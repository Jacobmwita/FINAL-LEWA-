<?php
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

// Define allowed user types for this script
$allowed_user_types = ['service_advisor', 'workshop_manager', 'admin', 'administrator', 'manager'];

if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['user_type']), $allowed_user_types)) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Your user type does not have permission to perform this action.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit();
}

if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['status' => 'error', 'message' => 'CSRF token mismatch.']);
    exit();
}

include __DIR__ . '/../db_connect.php';

if (!$conn) {
    error_log("Database connection failed in assign_job_with_parts.php: " . mysqli_connect_error());
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    exit();
}

$job_card_id = filter_input(INPUT_POST, 'job_card_id', FILTER_VALIDATE_INT);
$assigned_to_mechanic_id = filter_input(INPUT_POST, 'mechanic_id', FILTER_VALIDATE_INT); // Input name is 'mechanic_id' from form
$labor_cost = filter_input(INPUT_POST, 'labor_cost', FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0]]);
$parts = isset($_POST['parts']) && is_array($_POST['parts']) ? $_POST['parts'] : [];

if (!$job_card_id || !$assigned_to_mechanic_id) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid job card or mechanic ID provided.']);
    exit();
}

try {
    $conn->begin_transaction();

    // 1. Check job card status and mechanic validity
    $check_stmt = $conn->prepare("SELECT status FROM job_cards WHERE job_card_id = ?");
    if (!$check_stmt) {
        throw new Exception("Failed to prepare job card check statement: " . $conn->error);
    }
    $check_stmt->bind_param("i", $job_card_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();

    if ($result->num_rows === 0) {
        throw new Exception('Job card not found.');
    }

    $job = $result->fetch_assoc();
    // Allow re-assignment if status is pending, assigned, or waiting_for_parts
    if (!in_array($job['status'], ['pending', 'assigned', 'waiting_for_parts'])) {
        throw new Exception('Job is not in a status that allows re-assignment or initial assignment (must be pending, assigned, or waiting for parts). Current status: ' . $job['status']);
    }
    $check_stmt->close();

    // Verify mechanic exists and is active
    $mech_stmt = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND user_type = 'mechanic' AND is_active = 1");
    if (!$mech_stmt) {
        throw new Exception("Failed to prepare mechanic check statement: " . $conn->error);
    }
    $mech_stmt->bind_param("i", $assigned_to_mechanic_id); // Use the consistent variable name here
    $mech_stmt->execute();
    if ($mech_stmt->get_result()->num_rows === 0) {
        throw new Exception('Invalid or inactive mechanic selected.');
    }
    $mech_stmt->close();

    // 2. Update job card with assigned mechanic, status, and labor cost
    // CORRECTED: Changed 'mechanic_id' to 'assigned_to_mechanic_id'
    $update_sql = "UPDATE job_cards SET assigned_to_mechanic_id = ?, status = 'assigned', assigned_at = NOW()";
    $types = "i";
    $params = [$assigned_to_mechanic_id]; // Use the consistent variable name here

    // Only add labor_cost if it's provided and valid
    if ($labor_cost !== null) {
        $update_sql .= ", labor_cost = ?";
        $types .= "d"; // 'd' for double (float)
        $params[] = $labor_cost;
    }

    $update_sql .= " WHERE job_card_id = ?";
    $types .= "i";
    $params[] = $job_card_id;

    $update_stmt = $conn->prepare($update_sql);
    if (!$update_stmt) {
        throw new Exception("Failed to prepare job card update statement: " . $conn->error);
    }

    // Bind parameters dynamically
    $bind_names[] = $types;
    for ($i = 0; $i < count($params); $i++) {
        $bind_name = 'bind' . $i;
        $$bind_name = $params[$i];
        $bind_names[] = &$$bind_name;
    }
    call_user_func_array([$update_stmt, 'bind_param'], $bind_names);

    if (!$update_stmt->execute()) {
        throw new Exception("Failed to assign mechanic to job card: " . $update_stmt->error);
    }
    $update_stmt->close();

    // 3. Record job assignment history
    // Assuming 'mechanic_id' in job_assignments refers to the assigned mechanic for consistency
    $history_stmt = $conn->prepare("INSERT INTO job_assignments (job_card_id, assigned_to_mechanic_id, assigned_by, assigned_at) VALUES (?, ?, ?, NOW())");
    if (!$history_stmt) {
        throw new Exception("Failed to prepare job assignment history statement: " . $conn->error);
    }
    $history_stmt->bind_param("iii", $job_card_id, $assigned_to_mechanic_id, $_SESSION['user_id']); // Use assigned_to_mechanic_id
    if (!$history_stmt->execute()) {
        throw new Exception("Failed to record job assignment history: " . $history_stmt->error);
    }
    $history_stmt->close();

    // 4. Process parts (deduct from inventory and record in job_parts)
    foreach ($parts as $item_id => $part_data) {
        if (isset($part_data['selected']) && $part_data['selected'] == 1) {
            $quantity_requested = filter_var($part_data['quantity'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if (!$quantity_requested) {
                throw new Exception("Invalid quantity for part ID " . htmlspecialchars($item_id));
            }

            // Check current stock and deduct
            $stmt_check_stock = $conn->prepare("SELECT quantity, item_name FROM inventory WHERE item_id = ? FOR UPDATE");
            if (!$stmt_check_stock) {
                throw new Exception("Failed to prepare stock check statement: " . $conn->error);
            }
            $stmt_check_stock->bind_param("i", $item_id);
            $stmt_check_stock->execute();
            $stock_result = $stmt_check_stock->get_result();
            if ($stock_result->num_rows === 0) {
                throw new Exception("Part with ID " . htmlspecialchars($item_id) . " not found in inventory.");
            }
            $stock_row = $stock_result->fetch_assoc();
            $current_stock = $stock_row['quantity'];
            $item_name = $stock_row['item_name'];
            $stmt_check_stock->close();

            if ($current_stock < $quantity_requested) {
                throw new Exception("Not enough stock for " . htmlspecialchars($item_name) . ". Available: " . htmlspecialchars($current_stock) . ", Requested: " . htmlspecialchars($quantity_requested) . ".");
            }

            // Deduct stock
            $stmt_deduct_stock = $conn->prepare("UPDATE inventory SET quantity = quantity - ? WHERE item_id = ?");
            if (!$stmt_deduct_stock) {
                throw new Exception("Failed to prepare stock deduction statement: " . $conn->error);
            }
            $stmt_deduct_stock->bind_param("ii", $quantity_requested, $item_id);
            if (!$stmt_deduct_stock->execute()) {
                throw new Exception("Failed to deduct stock for part ID " . htmlspecialchars($item_id) . ": " . $stmt_deduct_stock->error);
            }
            $stmt_deduct_stock->close();

            // Record part usage for the job
            // Assuming 'assigned_by_user_id' is the user who assigned the parts, not necessarily the assigned mechanic
            $record_job_part_sql = "INSERT INTO job_parts (job_card_id, item_id, quantity_used, assigned_by_user_id) VALUES (?, ?, ?, ?)";
            $stmt_record_part = $conn->prepare($record_job_part_sql);
            if (!$stmt_record_part) {
                throw new Exception("Failed to prepare job part record statement: " . $conn->error);
            }
            $stmt_record_part->bind_param("iiii", $job_card_id, $item_id, $quantity_requested, $_SESSION['user_id']);
            if (!$stmt_record_part->execute()) {
                throw new Exception("Failed to record part usage for job ID " . htmlspecialchars($job_card_id) . ": " . $stmt_record_part->error);
            }
            $stmt_record_part->close();
        }
    }

    $conn->commit();
    echo json_encode(['status' => 'success', 'message' => 'Job card assigned and parts requested successfully.']);

} catch (Exception $e) {
    $conn->rollback();
    error_log("Transaction failed in assign_job_with_parts.php: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Transaction failed: ' . $e->getMessage()]);
} finally {
    if (isset($conn) && $conn->ping()) {
        $conn->close();
    }
}
?>