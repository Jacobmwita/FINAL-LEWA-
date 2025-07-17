<?php
session_start();
header('Content-Type: application/json');

// Security Checkup
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'supervisor' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

// CSRF Protection
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit();
}

// Include necessary files
include '../db_connect.php';

// Validate input - parts_cost will now be calculated, so we don't expect it from POST
if (!isset($_POST['job_card_id'], $_POST['labor_cost'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required data for invoice creation.']);
    exit();
}

$job_card_id = intval($_POST['job_card_id']);
$labor_cost = floatval($_POST['labor_cost']);
// $parts_cost will be calculated dynamically below
$generated_by = $_SESSION['user_id'];
$invoice_date = date('Y-m-d H:i:s');

// --- FETCH assigned_to_mechanic_id and service_advisor_id from job_cards table ---
$assigned_to_mechanic_id = null;
$service_advisor_id = null;

$stmt_fetch_job_card_details = $conn->prepare("SELECT assigned_to_mechanic_id, service_advisor_id FROM job_cards WHERE job_card_id = ?");
if ($stmt_fetch_job_card_details) {
    $stmt_fetch_job_card_details->bind_param("i", $job_card_id);
    $stmt_fetch_job_card_details->execute();
    $result_job_card_details = $stmt_fetch_job_card_details->get_result();
    if ($result_job_card_details->num_rows > 0) {
        $row_job_card_details = $result_job_card_details->fetch_assoc();
        $assigned_to_mechanic_id_val = $row_job_card_details['assigned_to_mechanic_id'];
        $service_advisor_id_val = $row_job_card_details['service_advisor_id'];

        if ($assigned_to_mechanic_id_val !== null && $assigned_to_mechanic_id_val > 0) {
            $assigned_to_mechanic_id = intval($assigned_to_mechanic_id_val);
        }
        if ($service_advisor_id_val !== null && $service_advisor_id_val > 0) {
            $service_advisor_id = intval($service_advisor_id_val);
        }
    }
    $stmt_fetch_job_card_details->close();
} else {
    error_log("Failed to prepare statement for fetching job card details (mechanic/advisor): " . $conn->error);
}


try {
    $conn->begin_transaction();

    // Calculate parts_cost from job_parts table
    $calculated_parts_cost = 0;
    $parts_sum_query = "SELECT SUM(jcp.quantity_used * inv.price) AS total_parts_cost
                        FROM job_parts jcp
                        JOIN inventory inv ON jcp.item_id = inv.item_id
                        WHERE jcp.job_card_id = ?";
    $stmt_parts_sum = $conn->prepare($parts_sum_query);
    if ($stmt_parts_sum) {
        $stmt_parts_sum->bind_param("i", $job_card_id);
        $stmt_parts_sum->execute();
        $result_parts_sum = $stmt_parts_sum->get_result();
        if ($result_parts_sum->num_rows > 0) {
            $row_parts_sum = $result_parts_sum->fetch_assoc();
            $calculated_parts_cost = floatval($row_parts_sum['total_parts_cost']);
        }
        $stmt_parts_sum->close();
    } else {
        throw new Exception("Failed to prepare parts cost calculation query: " . $conn->error);
    }
    $parts_cost = $calculated_parts_cost; // Assign the calculated value to $parts_cost

    $total_amount = $labor_cost + $parts_cost; // Recalculate total_amount with the new parts_cost

    // 1. Insert into invoices table
    // CORRECTED: Changed 'mechanic_id' to 'assigned_to_mechanic_id' in the INSERT statement
    $stmt_invoice = $conn->prepare("INSERT INTO invoices (job_card_id, labor_cost, parts_cost, total_amount, generated_by, invoice_date, assigned_to_mechanic_id, service_advisor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

    if (!$stmt_invoice) {
        throw new Exception("Invoice statement preparation failed: " . $conn->error);
    }

    // Bind parameters, using 'i' for integers and 'd' for doubles.
    // For nullable integer fields (assigned_to_mechanic_id, service_advisor_id), passing NULL directly works with 'i' type.
    $stmt_invoice->bind_param("idddisii", $job_card_id, $labor_cost, $parts_cost, $total_amount, $generated_by, $invoice_date, $assigned_to_mechanic_id, $service_advisor_id);

    if (!$stmt_invoice->execute()) {
        throw new Exception("Invoice insertion failed: " . $stmt_invoice->error);
    }

    // 2. Update the job card status to 'invoiced'
    $stmt_job_card_update = $conn->prepare("UPDATE job_cards SET status = 'invoiced' WHERE job_card_id = ?");
    if (!$stmt_job_card_update) {
        throw new Exception("Job card status update statement preparation failed: " . $conn->error);
    }
    $stmt_job_card_update->bind_param("i", $job_card_id);

    if (!$stmt_job_card_update->execute()) {
        throw new Exception("Job card status update failed: " . $stmt_job_card_update->error);
    }

    // Commit the transaction
    $conn->commit();
    $stmt_invoice->close();
    $stmt_job_card_update->close();
    $conn->close();

    echo json_encode(['success' => true, 'message' => 'Invoice generated successfully!']);

} catch (Exception $e) {
    $conn->rollback();
    error_log("Invoice generation failed: " . $e->getMessage());
    http_response_code(500); // Internal Server Error
    echo json_encode(['success' => false, 'message' => 'Failed to generate invoice. ' . $e->getMessage()]);
    // Close connection in case of error
    if (isset($conn) && $conn->ping()) {
        $conn->close();
    }
}
?>