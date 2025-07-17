<?php
// This script generates an invoice for a given job card ID.
// It assumes the job card is already 'completed'.

session_start(); // Ensure session is started for user_id access

error_reporting(E_ALL); // Enable all error reporting
ini_set('display_errors', 1); // Display errors for debugging

require_once '../../includes/auth.php'; // For permission checks
require_once '../../includes/config.php'; // For DB config
// Changed to include db_connect.php which is expected to provide $conn (mysqli)
include '../db_connect.php'; // For mysqli connection

// Check if the user has supervisor permission
if (!hasPermission('supervisor')) {
    error_log("Unauthorized access attempt to generate_invoice.php by user_id: " . ($_SESSION['user_id'] ?? 'N/A'));
    header("Location: /autoservice/dashboard.php"); // Redirect to a safe page
    exit();
}

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

// Get the raw POST data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

$jobId = $data['jobId'] ?? null;

if (!$jobId) {
    $response['message'] = 'Job ID is required.';
    error_log("Generate Invoice Error: Job ID is missing.");
    echo json_encode($response);
    exit();
}

// Ensure $conn is available and connected
if (!$conn) {
    $response['message'] = 'Database connection error.';
    error_log("Generate Invoice Error: mysqli connection object is null.");
    echo json_encode($response);
    exit();
}

// Start transaction
mysqli_begin_transaction($conn);

try {
    // 1. Fetch job card details to ensure it's completed and get necessary data
    // Using 'job_card_id' as the primary key, consistent with service_dashboard.php
    $stmt = $conn->prepare("SELECT job_card_id, status, created_by_user_id, created_at, labor_cost, mechanic_id, vehicle_id FROM job_cards WHERE job_card_id = ?");
    if (!$stmt) {
        throw new Exception("Failed to prepare job card fetch statement: " . $conn->error);
    }
    $stmt->bind_param("i", $jobId);
    $stmt->execute();
    $result = $stmt->get_result();
    $jobCard = $result->fetch_assoc();
    $stmt->close();

    if (!$jobCard) {
        throw new Exception('Job card not found.');
    }

    if ($jobCard['status'] !== 'completed') {
        throw new Exception('Job card is not yet completed. An invoice can only be generated for completed jobs.');
    }

    // Check if an invoice already exists for this job card to prevent duplicates
    $stmt = $conn->prepare("SELECT invoice_id FROM invoices WHERE job_card_id = ?");
    if (!$stmt) {
        throw new Exception("Failed to prepare duplicate invoice check statement: " . $conn->error);
    }
    $stmt->bind_param("i", $jobId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        throw new Exception('An invoice already exists for this job card.');
    }
    $stmt->close();

    // 2. Calculate the invoice amount (labor cost + parts cost)
    $laborCost = $jobCard['labor_cost'] ?? 0.00;

    $partsCost = 0;
    $parts_query = "SELECT jcp.quantity_used, inv.unit_price 
                    FROM job_card_parts jcp 
                    JOIN inventory inv ON jcp.item_id = inv.item_id 
                    WHERE jcp.job_card_id = ?";
    $parts_stmt = $conn->prepare($parts_query);
    if ($parts_stmt) {
        $parts_stmt->bind_param("i", $jobId);
        $parts_stmt->execute();
        $parts_result = $parts_stmt->get_result();
        while ($part = $parts_result->fetch_assoc()) {
            $partsCost += $part['quantity_used'] * $part['unit_price'];
        }
        $parts_stmt->close();
    } else {
        error_log("Generate Invoice Error: Failed to prepare parts query: " . $conn->error);
        // Continue without parts cost if query fails, or throw an error based on strictness
    }

    $totalAmount = $laborCost + $partsCost;

    // Get necessary IDs for invoice record
    $requestedByUserId = $jobCard['created_by_user_id'];
    $jobCardUpdatedAt = $jobCard['created_at']; // Using created_at as an approximation for job update time
    $mechanicId = $jobCard['mechanic_id'];
    $serviceAdvisorId = $_SESSION['user_id']; // The supervisor generating the invoice

    // 3. Insert invoice record
    // Ensure 'invoices' table has columns: invoice_id (PK, AUTO_INCREMENT), job_card_id, mechanic_id, service_advisor_id, labor_cost, parts_cost, total_amount, invoice_date, created_at
    $insert_invoice_query = "INSERT INTO invoices (job_card_id, mechanic_id, service_advisor_id, labor_cost, parts_cost, total_amount, invoice_date, created_at) 
                             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())";
    $stmt = $conn->prepare($insert_invoice_query);
    if (!$stmt) {
        throw new Exception("Failed to prepare invoice insert statement: " . $conn->error);
    }
    $stmt->bind_param("iiiddd", // Corrected bind_param types
        $jobId,
        $mechanicId,
        $serviceAdvisorId,
        $laborCost,
        $partsCost,
        $totalAmount
    );

    if (!$stmt->execute()) {
        throw new Exception("Failed to create invoice: " . $stmt->error);
    }
    $invoice_id = $conn->insert_id; // Get the newly inserted invoice ID
    $stmt->close();

    // 4. Update job card status to 'invoiced'
    $stmt = $conn->prepare("UPDATE job_cards SET status = 'invoiced' WHERE job_card_id = ?");
    if (!$stmt) {
        throw new Exception("Failed to prepare job card status update statement: " . $conn->error);
    }
    $stmt->bind_param("i", $jobId);
    if (!$stmt->execute()) {
        throw new Exception("Failed to update job card status to 'invoiced': " . $stmt->error);
    }
    $stmt->close();

    mysqli_commit($conn); // Commit transaction
    $response['success'] = true;
    $response['message'] = 'Invoice generated successfully!';
    $response['invoice_id'] = $invoice_id; // Return the new invoice ID

} catch (Exception $e) {
    mysqli_rollback($conn); // Rollback on error
    $response['message'] = 'Error generating invoice: ' . $e->getMessage();
    error_log("Invoice generation failed for job ID {$jobId}: " . $e->getMessage());
} finally {
    if ($conn) {
        $conn->close();
    }
}

echo json_encode($response);
?>
