<?php
    // Enable error reporting for debugging
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
    
    session_start();
    require_once 'config/database.php';
    require_once 'otp_functions.php';

    $error = '';
    $debug_mode = false; // Set to true to see debug messages

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = 'Please fill in all fields';
        } else {
            try {
                $db = new Database();
                $pdo = $db->getConnection();

                if ($debug_mode) {
                    echo "Database connected successfully<br>";
                }

                $stmt = $pdo->prepare("SELECT * FROM users WHERE email=:email LIMIT 1");
                $stmt->execute([':email' => $email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($debug_mode) {
                    echo "User found: " . ($user ? 'Yes' : 'No') . "<br>";
                    if ($user) {
                        echo "User ID: " . $user['id'] . "<br>";
                        echo "User Status: " . $user['status'] . "<br>";
                        echo "Stored Password: " . $user['password'] . "<br>";
                        echo "Password is hashed: " . (strlen($user['password']) == 60 ? 'Yes' : 'No (Plain text detected!)') . "<br>";
                    }
                }

                if (!$user) {
                    $error = 'Invalid email or password';
                } else {
                    // Check if password is hashed (bcrypt hashes are 60 characters)
                    if (strlen($user['password']) == 60) {
                        // Password is hashed, use password_verify
                        $password_match = password_verify($password, $user['password']);
                    } else {
                        // Password is plain text (temporary fix - you should hash it!)
                        $password_match = ($password === $user['password']);
                        if ($debug_mode) {
                            echo "WARNING: Password stored as plain text!<br>";
                        }
                    }

                    if ($debug_mode) {
                        echo "Password match: " . ($password_match ? 'Yes' : 'No') . "<br>";
                    }

                    if (!$password_match) {
                        $error = 'Invalid email or password';
                    } elseif ($user['status'] !== 'active') {
                        $error = 'Account inactive. Contact admin.';
                    } else {
                        // Generate OTP
                        $otp = generateOTP(6);
                        
                        if ($debug_mode) {
                            echo "Generated OTP: $otp<br>";
                        }
                        
                        storeOTP($user['id'], $otp, 3); // expires in 3 minutes

                        $sent = sendOTPEmail($user['email'], $user['full_name'], $otp);
                        
                        if ($debug_mode) {
                            echo "Email sent: " . ($sent ? 'Yes' : 'No') . "<br>";
                        }
                        
                        if (!$sent) {
                            $error = 'Failed to send OTP. Contact admin.';
                            if ($debug_mode) {
                                echo "Email sending failed. Check SMTP configuration.<br>";
                            }
                        } else {
                            $_SESSION['otp_user_id'] = $user['id'];
                            $_SESSION['otp_redirect_role'] = $user['role'];
                            $_SESSION['otp_sent_at'] = time();
                            $_SESSION['otp_attempts'] = 0;

                            if ($debug_mode) {
                                echo "Session set successfully. Redirecting...<br>";
                                echo "<a href='verify_otp.php'>Click here if not redirected</a>";
                                exit();
                            }

                            header('Location: verify_otp.php');
                            exit();
                        }
                    }
                }
            } catch (Exception $e) {
                $error = 'System error: ' . $e->getMessage();
                if ($debug_mode) {
                    echo "Exception caught: " . $e->getMessage() . "<br>";
                    echo "Stack trace: " . $e->getTraceAsString();
                }
            }
        }
    }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Barangay Kapasigan</title>
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
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            position: relative;
            overflow: hidden;
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
            padding: 20px;
            align-items: center;
        }

        .brand-section {
            color: white;
            animation: slideInLeft 0.8s ease-out;
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
            padding: 50px 40px;
            animation: slideInRight 0.8s ease-out;
        }

        .form-header {
            margin-bottom: 40px;
            text-align: center;
        }

        .form-header h2 {
            font-size: 32px;
            color: #2d3748;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .form-header p {
            color: #718096;
            font-size: 15px;
        }

        .form-group {
            margin-bottom: 20px;
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
            padding: 12px 16px;
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
            font-size: 16px;
            padding: 12px 16px;
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

        .btn-submit {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 25px;
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
            padding: 15px 20px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideDown 0.3s ease;
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

        .alert i {
            font-size: 18px;
            flex-shrink: 0;
        }

        .auth-footer {
            text-align: center;
            margin-top: 25px;
            color: #718096;
            font-size: 14px;
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

        .checkbox-group {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .checkbox-group a {
            color: #667eea;
            text-decoration: none;
        }

        .checkbox-group a:hover {
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
                    <h2>Welcome Back</h2>
                    <p>Sign in to your account to continue</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i><?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="form-group">
                        <label class="form-label" for="email">
                            <i class="fas fa-envelope me-2" style="color: #667eea;"></i>Email Address
                        </label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-at"></i></span>
                            <input type="email" name="email" id="email" class="form-control" placeholder="Enter your email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                        </div>
                    </div>

                    <div class="form-group password-group">
                        <label class="form-label" for="password">
                            <i class="fas fa-lock me-2" style="color: #667eea;"></i>Password
                        </label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-lock"></i></span>
                            <input type="password" class="form-control" id="password" name="password" placeholder="Enter your password" required>
                        </div>
                        <button type="button" class="toggle-password" onclick="togglePassword('password')">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>

                    <div class="checkbox-group">
                        <label style="margin: 0;">
                            <input type="checkbox" style="margin-right: 5px;"> Remember me
                        </label>
                        <a href="forgot_password.php">Forgot password?</a>
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="fas fa-sign-in-alt me-2"></i>Sign In
                    </button>
                </form>

                <div class="auth-footer">
                    Don't have an account? <a href="register.php">Sign up here</a>
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