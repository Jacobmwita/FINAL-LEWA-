<?php

/**
 * Checks if the current logged-in user has a specific permission.
 *
 * This is a placeholder function. In a real application, you would
 * fetch the user's roles/permissions from the database (e.g., from
 * $_SESSION['user_permissions'] or by querying the 'users' table).
 *
 * @param string $permission The permission to check (e.g., 'parts', 'admin', 'mechanic', 'supervisor').
 * @return bool True if the user has the permission, false otherwise.
 */
function hasPermission($permission) {
    // In a real application, you'd check $_SESSION['user_roles'] or query the DB.
    // For demonstration, let's assume 'parts' permission is available if a session exists.
    // You'd typically load user permissions into session upon login.

    if (session_status() == PHP_SESSION_NONE) {
        session_start(); // Start session if not already started
    }

    // Example: Check if the user is logged in and their role or specific permission is set
    // This is a very basic example. You would likely have a more robust permission system.
    if (isset($_SESSION['user_role'])) {
        $user_role = $_SESSION['user_role'];

        switch ($permission) {
            case 'parts':
                // Assuming 'parts_manager' role has 'parts' permission
                return ($user_role === 'parts_manager' || $user_role === 'admin');
            case 'admin':
                return ($user_role === 'admin');
            case 'supervisor': // Added case for supervisor permission
                return ($user_role === 'supervisor' || $user_role === 'admin');
            // Add more cases for other permissions/roles
            default:
                return false;
        }
    }

    // If no session or user role, no permission
    return false;
}

/**
 * Formats a date string into a more readable format.
 *
 * @param string $date_string The date string to format (e.g., from database 'YYYY-MM-DD HH:MM:SS').
 * @return string The formatted date (e.g., 'June 06, 2025').
 */
function formatDate($date_string) {
    if (empty($date_string) || $date_string === '0000-00-00 00:00:00') {
        return 'N/A'; // Or whatever placeholder you prefer for empty dates
    }
    // Create a DateTime object from the input string
    $date = new DateTime($date_string);
    // Format the date as "Month Day, Year"
    return $date->format('F d, Y');
}

/**
 * Sanitize input data to prevent XSS.
 * Use this function when displaying user-generated content in HTML.
 *
 * @param string $data The input string to sanitize.
 * @return string The sanitized string.
 */
function sanitizeInput($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

// Add more helper functions as needed (e.g., for database interactions, validation, etc.)

?>