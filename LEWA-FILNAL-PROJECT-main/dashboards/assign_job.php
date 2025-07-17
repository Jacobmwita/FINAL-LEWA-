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
    $mechanic_id = filter_input(INPUT_POST, 'mechanic_id', FILTER_VALIDATE_INT);

    if (!$job_card_id || !$mechanic_id) {
        die(json_encode(['status' => 'error', 'message' => 'Invalid job card or mechanic ID']));
    }

    try {
    
        $conn->begin_transaction();

        
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

    
        $mech_stmt = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND user_type = 'mechanic' AND is_active = 1");
        $mech_stmt->bind_param("i", $mechanic_id);
        $mech_stmt->execute();

        if ($mech_stmt->get_result()->num_rows === 0) {
            die(json_encode(['status' => 'error', 'message' => 'Invalid or inactive mechanic']));
        }


        $update_stmt = $conn->prepare("UPDATE job_cards SET mechanic_id = ?, status = 'assigned', assigned_at = NOW() WHERE job_card_id = ?");
        $update_stmt->bind_param("ii", $mechanic_id, $job_card_id);

        if (!$update_stmt->execute()) {
            throw new Exception("Failed to assign mechanic");
        }

        
        $history_stmt = $conn->prepare("INSERT INTO job_assignments (job_card_id, mechanic_id, assigned_by, assigned_at) VALUES (?, ?, ?, NOW())");
        $history_stmt->bind_param("iii", $job_card_id, $mechanic_id, $_SESSION['user_id']);
        $history_stmt->execute();

    
        $conn->commit();

        
        echo json_encode(['status' => 'success', 'message' => 'Mechanic assigned successfully']);
        exit();

    } catch (Exception $e) {
        
        $conn->rollback();
        die(json_encode(['status' => 'error', 'message' => $e->getMessage()]));
    }
} else {
    
    header("Location: service_dashboard.php");
    exit();
}
?>