<?php
// assign_job_with_parts.php
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

// Verify user is logged in and is a service advisor
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'service_advisor') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please log in as a service advisor.']);
    exit();
}

// Validate CSRF token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['status' => 'error', 'message' => 'CSRF token mismatch.']);
    exit();
}

// Include database connection
include __DIR__ . '/../db_connect.php';

if (!$conn) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    exit();
}

$job_card_id = filter_var($_POST['job_card_id'] ?? '', FILTER_VALIDATE_INT);
$mechanic_id = filter_var($_POST['mechanic_id'] ?? '', FILTER_VALIDATE_INT);
$requested_parts = $_POST['parts'] ?? []; // Array of parts selected

if (!$job_card_id || !$mechanic_id) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid job card ID or mechanic ID provided.']);
    $conn->close();
    exit();
}

// Start transaction
$conn->begin_transaction();

try {
    // 1. Assign mechanic and update job card status
    $update_job_query = "UPDATE job_cards SET assigned_mechanic_id = ?, status = 'assigned', assigned_at = NOW() WHERE job_card_id = ? AND status = 'pending'";
    $stmt_update_job = $conn->prepare($update_job_query);
    if (!$stmt_update_job) {
        throw new Exception("Failed to prepare job update query: " . $conn->error);
    }
    $stmt_update_job->bind_param("ii", $mechanic_id, $job_card_id);
    $stmt_update_job->execute();

    if ($stmt_update_job->affected_rows === 0) {
        throw new Exception("Job card not found or already assigned.");
    }
    $stmt_update_job->close();

    // 2. Insert requested spare parts into job_card_parts table
    if (!empty($requested_parts)) {
        $insert_part_query = "INSERT INTO job_parts (job_card_id, job_part_id, quantity_requested, status, created_at) VALUES (?, ?, ?, 'pending', NOW())";
        $stmt_insert_part = $conn->prepare($insert_part_query);
        if (!$stmt_insert_part) {
            throw new Exception("Failed to prepare part insertion query: " . $conn->error);
        }

        foreach ($requested_parts as $part_id => $part_data) {
            // Check if the part was selected (checkbox value '1')
            if (isset($part_data['selected']) && $part_data['selected'] == '1') {
                $quantity = filter_var($part_data['quantity'] ?? 1, FILTER_VALIDATE_INT);
                
                // Ensure quantity is positive
                if ($quantity <= 0) {
                    $quantity = 1; 
                }

                // Optionally, fetch max quantity from spare_parts to prevent requesting more than stock
                // For simplicity, we'll rely on the front-end max attribute for now, 
                // but a server-side check here would be more robust.
                $stmt_insert_part->bind_param("iii", $job_card_id, $job_part_id, $quantity);
                $stmt_insert_part->execute();
                if ($stmt_insert_part->affected_rows === 0) {
                    // Log or handle cases where a part insertion fails (e.g., part_id not found)
                    error_log("Failed to insert part (ID: $job_part_id, Job: $job_card_id) into job_parts.");
                    // You might choose to throw an exception here if a single part failure should fail the whole transaction
                }
            }
        }
        $stmt_insert_part->close();
    }

    // Commit transaction
    $conn->commit();
    echo json_encode(['status' => 'success', 'message' => 'Mechanic assigned and parts requested successfully!']);

} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    error_log("Transaction failed: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Failed to assign job or request parts: ' . $e->getMessage()]);
} finally {
    $conn->close();
}
?>