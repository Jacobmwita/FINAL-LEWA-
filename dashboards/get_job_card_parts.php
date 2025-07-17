<?php
session_start();
header('Content-Type: application/json');

// Include database connection
// Adjusted path assuming this file is in a subdirectory like 'api/' or 'includes/'
// and db_connect.php is in the parent directory.
include '../db_connect.php';

$response = ['success' => false, 'message' => '', 'data' => []];

if (!isset($_GET['job_card_id'])) {
    $response['message'] = 'Job Card ID is required.';
    echo json_encode($response);
    exit();
}

$job_card_id = filter_input(INPUT_GET, 'job_card_id', FILTER_VALIDATE_INT);

if ($job_card_id === false) {
    $response['message'] = 'Invalid Job Card ID.';
    echo json_encode($response);
    exit();
}

try {
    // Query to fetch parts used for the specific job card, including their prices from the inventory
    // CORRECTED: Changed 'i.unit_price' to 'i.price' for consistency with inventory table.
    $stmt = $conn->prepare("
        SELECT
            jcp.quantity_used,
            i.item_name,
            i.price, -- Changed from i.unit_price to i.price
            i.item_number
        FROM
            job_parts jcp
        JOIN
            inventory i ON jcp.item_id = i.item_id
        WHERE
            jcp.job_card_id = ?
    ");

    if (!$stmt) {
        throw new Exception("Failed to prepare statement: " . $conn->error);
    }

    $stmt->bind_param("i", $job_card_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $response['data'][] = $row;
        }
        $response['success'] = true;
    } else {
        $response['message'] = 'No parts found for this job card.';
        $response['success'] = true; // Still a success, just no data
    }

    $stmt->close();

} catch (Exception $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
    error_log("Error fetching job card parts: " . $e->getMessage());
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}

echo json_encode($response);
?>