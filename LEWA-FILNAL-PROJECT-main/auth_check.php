<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


function auth_check() {

    if (!headers_sent()) {
        session_set_cookie_params([
            'lifetime' => 86400,
            'path' => '/',
            'domain' => $_SERVER['HTTP_HOST'],
            'secure' => isset($_SERVER['HTTPS']), 
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!headers_sent()) { 
        header("X-Frame-Options: DENY");
        header("X-Content-Type-Options: nosniff");
        header("X-XSS-Protection: 1; mode=block");
    }

    if (!defined('BASE_URL')) {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        define('BASE_URL', "{$protocol}://{$host}/lewa");
    }
    if (!isset($_SESSION['user_id'])) {
        

        error_log("Auth Check: No user_id in session. Redirecting to login.");
        header("Location: " . BASE_URL . "/user_login.php");
        exit();
    }

    
    if (!isset($_SESSION['dashboard_access'])) {
        
        error_log("Auth Check: dashboard_access missing. Destroying session and redirecting.");
        session_destroy();
        header("Location: " . BASE_URL . "/user_login.php?error=no_access");
        exit();
    }

    $allowed_dashboards = [
        'admin_dashboard' => 'admin_dashboard.php',
        'service_dashboard' => 'service_dashboard.php',
        'driver_portal' => 'driver_portal.php',
        'technician_dashboard' => 'technician_dashboard.php',
        'inventory_dashboard' => 'inventory_dashboard.php',
        'supervisor_dashboard' => 'supervisor_dashboard.php'
    ];


    $current_page = basename($_SERVER['SCRIPT_NAME']);

    
    $expected_dashboard = $allowed_dashboards[$_SESSION['dashboard_access']] ?? null;

    
    if ($expected_dashboard === null || $expected_dashboard !== $current_page) {
        error_log("Auth Check: User tried to access wrong dashboard. Expected: " . ($expected_dashboard ?? 'N/A') . ", Actual: " . $current_page . ". Redirecting.");
        

        header("Location: " . BASE_URL . "/dashboards/" . $expected_dashboard);
        exit();
    }
}


?>