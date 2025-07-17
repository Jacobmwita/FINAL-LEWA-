<?php
// Database credentials
define('DB_HOST', 'localhost'); // Your database host (e.g., 'localhost' or an IP address)
define('DB_NAME', 'lewa'); // Your database name
define('DB_USER', 'root'); // Your database username (e.g., 'root' for XAMPP default)
define('DB_PASS', ''); // Your database password (e.g., '' for XAMPP default, or a strong password if set)

// Application base URL (useful for redirects, includes, etc.)
// Make sure this matches your project's URL in XAMPP
define('BASE_URL', 'http://localhost/lewa/');

// Other configurations (example)
define('DEFAULT_TIMEZONE', 'Africa/Nairobi'); // Set your timezone

// --- DO NOT EDIT BELOW THIS LINE ---

// Set the default timezone
date_default_timezone_set(DEFAULT_TIMEZONE);

// Error reporting (adjust for production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Database connection using PDO
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    // Set the PDO error mode to exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Set default fetch mode to associative array
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>