<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'includes/auth.php';

$auth = new Auth();
$error = '';
$success = '';

// Redirect if already logged in
if ($auth->isLoggedIn()) {
    if ($auth->isAdmin()) {
        header("Location: admin_dashboard.php");
    } else {
        header("Location: resident_dashboard.php");
    }
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    
    // Validation
    if (empty($email) || empty($password) || empty($full_name)) {
        $error = 'Please fill in all required fields';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long';
    } else {
        // Use email as username for the auth system
        $username = $email;
        $register_result = $auth->register($username, $email, $password, $full_name, $phone, $address);
        if ($register_result) {
            $success = 'Registration successful! You can now <a href="login.php" style="color: #028a0f; font-weight: 600;">login with your credentials</a>.';
            $_POST = array();
        } else {
            $error = 'Registration failed. Email may already exist.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Barangay Kapasigan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            position: relative;
            overflow: hidden;
            padding: 0 20px;
        }

        .bg-decoration {
            position: fixed;
            width: 300px;
            height: 300px;
            border-radius: 50%;
            opacity: 0.1;
            z-index: 0;
        }

        .decoration-1 {
            top: -100px;
            left: -100px;
            background: white;
            animation: float 6s ease-in-out infinite;
        }

        .decoration-2 {
            bottom: -100px;
            right: -100px;
            background: white;
            animation: float 8s ease-in-out infinite 1s;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(30px); }
        }

        .container-wrapper {
            position: relative;
            z-index: 1;
            width: 100%;
        }

        .auth-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            max-width: 1100px;
            margin: 0 auto;
            align-items: start;
        }

        .brand-section {
            color: white;
            animation: slideInLeft 0.8s ease-out;
            position: sticky;
            top: 40px;
        }

        .brand-logo-large {
            width: 120px;
            height: 120px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 30px;
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            animation: bounce 2s ease-in-out infinite;
        }

        .brand-logo-large img {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: 15px;
        }

        .brand-section h1 {
            font-size: 48px;
            font-weight: 800;
            margin-bottom: 15px;
            text-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
        }

        .brand-section p {
            font-size: 18px;
            opacity: 0.9;
            margin-bottom: 30px;
            font-weight: 300;
        }

        .features {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .feature {
            display: flex;
            gap: 15px;
            align-items: flex-start;
        }

        .feature-icon {
            width: 50px;
            height: 50px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
            backdrop-filter: blur(10px);
        }

        .feature-text h3 {
            font-size: 16px;
            margin-bottom: 5px;
            font-weight: 600;
        }

        .feature-text p {
            font-size: 13px;
            opacity: 0.8;
            margin: 0;
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-20px); }
        }

        @keyframes slideInLeft {
            from { opacity: 0; transform: translateX(-50px); }
            to { opacity: 1; transform: translateX(0); }
        }

        @keyframes slideInRight {
            from { opacity: 0; transform: translateX(50px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .form-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 30px;
            animation: slideInRight 0.8s ease-out;
            max-height: 95vh;
            display: flex;
            flex-direction: column;
        }

        .form-header {
            margin-bottom: 20px;
            text-align: center;
        }

        .form-header h2 {
            font-size: 24px;
            color: #2d3748;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .form-header p {
            color: #718096;
            font-size: 13px;
        }

        .form-group {
            margin-bottom: 12px;
        }

        .form-label {
            display: block;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 10px;
            font-size: 14px;
        }

        .form-control, .input-group .form-control {
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 10px 14px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: #f9fafb;
        }

        .form-control:focus, .input-group .form-control:focus {
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
            outline: none;
        }

        .input-group-text {
            background: transparent;
            border: 2px solid #e2e8f0;
            border-right: none;
            color: #667eea;
            font-size: 14px;
            padding: 10px 14px;
            border-radius: 12px 0 0 12px;
            transition: 0.3s;
        }

        .input-group .form-control {
            border-radius: 0 12px 12px 0;
            border-left: none;
        }

        .input-group:focus-within .input-group-text {
            border-color: #667eea;
            background: #f9fafb;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .form-row.full {
            grid-template-columns: 1fr;
        }

        .form-group.password-group {
            position: relative;
        }

        .toggle-password {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #667eea;
            cursor: pointer;
            font-size: 18px;
            padding: 8px;
            z-index: 10;
        }

        .form-text {
            display: block;
            font-size: 12px;
            color: #718096;
            margin-top: 6px;
        }

        .btn-submit {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 15px;
            position: relative;
            overflow: hidden;
        }

        .btn-submit::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.2);
            transition: left 0.5s ease;
        }

        .btn-submit:hover::before {
            left: 100%;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        .alert {
            border: none;
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideDown 0.3s ease;
            font-size: 13px;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .alert-danger {
            background: #fef2f2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }

        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border-left: 4px solid #22c55e;
        }

        .alert i {
            font-size: 16px;
            flex-shrink: 0;
        }

        .terms-checkbox {
            display: flex;
            gap: 8px;
            align-items: flex-start;
            margin: 15px 0;
            font-size: 12px;
            color: #718096;
        }

        .terms-checkbox input[type="checkbox"] {
            margin-top: 3px;
            cursor: pointer;
            flex-shrink: 0;
        }

        .terms-checkbox a {
            color: #667eea;
            text-decoration: none;
        }

        .terms-checkbox a:hover {
            text-decoration: underline;
        }

        .auth-footer {
            text-align: center;
            margin-top: 15px;
            color: #718096;
            font-size: 13px;
        }

        .auth-footer a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
            transition: 0.3s;
        }

        .auth-footer a:hover {
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            .auth-container {
                grid-template-columns: 1fr;
                gap: 30px;
            }

            .brand-section {
                display: none;
            }

            .form-card {
                padding: 40px 25px;
            }

            .form-header h2 {
                font-size: 24px;
            }

            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="bg-decoration decoration-1"></div>
    <div class="bg-decoration decoration-2"></div>

    <div class="container-wrapper">
        <div class="auth-container">
            <!-- Brand Section -->
            <div class="brand-section">
                <div class="brand-logo-large">
                    <img src="kapasigan.png" alt="Barangay Kapasigan">
                </div>
                <h1>Barangay Kapasigan</h1>
                <p>Smart Resource & Facility Management System</p>
                
                <div class="features">
                    <div class="feature">
                        <div class="feature-icon"><i class="fas fa-calendar-check"></i></div>
                        <div class="feature-text">
                            <h3>Easy Scheduling</h3>
                            <p>Book facilities and resources with just a few clicks</p>
                        </div>
                    </div>
                    <div class="feature">
                        <div class="feature-icon"><i class="fas fa-chart-line"></i></div>
                        <div class="feature-text">
                            <h3>Real-time Analytics</h3>
                            <p>Track and optimize resource utilization</p>
                        </div>
                    </div>
                    <div class="feature">
                        <div class="feature-icon"><i class="fas fa-bell"></i></div>
                        <div class="feature-text">
                            <h3>Smart Notifications</h3>
                            <p>Stay updated with timely reminders</p>
                        </div>
                    </div>
                    <div class="feature">
                        <div class="feature-icon"><i class="fas fa-brain"></i></div>
                        <div class="feature-text">
                            <h3>AI Recommendations</h3>
                            <p>Get personalized suggestions for better bookings</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form Card -->
            <div class="form-card">
                <div class="form-header">
                    <h2>Create Account</h2>
                    <p>Join Barangay Kapasigan Community</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i><?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i><?php echo $success; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <!-- Email -->
                    <div class="form-group">
                        <label class="form-label" for="email">
                            <i class="fas fa-envelope me-2" style="color: #667eea;"></i>Email Address
                        </label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                            <input type="email" class="form-control" id="email" name="email" 
                                   placeholder="Enter your email address" 
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                        </div>
                        <small class="form-text">This will be used to login to your account</small>
                    </div>

                    <!-- Full Name -->
                    <div class="form-group">
                        <label class="form-label" for="full_name">
                            <i class="fas fa-id-card me-2" style="color: #667eea;"></i>Full Name
                        </label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-user"></i></span>
                            <input type="text" class="form-control" id="full_name" name="full_name" 
                                   placeholder="Enter your full name" 
                                   value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" required>
                        </div>
                    </div>

                    <!-- Phone & Address Row -->
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="phone">
                                <i class="fas fa-phone me-2" style="color: #667eea;"></i>Phone Number
                            </label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-phone"></i></span>
                                <input type="tel" class="form-control" id="phone" name="phone" 
                                       placeholder="Enter phone number" 
                                       value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="address">
                                <i class="fas fa-map-marker-alt me-2" style="color: #667eea;"></i>Address
                            </label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-map"></i></span>
                                <input type="text" class="form-control" id="address" name="address" 
                                       placeholder="Enter your address" 
                                       value="<?php echo htmlspecialchars($_POST['address'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Password & Confirm Row -->
                    <div class="form-row">
                        <div class="form-group password-group">
                            <label class="form-label" for="password">
                                <i class="fas fa-lock me-2" style="color: #667eea;"></i>Password
                            </label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                <input type="password" class="form-control" id="password" name="password" 
                                       placeholder="Create password" required>
                            </div>
                            <button type="button" class="toggle-password" onclick="togglePassword('password')">
                                <i class="fas fa-eye"></i>
                            </button>
                            <small class="form-text">Minimum 6 characters</small>
                        </div>

                        <div class="form-group password-group">
                            <label class="form-label" for="confirm_password">
                                <i class="fas fa-check-circle me-2" style="color: #667eea;"></i>Confirm Password
                            </label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                       placeholder="Confirm password" required>
                            </div>
                            <button type="button" class="toggle-password" onclick="togglePassword('confirm_password')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Terms & Conditions -->
                    <div class="terms-checkbox">
                        <input type="checkbox" id="terms" name="terms" required>
                        <label for="terms">
                            I agree to the <a href="#">Terms of Service</a> and <a href="#">Privacy Policy</a>
                        </label>
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="fas fa-user-plus me-2"></i>Create Account
                    </button>
                </form>

                <div class="auth-footer">
                    Already have an account? <a href="login.php">Sign in here</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePassword(id) {
            const input = document.getElementById(id);
            const button = event.target.closest('.toggle-password');
            
            if (input.type === 'password') {
                input.type = 'text';
                button.innerHTML = '<i class="fas fa-eye-slash"></i>';
            } else {
                input.type = 'password';
                button.innerHTML = '<i class="fas fa-eye"></i>';
            }
        }

        document.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                const form = document.activeElement.closest('form');
                if (form) form.submit();
            }
        });
    </script>
</body>
</html>