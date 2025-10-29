<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';

class Auth {
    private $conn;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    public function login($username, $password) {
        try {
            $query = "SELECT id, username, email, password, full_name, role, status FROM users WHERE (username = :username OR email = :username) AND status = 'active'";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':username', $username);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Check password - support both demo and hashed passwords
                $password_valid = false;
                if ($password === 'password') {
                    $password_valid = true; // Demo password
                } elseif (password_verify($password, $user['password'])) {
                    $password_valid = true; // Hashed password
                }
                
                if ($password_valid) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['email'] = $user['email'];
                    return true;
                }
            }
            return false;
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            return false;
        }
    }
    
    public function register($username, $email, $password, $full_name, $phone = '', $address = '') {
        try {
            if (!$this->conn) {
                error_log("Database connection is null");
                return false;
            }
            
            // Check if username or email already exists
            $query = "SELECT COUNT(*) FROM users WHERE username = :username OR email = :email";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':username', $username);
            $stmt->bindParam(':email', $email);
            $stmt->execute();
            
            if ($stmt->fetchColumn() > 0) {
                error_log("User already exists: $username or $email");
                return false; // User already exists
            }
            
            // Insert new user
            $query = "INSERT INTO users (username, email, password, full_name, phone, address, role, status) VALUES (:username, :email, :password, :full_name, :phone, :address, 'resident', 'active')";
            $stmt = $this->conn->prepare($query);
            
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            $stmt->bindParam(':username', $username);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':password', $hashed_password);
            $stmt->bindParam(':full_name', $full_name);
            $stmt->bindParam(':phone', $phone);
            $stmt->bindParam(':address', $address);
            
            $result = $stmt->execute();
            if ($result) {
                error_log("User registered successfully: $username");
            } else {
                error_log("Failed to execute insert statement");
            }
            return $result;
        } catch (Exception $e) {
            error_log("Registration error: " . $e->getMessage());
            return false;
        }
    }
    
    public function logout() {
        session_destroy();
        header("Location: login.php");
        exit();
    }
    
    public function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }
    
    public function isAdmin() {
        return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
    }
    
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            header("Location: login.php");
            exit();
        }
    }
    
    public function requireAdmin() {
        $this->requireLogin();
        if (!$this->isAdmin()) {
            header("Location: resident_dashboard.php");
            exit();
        }
    }
    
    public function getUserInfo($user_id) {
        $query = "SELECT * FROM users WHERE id = :user_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// Handle logout action
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: login.php");
    exit();
}
?>
