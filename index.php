<?php
/**
 * Kapasigan - Facility and Resource Management System
 * Main entry point for the application
 */

// Start session
session_start();

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define base path
define('BASE_PATH', __DIR__);
define('INCLUDES_PATH', BASE_PATH . '/includes');
define('CONFIG_PATH', BASE_PATH . '/config');

// Load configuration
require_once CONFIG_PATH . '/database.php';

// Load authentication
require_once INCLUDES_PATH . '/auth.php';

// Route the request
$request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$request_uri = str_replace('/index.php', '', $request_uri);
$request_uri = trim($request_uri, '/');

// Default route
if (empty($request_uri) || $request_uri === '') {
    // Check if user is logged in
    if (isset($_SESSION['user_id'])) {
        // Redirect to appropriate dashboard based on role
        $role = $_SESSION['role'] ?? 'resident';
        if ($role === 'admin') {
            header('Location: /admin_dashboard.php');
        } else {
            header('Location: /resident_dashboard.php');
        }
        exit;
    } else {
        // Redirect to login
        header('Location: /login.php');
        exit;
    }
}

// Route to appropriate file
$routes = [
    'login' => 'login.php',
    'register' => 'register.php',
    'logout' => 'logout.php',
    'dashboard' => 'resident_dashboard.php',
    'admin' => 'admin_dashboard.php',
    'calendar' => 'calendar.php',
    'book-facility' => 'book_facility.php',
    'request-vehicle' => 'request_vehicle.php',
    'borrow-item' => 'borrow_item.php',
    'my-requests' => 'my_requests.php',
    'messages' => 'resident_messages.php',
    'reminders' => 'reminders.php',
];

// Check if route exists
if (isset($routes[$request_uri])) {
    require_once BASE_PATH . '/' . $routes[$request_uri];
} else {
    // Try to load the file directly if it exists
    $file_path = BASE_PATH . '/' . $request_uri . '.php';
    if (file_exists($file_path)) {
        require_once $file_path;
    } else {
        // 404 error
        http_response_code(404);
        echo "Page not found: " . htmlspecialchars($request_uri);
    }
}
?>
