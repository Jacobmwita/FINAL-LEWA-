<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'driver') {
    header("Location: user_login.php");
    exit();
}
require_once __DIR__ . '/../db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vehicle_id = $_POST['vehicle_id'];
    $description = $_POST['description'];
    $urgency = $_POST['urgency'] ?? 'normal'; 
    $driver_id = $_SESSION['user_id']; 


    if (empty($vehicle_id) || empty($description)) {
        $_SESSION['error_message'] = "Please fill in all required fields.";
        header("Location: request_service.php"); 
        exit();
    }
    
    $stmt = $conn->prepare("INSERT INTO job_cards (vehicle_id, driver_id, description, status, urgency, created_at) VALUES (?, ?, ?, 'pending', ?, NOW())");
    if ($stmt === false) {
        error_log("Failed to prepare statement for job_card insertion: " . $conn->error);
        $_SESSION['error_message'] = "An error occurred. Please try again later.";
        header("Location: driver_portal.php");
        exit();
    }

    
    if (!$stmt->bind_param("iiss", $vehicle_id, $driver_id, $description, $urgency)) {
        error_log("Failed to bind params for job_card insertion: " . $stmt->error);
        $_SESSION['error_message'] = "An error occurred. Please try again later.";
        header("Location: driver_portal.php");
        exit();
    }

    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Service request submitted successfully!";
        header("Location: driver_portal.php");
        exit();
    } else {
        error_log("Failed to execute statement for job_card insertion: " . $stmt->error);
        $_SESSION['error_message'] = "Failed to submit service request. Please try again.";
        header("Location: driver_portal.php");
        exit();
    }
    $stmt->close();
} else {
    
    header("Location: driver_portal.php");
    exit();
}
$conn->close();
?>