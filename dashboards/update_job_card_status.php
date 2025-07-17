<?php

session_start();
// GIVEN ACCES TO ADMINS ONLY
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

include '../db_connect.php';

// Check if the database connection was successful
if (!$conn) {
    $_SESSION['error_message'] = "Database connection failed.";
    header("Location: admin_job_cards.php");
    exit();
}

// Ensure job_card_id and status are always set in the POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['job_card_id'], $_POST['status'])) {
    $job_card_id = $_POST['job_card_id'];
    $new_status = $_POST['status'];

    $assigned_to_mechanic_id = isset($_POST['assigned_to_mechanic_id']) && $_POST['assigned_to_mechanic_id'] !== '' ? (int)$_POST['assigned_to_mechanic_id'] : null;
    // New: Get the service advisor ID from the POST request
    $service_advisor_id = isset($_POST['service_advisor_id']) && $_POST['service_advisor_id'] !== '' ? (int)$_POST['service_advisor_id'] : null;

    $stmt = null; // Initialize $stmt to null

    if ($new_status === 'completed') {
        $current_timestamp = date('Y-m-d H:i:s');
        // --- MODIFIED QUERY: Include service_advisor_id update ---
        $update_query = "UPDATE job_cards SET status = ?, completed_at = ?, assigned_to_mechanic_id = ?, service_advisor_id = ? WHERE job_card_id = ?";
        $stmt = $conn->prepare($update_query);
        if ($stmt) {
            // --- MODIFIED BIND_PARAM: Include service_advisor_id ---
            $stmt->bind_param("ssiii", $new_status, $current_timestamp, $assigned_to_mechanic_id, $service_advisor_id, $job_card_id);
        }
    } else {
        // --- MODIFIED QUERY: Include assigned_mechanic_id and service_advisor_id update ---
        $update_query = "UPDATE job_cards SET status = ?, completed_at = NULL, assigned_to_mechanic_id = ?, service_advisor_id = ? WHERE job_card_id = ?";
        $stmt = $conn->prepare($update_query);
        if ($stmt) {
            // --- MODIFIED BIND_PARAM: Include service_advisor_id ---
            $stmt->bind_param("siii", $new_status, $assigned_to_mechanic_id, $service_advisor_id, $job_card_id);
        }
    }

    // Check if statement preparation was successful
    if (!$stmt) {
        $_SESSION['error_message'] = "Failed to prepare the SQL statement: " . $conn->error;

        header("Location: admin_job_cards.php");
        exit();
    }

    // Execute the statement
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Job card status and assigned personnel updated successfully!";
        header("Location: admin_job_cards.php");
        exit();
    } else {
        // This is the most common place for database-related errors
        $_SESSION['error_message'] = "Error updating job card status: " . $stmt->error;
        // error_log("SQL Execute Error:
        header("Location: admin_job_cards.php");
        exit();
    }

    // Close the statement and connection.
    $stmt->close();
    $conn->close();

} else {
    // This error triggers if 'job_card_id' or 'status'
    $_SESSION['error_message'] = "Invalid request or missing job ID/status.";
    header("Location: admin_job_cards.php");
    exit();
}
?>
