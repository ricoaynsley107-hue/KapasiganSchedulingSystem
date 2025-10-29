<?php
session_start();
require_once 'config/database.php';
require_once 'otp_functions.php';

$error = '';
$info = '';

if (empty($_SESSION['otp_user_id'])) {
    header('Location: login.php');
    exit();
}

$userId = (int)$_SESSION['otp_user_id'];
$maxAttempts = 5;
$lockMinutes = 15;
$attemptKey = "otp_attempts_user_{$userId}";
$lockKey = "otp_lock_until_user_{$userId}";

if (!isset($_SESSION[$attemptKey])) $_SESSION[$attemptKey] = 0;

if (!empty($_SESSION[$lockKey]) && time() < $_SESSION[$lockKey]) {
    $wait = $_SESSION[$lockKey] - time();
    $error = "Too many failed attempts. Try again in " . ceil($wait/60) . " minute(s).";
} else {
    if (!empty($_SESSION[$lockKey]) && time() >= $_SESSION[$lockKey]) {
        unset($_SESSION[$lockKey]);
        $_SESSION[$attemptKey] = 0;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $otpInput = preg_replace('/\D/', '', trim($_POST['otp'] ?? ''));

        if (empty($otpInput)) {
            $error = 'Please enter the OTP sent to your email.';
        } else {
            $res = verifyStoredOTP($userId, $otpInput);
            if ($res['ok']) {
                $db = new Database();
                $pdo = $db->getConnection();

                $stmt = $pdo->prepare("SELECT id, username, email, full_name, role FROM users WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => $userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$user) {
                    $error = 'User not found.';
                } else {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['full_name'] = $user['full_name'];

                    unset($_SESSION['otp_user_id'], $_SESSION['otp_sent_at'], $_SESSION['otp_redirect_role']);
                    unset($_SESSION[$attemptKey], $_SESSION[$lockKey]);

                    $role = $_SESSION['otp_redirect_role'] ?? $user['role'];
                    if ($role === 'admin') {
                        header('Location: admin_dashboard.php');
                    } else {
                        header('Location: resident_dashboard.php');
                    }
                    exit();
                }
            } else {
                $_SESSION[$attemptKey]++;
                $remaining = max(0, $maxAttempts - $_SESSION[$attemptKey]);

                $reason = $res['reason'] ?? 'invalid';
                if ($reason === 'expired') {
                    $error = 'OTP has expired. Please <a href="resend_otp.php">request a new code</a>.';
                } elseif ($reason === 'used') {
                    $error = 'This OTP was already used. Please request a new code.';
                } elseif ($reason === 'no_otp') {
                    $error = 'No OTP request found. Please log in again.';
                } else {
                    $error = "Invalid OTP. You have {$remaining} attempt(s) left.";
                }

                if ($_SESSION[$attemptKey] >= $maxAttempts) {
                    $_SESSION[$lockKey] = time() + ($lockMinutes * 60);
                    $error = "Too many failed attempts. You are locked out for {$lockMinutes} minutes.";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Verify OTP - Barangay Kapasigan</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>
body {
    font-family: 'Segoe UI', sans-serif;
    min-height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}
.otp-card {
    background: white;
    padding: 40px;
    border-radius: 20px;
    max-width: 400px;
    width: 100%;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    text-align: center;
}
.otp-card h2 {
    margin-bottom: 20px;
    font-weight: 700;
    color: #2d3748;
}
.otp-input {
    border-radius: 12px;
    padding: 12px;
    width: 100%;
    border: 2px solid #e2e8f0;
    font-size: 18px;
    margin-bottom: 20px;
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
    margin-bottom: 10px;
}
.btn-submit:hover { opacity: 0.9; }
.alert { border-radius: 12px; padding: 10px 15px; margin-bottom: 20px; }
.countdown { font-size: 14px; color: #667eea; margin-bottom: 10px; }
.resend-form { margin-top: 10px; }
</style>
</head>
<body>
<div class="otp-card">
    <h2>Enter OTP</h2>
    <p>We've sent an OTP to your registered email. It will expire in 3 minutes.</p>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>

    <?php if ($info): ?>
        <div class="alert alert-info"><?php echo $info; ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="text" name="otp" class="otp-input" maxlength="6" placeholder="Enter OTP" inputmode="numeric" pattern="\d*" required autofocus>
        <button type="submit" class="btn-submit"><i class="fas fa-key me-2"></i>Verify OTP</button>
    </form>

    <div class="resend-form">
        <a href="login.php" class="btn btn-link">Login Again</a>
    </div>
</div>
</body>
</html>
