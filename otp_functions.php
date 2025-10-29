<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/database.php';
require_once 'otp_functions.php';


require_once 'PHPMailer/src/Exception.php';
require_once 'PHPMailer/src/PHPMailer.php';
require_once 'PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * generateOTP
 * returns a numeric OTP string with leading zeros allowed (default 6 digits)
 */
function generateOTP($length = 6) {
    return str_pad((string)random_int(0, (int)pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
}

/**
 * sendOTPEmail
 * Sends OTP to user's email using PHPMailer.
 * Replace $smtpUser and $smtpPass with real credentials (app password for Gmail).
 */
function sendOTPEmail($toEmail, $full_name, $otp) {
    $mail = new PHPMailer(true);
    try {
        // <-- REPLACE THESE with your SMTP credentials
        $smtpHost = 'smtp.gmail.com';
        $smtpUser = 'depaduamarkian49@gmail.com';  
        $smtpPass = 'fecl ikra jnib mtbm';       
        $smtpPort = 587;

        $mail->isSMTP();
        $mail->Host       = $smtpHost;
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpUser;
        $mail->Password   = $smtpPass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $smtpPort;

        $mail->setFrom($smtpUser, 'Barangay Kapasigan');
        $mail->addAddress($toEmail, $full_name);

        $mail->isHTML(true);
        $mail->Subject = 'Barangay Kapasigan - OTP Verification';
        $mail->Body    = "
            <div style='font-family: Arial, sans-serif;'>
                <h2>Email Verification</h2>
                <p>Hello <strong>" . htmlspecialchars($full_name) . "</strong>,</p>
                <p>Your OTP code is:</p>
                <div style='font-size: 22px; font-weight: 700; margin: 10px 0;'>" . htmlspecialchars($otp) . "</div>
                <p>This code will expire in 3 minutes.</p>
                <p>If you didn't request this, please ignore this email.</p>
                <hr>
                <small>© " . date('Y') . " Barangay Kapasigan</small>
            </div>
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer Error: " . ($mail->ErrorInfo ?? $e->getMessage()));
        return false;
    }    
}

/**
 * storeOTP
 * - marks previous unused OTPs as used (safety)
 * - stores OTP plaintext (you requested no hashing)
 * - returns inserted otp_codes id or false
 */
function storeOTP($userId, $otp, $minutes = 3) {
    $db = new Database();
    $pdo = $db->getConnection();

    // mark previous unused OTPS as used
    $upd = $pdo->prepare("UPDATE otp_codes SET used = 1 WHERE user_id = :uid AND used = 0");
    $upd->execute([':uid' => $userId]);

    $expiresAt = (new DateTime())->modify("+{$minutes} minutes")->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare("INSERT INTO otp_codes (user_id, otp, expires_at) VALUES (:user_id, :otp, :expires_at)");
    $ok = $stmt->execute([
        ':user_id' => $userId,
        ':otp' => $otp,               // <--- store plain OTP (not hashed)
        ':expires_at' => $expiresAt
    ]);

    if ($ok) {
        return $pdo->lastInsertId();
    }
    return false;
}

/**
 * resendOTP
 * - checks last sent (created_at) and prevents re-sending within $cooldownSeconds
 * - returns ['ok'=>true,'message'=>...] or ['ok'=>false,'message'=>...]
 */
function resendOTP($userId, $toEmail, $full_name, $cooldownSeconds = 60, $otpLength = 6) {
    $db = new Database();
    $pdo = $db->getConnection();

    // fetch latest OTP record
    $stmt = $pdo->prepare("SELECT created_at FROM otp_codes WHERE user_id = :uid ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([':uid' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $lastSent = new DateTime($row['created_at']);
        $now = new DateTime();
        $diff = $now->getTimestamp() - $lastSent->getTimestamp();
        if ($diff < $cooldownSeconds) {
            return ['ok' => false, 'message' => "Please wait {$cooldownSeconds} seconds before requesting another code."];
        }
    }

    $otp = generateOTP($otpLength);
    $storeId = storeOTP($userId, $otp, 3);
    if (!$storeId) return ['ok' => false, 'message' => 'Failed to create OTP record.'];

    $sent = sendOTPEmail($toEmail, $full_name, $otp);
    if (!$sent) return ['ok' => false, 'message' => 'Failed to send OTP email.'];

    return ['ok' => true, 'message' => 'A fresh OTP has been sent to your email.'];
}

/**
 * verifyStoredOTP
 * - sanitizes OTP input to digits only (keeps leading zeros)
 * - compares directly (string equality) to stored OTP (plaintext)
 * - marks used when valid
 * - returns array('ok' => bool, 'reason' => 'expired'|'used'|'invalid'|'no_otp')
 */
function verifyStoredOTP($userId, $otp) {
    // sanitize OTP to digits only (keeps leading zeros)
    $otpClean = preg_replace('/\D/', '', (string)$otp);

    $db = new Database();
    $pdo = $db->getConnection();

    // fetch latest OTP record
    $stmt = $pdo->prepare("SELECT id, otp, expires_at, used FROM otp_codes WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([':user_id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) return ['ok' => false, 'reason' => 'no_otp'];

    if ($row['used']) return ['ok' => false, 'reason' => 'used'];

    $expiresAt = new DateTime($row['expires_at']);
    $now = new DateTime();
    if ($now > $expiresAt) return ['ok' => false, 'reason' => 'expired'];

    // direct string comparison
    if ($otpClean === (string)$row['otp']) {
        // mark otp as used
        $upd = $pdo->prepare("UPDATE otp_codes SET used = 1 WHERE id = :id");
        $upd->execute([':id' => $row['id']]);
        return ['ok' => true];
    }

    return ['ok' => false, 'reason' => 'invalid'];
}
