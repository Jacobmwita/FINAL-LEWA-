<?php
// dashboards/request_parts.php

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

// SECURITY CHECKUP
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'mechanic') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

// INITIATE DATABASE CONNECTION
include __DIR__ . '/../db_connect.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit();
}

// VALIDATE REQUEST METHOD
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

// VALIDATE AND SANITIZE INPUTS
$job_card_id = filter_input(INPUT_POST, 'job_card_id', FILTER_VALIDATE_INT);
$item_id = filter_input(INPUT_POST, 'part_item_id', FILTER_VALIDATE_INT);
$quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);

// Basic input validation
if ($job_card_id === false || $item_id === false || $quantity === false || $job_card_id <= 0 || $item_id <= 0 || $quantity <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid data provided.']);
    exit();
}

$mechanic_id = $_SESSION['user_id'];

// Check if the job card is actually assigned to this mechanic
// CORRECTED: Changed 'mechanic_id' to 'assigned_to_mechanic_id'
$check_job_query = "SELECT assigned_to_mechanic_id FROM job_cards WHERE job_card_id = ?";
$stmt = $conn->prepare($check_job_query);
$stmt->bind_param("i", $job_card_id);
$stmt->execute();
$result = $stmt->get_result();
$job = $result->fetch_assoc();

// CORRECTED: Check against assigned_to_mechanic_id
if (!$job || $job['assigned_to_mechanic_id'] != $mechanic_id) {
    echo json_encode(['success' => false, 'message' => 'Job card not found or not assigned to you.']);
    $stmt->close();
    exit();
}
$stmt->close();

// Check if the part exists
$check_part_query = "SELECT item_id FROM inventory WHERE item_id = ?";
$stmt = $conn->prepare($check_part_query);
$stmt->bind_param("i", $item_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'The selected part does not exist.']);
    $stmt->close();
    exit();
}
$stmt->close();

// START TRANSACTION
$conn->begin_transaction();

try {
    // Insert the new parts request into the database
    // The 'mechanic_id' here in parts_requests is likely the *requesting* mechanic, which is fine.
    $insert_request_sql = "INSERT INTO parts_requests (job_card_id, mechanic_id, item_id, quantity_requested, status) VALUES (?, ?, ?, ?, 'pending')";
    $stmt = $conn->prepare($insert_request_sql);
    $stmt->bind_param("iiii", $job_card_id, $mechanic_id, $item_id, $quantity);

    if (!$stmt->execute()) {
        throw new Exception("Failed to record parts request.");
    }
    $stmt->close();

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Parts request submitted successfully!']);

} catch (Exception $e) {
    $conn->rollback();
    error_log("Parts request failed for Job ID {$job_card_id}: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred while processing your request. Please try again.']);
}

$conn->close();
?>