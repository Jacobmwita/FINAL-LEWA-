<?php
// get_completed_count.php
// This file provides the count of jobs completed in the current week via AJAX.

session_start(); // Start the session if it's not already started. This is good practice.

// Set the content type to JSON. This tells the browser to expect a JSON response.
header('Content-Type: application/json');

// Include your database connection file.
// Adjust the path if 'db_connect.php' is located elsewhere relative to this file.
include __DIR__ . '/../db_connect.php';

// Check if the database connection was successful.
// If not, return an error message as JSON and exit.
if (!$conn) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed. Please check db_connect.php.']);
    exit();
}

// --- Timezone Configuration (Crucial for Accurate Week Calculation) ---
// Set the default timezone to East African Time (EAT) for accurate date calculations.
// This ensures that 'this monday', 'this sunday' align with your local time.
date_default_timezone_set('Africa/Nairobi');

// Calculate the start and end timestamps for the current week (Monday 00:00:00 to Sunday 23:59:59).
// 'last monday' gets the start of the current week (e.g., if today is Friday, it's this Monday).
// 'this sunday' gets the end of the current week.
$start_of_week = date('Y-m-d 00:00:00', strtotime('last monday'));
$end_of_week = date('Y-m-d 23:59:59', strtotime('this sunday'));

// Prepare the SQL query to count job cards that are 'completed'
// and whose 'completed_at' timestamp falls within the current week.
// Using a prepared statement prevents SQL injection.
$completed_this_week_query = "SELECT COUNT(*) AS count FROM job_cards WHERE status = 'completed' AND completed_at BETWEEN ? AND ?";
$stmt = $conn->prepare($completed_this_week_query);

if ($stmt) {
    // Bind the parameters: 'ss' means two string parameters ($start_of_week, $end_of_week).
    $stmt->bind_param("ss", $start_of_week, $end_of_week);
    $stmt->execute(); // Execute the prepared statement.
    $result = $stmt->get_result()->fetch_assoc(); // Get the result as an associative array.
    $completed_count = $result['count']; // Extract the 'count' value.
    $stmt->close(); // Close the prepared statement.

    // Return a JSON success response with the fetched count.
    echo json_encode(['status' => 'success', 'count' => $completed_count]);
} else {
    // If the prepared statement failed, return a JSON error response.
    echo json_encode(['status' => 'error', 'message' => 'Failed to prepare SQL query: ' . $conn->error]);
    // Log the error for debugging purposes (check your server's PHP error logs).
    error_log("Error preparing completed jobs count query: " . $conn->error);
}

// Close the database connection.
$conn->close();

?>