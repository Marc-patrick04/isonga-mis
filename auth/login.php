<?php
session_start();

// Check for logout success message
if (isset($_SESSION['logout_success'])) {
    $logout_message = "You have been successfully logged out.";
    unset($_SESSION['logout_success']);
}

require_once '../config/database.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: ' . getDashboardUrl($_SESSION['role']));
    exit();
}

// Process login form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    $errors = [];
    
    if (empty($email)) {
        $errors[] = "Email is required";
    }
    
    if (empty($password)) {
        $errors[] = "Password is required";
    }
    
    if (empty($errors)) {
        try {
            // Committee login - exclude student role
            $stmt = $pdo->prepare("
                SELECT u.*, d.name as department_name, p.name as program_name 
                FROM users u 
                LEFT JOIN departments d ON u.department_id = d.id 
                LEFT JOIN programs p ON u.program_id = p.id 
                WHERE u.email = ? AND u.status = 'active' AND u.role != 'student'
            ");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Use password_verify for hashed passwords
            if ($user && password_verify($password, $user['password'])) {
                // Login successful
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['reg_number'] = $user['reg_number'];
                $_SESSION['department'] = $user['department_name'];
                $_SESSION['program'] = $user['program_name'];
                $_SESSION['academic_year'] = $user['academic_year'];
                
                // Record login activity
                recordLoginActivity($pdo, $user['id'], true);
                
                // Update last login
                $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW(), login_count = login_count + 1 WHERE id = ?");
                $updateStmt->execute([$user['id']]);
                
                // Redirect to appropriate dashboard
                header('Location: ' . getDashboardUrl($user['role']));
                exit();
            } else {
                recordLoginActivity($pdo, null, false, $email);
                $errors[] = "Invalid email or password";
            }
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            $errors[] = "Login failed. Please try again.";
        }
    }
}

function getDashboardUrl($role) {
    $rolePaths = [
        'admin' => '../admin/dashboard.php',
        'guild_president' => '../guild_president/dashboard.php',
        'vice_guild_academic' => '../vice_guild_academic/dashboard.php',
        'vice_guild_finance' => '../vice_guild_finance/dashboard.php',
        'general_secretary' => '../general_secretary/dashboard.php',
        'minister_sports' => '../minister_sports/dashboard.php',
        'minister_environment' => '../minister_environment/dashboard.php',
        'minister_public_relations' => '../minister_public_relations/dashboard.php',
        'minister_health' => '../minister_health/dashboard.php',
        'minister_culture' => '../minister_culture/dashboard.php',
        'minister_gender' => '../minister_gender/dashboard.php',
        'president_representative_board' => '../president_representative_board/dashboard.php',
        'vice_president_representative_board' => '../vice_president_representative_board/dashboard.php',
        'secretary_representative_board' => '../secretary_representative_board/dashboard.php',
        'president_arbitration' => '../president_arbitration/dashboard.php',
        'vice_president_arbitration' => '../vice_president_arbitration/dashboard.php',
        'advisor_arbitration' => '../advisor_arbitration/dashboard.php',
        'secretary_arbitration' => '../secretary_arbitration/dashboard.php'
    ];
    
    return $rolePaths[$role] ?? '../index.php';
}

function recordLoginActivity($pdo, $userId = null, $success = true, $email = null) {
    try {
        $stmt = $pdo->prepare("INSERT INTO login_activities (user_id, ip_address, user_agent, login_time, success, failure_reason) VALUES (?, ?, ?, NOW(), ?, ?)");
        $failure_reason = $success ? null : 'Invalid credentials for email: ' . $email;
        $stmt->execute([$userId, $_SERVER['REMOTE_ADDR'] ?? 'unknown', $_SERVER['HTTP_USER_AGENT'] ?? 'unknown', $success ? 1 : 0, $failure_reason]);
    } catch (PDOException $e) {
        error_log("Failed to record login activity: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Committee Login - Isonga RPSU Management System</title>
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
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--accent-blue) 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            position: relative;
        }

        .container {
            display: flex;
            width: 100%;
            max-width: 1000px;
            min-height: 600px;
            background: var(--white);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            position: relative;
            z-index: 1;
        }

        .graphic-side {
            flex: 1;
            background: var(--gradient-primary);
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

        .form-side {
            flex: 1;
            padding: 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .login-header {
            margin-bottom: 30px;
        }

        .form-title {
            font-size: 24px;
            margin-bottom: 10px;
            color: var(--text-dark);
        }

        .form-subtitle {
            color: var(--dark-gray);
            margin-bottom: 25px;
            font-size: 14px;
        }

        .input-group {
            margin-bottom: 20px;
            position: relative;
        }

        .input-group i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--dark-gray);
        }

        .input-group input {
            width: 100%;
            padding: 15px 15px 15px 45px;
            border: 2px solid var(--medium-gray);
            border-radius: 10px;
            font-size: 16px;
            transition: var(--transition);
        }

        .input-group input:focus {
            border-color: var(--primary-blue);
            outline: none;
            box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.2);
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--dark-gray);
            cursor: pointer;
            transition: var(--transition);
        }

        .password-toggle:hover {
            color: var(--primary-blue);
        }

        .btn {
            width: 100%;
            padding: 15px;
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
            background: var(--gradient-primary);
            color: var(--white);
        }

        .btn-primary:hover {
            background: var(--gradient-secondary);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 86, 179, 0.3);
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

        .alert-danger ul {
            margin: 10px 0 0 20px;
        }

        .help-links {
            text-align: center;
            margin-top: 20px;
            color: var(--dark-gray);
            font-size: 14px;
        }

        .help-links a {
            color: var(--primary-blue);
            text-decoration: none;
            font-weight: 600;
        }

        .help-links a:hover {
            text-decoration: underline;
        }

        /* Popup Notification Styles */
        .popup-notification {
            position: fixed;
            top: 30px;
            right: 30px;
            z-index: 1000;
            min-width: 300px;
            max-width: 400px;
            background: var(--white);
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
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

        .popup-header {
            padding: 18px 20px;
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

        @media (max-width: 900px) {
            .container {
                flex-direction: column;
                max-width: 500px;
            }
            
            .graphic-side {
                padding: 30px;
                min-height: 300px;
            }
            
            .form-side {
                padding: 30px;
            }
            
            .graphic-content h2 {
                font-size: 28px;
            }
            
            .logo h1 {
                font-size: 24px;
            }
        }

        @media (max-width: 600px) {
            .container {
                border-radius: 15px;
            }
            
            .graphic-side, .form-side {
                padding: 25px;
            }
            
            .graphic-content h2 {
                font-size: 24px;
            }
            
            .form-title {
                font-size: 20px;
            }
            
            .input-group input {
                padding: 12px 12px 12px 40px;
                font-size: 15px;
            }
            
            .btn {
                padding: 12px;
                font-size: 15px;
            }
        }
    </style>
</head>
<body>
    <!-- Popup Notification for Logout -->
    <?php if (isset($logout_message)): ?>
    <div id="logoutPopup" class="popup-notification show" style="background: rgba(40, 167, 69, 0.1); border-left: 4px solid var(--success);">
        <div class="popup-header">
            <div class="popup-title" style="color: var(--success);">
                <i class="fas fa-check-circle"></i>
                <span>Success!</span>
            </div>
            <button class="popup-close" onclick="hidePopup()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="popup-content">
            <div class="popup-message"><?php echo htmlspecialchars($logout_message); ?></div>
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
                <h2>RPSU Committee Portal</h2>
                <!-- <p>Access your dedicated dashboard to manage student concerns, track issues, and collaborate with your team members efficiently.</p> -->
                  <p>Access your dedicated dashboard to manage student concerns.</p>
                <ul class="features">
                    <!-- <li><i class="fas fa-tachometer-alt"></i> Personalized role-based dashboard</li> -->
                    <!-- <li><i class="fas fa-ticket-alt"></i> Manage and resolve student tickets</li> -->
                    <!-- <li><i class="fas fa-chart-line"></i> Track performance and generate reports</li> -->
                    <!-- <li><i class="fas fa-users"></i> Collaborate with committee members</li> -->
                    <!-- <li><i class="fas fa-calendar-alt"></i> Schedule and manage campus events</li> -->
                    <!-- <li><i class="fas fa-bullhorn"></i> Publish announcements to students</li> -->
                </ul>
            </div>
        </div>

        <!-- Form Side -->
        <div class="form-side">
            <div class="login-header">
                <h2 class="form-title">Committee Member Login</h2>
                <!-- <p class="form-subtitle">Enter your credentials to access the management system</p> -->
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <strong><i class="fas fa-exclamation-triangle"></i> Login Failed</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="login" id="loginForm">
                <div class="input-group">
                    <i class="fas fa-envelope"></i>
                    <input type="email" id="email" name="email" 
                           placeholder="your.email@rpsu.rp.ac.rw" 
                           value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>" 
                           required>
                </div>

                <div class="input-group">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="password" name="password" 
                           placeholder="Enter your password" 
                           required>
                    <button type="button" class="password-toggle" onclick="togglePassword()">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>

                <button type="submit" class="btn btn-primary" id="loginBtn">
                    <i class="fas fa-sign-in-alt"></i> Sign In
                </button>
            </form>

            <div class="help-links">
                <p>Having trouble signing in? <a href="forgot-password">Reset your password</a></p>
                <p style="margin-top: 10px;">Not a committee member? <a href="student_login">Student Login</a></p>
                <p style="margin-top: 10px;">Return to <a href="../index">Home Page</a></p>
            </div>
        </div>
    </div>

    <script>
        // Toggle password visibility
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const icon = document.querySelector('.password-toggle i');
            
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
        function hidePopup() {
            const popup = document.getElementById('logoutPopup');
            if (popup) {
                popup.remove();
            }
        }

        // Auto-hide popup after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const popup = document.getElementById('logoutPopup');
            if (popup) {
                setTimeout(() => {
                    hidePopup();
                }, 5000);
            }
        });

        // Add loading state on form submit
        const loginForm = document.getElementById('loginForm');
        const loginBtn = document.getElementById('loginBtn');
        
        if (loginForm) {
            loginForm.addEventListener('submit', function() {
                loginBtn.disabled = true;
                loginBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Signing in...';
            });
        }

        // Add focus effects to inputs
        document.querySelectorAll('input').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.classList.add('focused');
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.classList.remove('focused');
            });
        });

        // Clear error messages when typing
        const emailInput = document.getElementById('email');
        const passwordInput = document.getElementById('password');
        
        function clearErrors() {
            const errorAlert = document.querySelector('.alert-danger');
            if (errorAlert) {
                errorAlert.remove();
            }
        }
        
        if (emailInput) {
            emailInput.addEventListener('input', clearErrors);
        }
        if (passwordInput) {
            passwordInput.addEventListener('input', clearErrors);
        }
    </script>
</body>
</html>