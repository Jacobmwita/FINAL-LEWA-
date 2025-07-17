<?php

session_start();
// GIVEN  ACCES  TO  ADMINS ONLY
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: admin_login.php"); // Redirect to admin login if not an admin
    exit();
}

include '../db_connect.php'; 

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['job_card_id'], $_POST['status'])) {
    $job_card_id = $_POST['job_card_id'];
    $new_status = $_POST['status'];

    $current_timestamp = null;

    if ($new_status === 'completed') {
        $current_timestamp = date('Y-m-d H:i:s'); 
        $update_query = "UPDATE job_cards SET status = ?, completed_at = ? WHERE job_card_id = ?";
        $stmt = $conn->prepare($update_query);
        if ($stmt) {
            $stmt->bind_param("ssi", $new_status, $current_timestamp, $job_card_id);
        }
    } else {

        $update_query = "UPDATE job_cards SET status = ?, completed_at = NULL WHERE job_card_id = ?"; // Set to NULL if not completed
        $stmt = $conn->prepare($update_query);
        if ($stmt) {
            $stmt->bind_param("si", $new_status, $job_card_id);
        }
    }

    if ($stmt && $stmt->execute()) {
        $_SESSION['success_message'] = "Job card status updated successfully!";

        header("Location: admin_job_cards.php"); 
        exit();
    } else {
        $_SESSION['error_message'] = "Error updating job card status: " . ($stmt ? $stmt->error : $conn->error);
        header("Location: admin_job_cards.php"); 
        exit();
    }
    $stmt->close();
    $conn->close();
} else {
    $_SESSION['error_message'] = "Invalid request.";
    header("Location: admin_job_cards.php"); 
    exit();
}
?>