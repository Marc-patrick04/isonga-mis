<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Isonga RPSU Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="assets/images/logo.png">
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

        .form-header {
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

        .info-card {
            background: rgba(227, 242, 253, 0.3);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            border-left: 4px solid var(--primary-blue);
        }

        .info-card-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }

        .info-card-header i {
            font-size: 32px;
            color: var(--primary-blue);
        }

        .info-card-header h3 {
            font-size: 18px;
            color: var(--text-dark);
            font-weight: 600;
        }

        .info-card-body {
            margin-bottom: 20px;
        }

        .info-card-body p {
            color: var(--dark-gray);
            line-height: 1.6;
            font-size: 14px;
        }

        .user-type-selector {
            margin: 20px 0;
        }

        .user-type-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }

        .user-type-btn {
            flex: 1;
            padding: 12px;
            background: var(--white);
            border: 2px solid var(--medium-gray);
            border-radius: 8px;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-weight: 500;
            color: var(--text-dark);
        }

        .user-type-btn:hover {
            border-color: var(--primary-blue);
            background: var(--light-blue);
        }

        .user-type-btn.active {
            background: var(--light-blue);
            border-color: var(--primary-blue);
            color: var(--primary-blue);
        }

        .contact-info {
            background: var(--light-gray);
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
        }

        .contact-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--medium-gray);
        }

        .contact-item:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }

        .contact-item i {
            color: var(--primary-blue);
            font-size: 16px;
            margin-top: 2px;
        }

        .contact-item-content h4 {
            font-size: 14px;
            color: var(--text-dark);
            margin-bottom: 5px;
            font-weight: 600;
        }

        .contact-item-content p {
            font-size: 13px;
            color: var(--dark-gray);
            line-height: 1.5;
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

        .btn-secondary {
            background: var(--light-gray);
            color: var(--text-dark);
            border: 1px solid var(--medium-gray);
        }

        .btn-secondary:hover {
            background: var(--medium-gray);
            transform: translateY(-2px);
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 25px;
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

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .modal-overlay.show {
            display: flex;
        }

        .modal {
            background: var(--white);
            border-radius: 15px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-header h3 {
            font-size: 18px;
            color: var(--text-dark);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: var(--dark-gray);
            transition: var(--transition);
        }

        .modal-close:hover {
            color: var(--text-dark);
        }

        .modal-body {
            margin-bottom: 20px;
        }

        .modal-body p {
            color: var(--dark-gray);
            line-height: 1.6;
            margin-bottom: 15px;
        }

        .modal-footer {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .modal-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
        }

        .modal-btn-primary {
            background: var(--primary-blue);
            color: var(--white);
        }

        .modal-btn-primary:hover {
            background: var(--accent-blue);
        }

        .modal-btn-secondary {
            background: var(--light-gray);
            color: var(--text-dark);
        }

        .modal-btn-secondary:hover {
            background: var(--medium-gray);
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
            
            .user-type-buttons {
                flex-direction: column;
            }
            
            .action-buttons {
                flex-direction: column;
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
            
            .btn {
                padding: 12px;
                font-size: 15px;
            }
            
            .info-card {
                padding: 20px;
            }
            
            .modal {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <!-- Reset Request Modal -->
    <div id="resetModal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-key"></i> Password Reset Request</h3>
                <button class="modal-close" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <p>Your password reset request has been submitted successfully.</p>
                <p>Our support team will contact you within <strong>24 hours</strong> during working hours (Mon-Fri, 8:00 AM - 5:00 PM).</p>
                <p>Please make sure to have your student ID or registration number ready for verification.</p>
            </div>
            <div class="modal-footer">
                <button class="modal-btn modal-btn-secondary" onclick="closeModal()">Close</button>
                <button class="modal-btn modal-btn-primary" onclick="closeModalAndRedirect()">
                    <i class="fas fa-home"></i> Go to Homepage
                </button>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- Graphic Side -->
        <div class="graphic-side">
            <div class="logo">
                <img src="../assets/images/logo.png" alt="Isonga Platform Logo" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🎓</text></svg>'">
                <h1>Isonga</h1>
            </div>
            <div class="graphic-content">
                <h2>Password Recovery Assistance</h2>
                <p>We're here to help you regain access to your account securely and efficiently.</p>
                <ul class="features">
                    <!-- <li><i class="fas fa-shield-alt"></i> Secure verification process</li>
                    <li><i class="fas fa-user-check"></i> Identity verification required</li>
                    <li><i class="fas fa-clock"></i> Quick response time</li>
                    <li><i class="fas fa-headset"></i> Dedicated support team</li>
                    <li><i class="fas fa-lock"></i> Account security maintained</li>
                    <li><i class="fas fa-file-alt"></i> Documentation guidance</li> -->
                </ul>
            </div>
        </div>

        <!-- Form Side -->
        <div class="form-side">
            <div class="form-header">
                <h2 class="form-title">Reset Your Password</h2>
                <p class="form-subtitle">Select your account</p>
            </div>

            <div class="info-card">
                <div class="info-card-header">
                    <i class="fas fa-lock"></i>
                    <h3>Password Reset Required</h3>
                </div>
                
                <div class="info-card-body">
                    <p>For security reasons, password resets require manual verification.</p>
                </div>

                <div class="user-type-selector">
                    <h4 style="margin-bottom: 10px; color: var(--text-dark); font-size: 15px;">Select Account Type:</h4>
                    <div class="user-type-buttons">
                        <button class="user-type-btn active" onclick="selectUserType('student')">
                            <i class="fas fa-user-graduate"></i>
                            Student Account
                        </button>
                        <button class="user-type-btn" onclick="selectUserType('committee')">
                            <i class="fas fa-users"></i>
                            Committee Account
                        </button>
                    </div>
                </div>

                <!-- Student Contact Info -->
                <div id="studentContact" class="contact-info">
                    <div class="contact-item">
                        <i class="fas fa-map-marker-alt"></i>
                        <div class="contact-item-content">
                            <h4>Location</h4>
                            <p>Guild Council Office, Main Administration Building</p>
                        </div>
                    </div>
                    <div class="contact-item">
                        <i class="fas fa-clock"></i>
                        <div class="contact-item-content">
                            <h4>Office Hours</h4>
                            <p>Monday - Friday: 8:00 AM - 5:00 PM</p>
                        </div>
                    </div>
                    <div class="contact-item">
                        <i class="fas fa-file-alt"></i>
                        <div class="contact-item-content">
                            <h4>Required Documents</h4>
                            <p>Student ID Card, Registration Number</p>
                        </div>
                    </div>
                </div>

                <!-- Committee Contact Info -->
                <div id="committeeContact" class="contact-info" style="display: none;">
                    <div class="contact-item">
                        <i class="fas fa-map-marker-alt"></i>
                        <div class="contact-item-content">
                            <h4>Location</h4>
                            <p>IT Department, College Administration Building</p>
                        </div>
                    </div>
                    <div class="contact-item">
                        <i class="fas fa-clock"></i>
                        <div class="contact-item-content">
                            <h4>Office Hours</h4>
                            <p>Monday - Friday: 8:00 AM - 5:00 PM</p>
                        </div>
                    </div>
                    <div class="contact-item">
                        <i class="fas fa-file-alt"></i>
                        <div class="contact-item-content">
                            <h4>Required Information</h4>
                            <p>Committee ID, Email Address, Role Verification</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="action-buttons">
                <button class="btn btn-primary" onclick="submitResetRequest()">
                    <i class="fas fa-paper-plane"></i> Submit Reset Request
                </button>
                <a href="login.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Login
                </a>
            </div>

            <div class="help-links">
                <p>For urgent assistance, contact: <a href="tel:+250788123456">+250 788 123 456</a></p>
                <p style="margin-top: 10px;">Email: <a href="mailto:support@isonga.rp.ac.rw">support@isonga.rp.ac.rw</a></p>
            </div>
        </div>
    </div>

    <script>
        // Select user type
        function selectUserType(type) {
            // Update button states
            document.querySelectorAll('.user-type-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            const studentBtn = document.querySelector('.user-type-btn:nth-child(1)');
            const committeeBtn = document.querySelector('.user-type-btn:nth-child(2)');
            
            if (type === 'student') {
                studentBtn.classList.add('active');
                document.getElementById('studentContact').style.display = 'block';
                document.getElementById('committeeContact').style.display = 'none';
            } else {
                committeeBtn.classList.add('active');
                document.getElementById('studentContact').style.display = 'none';
                document.getElementById('committeeContact').style.display = 'block';
            }
        }

        // Submit reset request
        function submitResetRequest() {
            const isStudent = document.querySelector('.user-type-btn:nth-child(1)').classList.contains('active');
            const userType = isStudent ? 'Student' : 'Committee Member';
            
            // Show confirmation modal
            const modal = document.getElementById('resetModal');
            modal.classList.add('show');
            
            // Update modal message based on user type
            const modalBody = modal.querySelector('.modal-body');
            modalBody.innerHTML = `
                <p>Your password reset request as a <strong>${userType}</strong> has been submitted successfully.</p>
                <p>Our support team will contact you within <strong>24 hours</strong> during working hours (Mon-Fri, 8:00 AM - 5:00 PM).</p>
                <p>Please make sure to have your ${isStudent ? 'student ID or registration number' : 'committee ID and email address'} ready for verification.</p>
                <div style="background: #e3f2fd; padding: 10px; border-radius: 6px; margin-top: 15px;">
                    <p style="margin: 0; font-size: 13px;"><i class="fas fa-info-circle" style="color: #0056b3;"></i> Reference Number: <strong>RST-${Date.now().toString().slice(-6)}</strong></p>
                </div>
            `;
        }

        // Close modal
        function closeModal() {
            document.getElementById('resetModal').classList.remove('show');
        }

        // Close modal and redirect
        function closeModalAndRedirect() {
            closeModal();
            setTimeout(() => {
                window.location.href = '../index.php';
            }, 300);
        }

        // Close modal on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });

        // Close modal when clicking outside
        document.getElementById('resetModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            // Set default user type
            selectUserType('student');
        });
    </script>
</body>
</html>