<?php
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['user_id'])) {
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized access']));
}

include __DIR__ . '/../db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $job_card_id = filter_input(INPUT_POST, 'job_card_id', FILTER_VALIDATE_INT);
    // Use assigned_to_mechanic_id consistently for the mechanic being assigned
    $assigned_to_mechanic_id = filter_input(INPUT_POST, 'assigned_to_mechanic_id', FILTER_VALIDATE_INT);

    if (!$job_card_id || !$assigned_to_mechanic_id) {
        die(json_encode(['status' => 'error', 'message' => 'Invalid job card or mechanic ID']));
    }

    try {
        $conn->begin_transaction();

        // Check job card status
        $check_stmt = $conn->prepare("SELECT status FROM job_cards WHERE job_card_id = ?");
        $check_stmt->bind_param("i", $job_card_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();

        if ($result->num_rows === 0) {
            die(json_encode(['status' => 'error', 'message' => 'Job card not found']));
        }

        $job = $result->fetch_assoc();
        if ($job['status'] !== 'pending') {
            die(json_encode(['status' => 'error', 'message' => 'Job is not in pending status']));
        }

        // Validate the mechanic is active and exists
        // Use $assigned_to_mechanic_id here for consistency
        $mech_stmt = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND user_type = 'mechanic' AND is_active = 1");
        $mech_stmt->bind_param("i", $assigned_to_mechanic_id); // Changed from $mechanic_id
        $mech_stmt->execute();

        if ($mech_stmt->get_result()->num_rows === 0) {
            die(json_encode(['status' => 'error', 'message' => 'Invalid or inactive mechanic']));
        }

        // Update job card with assigned mechanic and status
        $update_stmt = $conn->prepare("UPDATE job_cards SET assigned_to_mechanic_id = ?, status = 'assigned', assigned_at = NOW() WHERE job_card_id = ?");
        $update_stmt->bind_param("ii", $assigned_to_mechanic_id, $job_card_id);

        if (!$update_stmt->execute()) {
            throw new Exception("Failed to assign mechanic");
        }

        // Insert into job_assignments history
        $history_stmt = $conn->prepare("INSERT INTO job_assignments (job_card_id, assigned_to_mechanic_id, assigned_by, assigned_at) VALUES (?, ?, ?, NOW())");
        $history_stmt->bind_param("iii", $job_card_id, $assigned_to_mechanic_id, $_SESSION['user_id']);
        $history_stmt->execute();

        $conn->commit();

        echo json_encode(['status' => 'success', 'message' => 'Mechanic assigned successfully']);
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        die(json_encode(['status' => 'error', 'message' => $e->getMessage()]));
    }
} else {
    // Redirect if not a POST request
    header("Location: service_dashboard.php");
    exit();
}
?>