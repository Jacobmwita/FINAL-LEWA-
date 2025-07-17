<?php
session_start();
header('Content-Type: application/json');

// Security Checkup
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

// Include necessary files
include '../db_connect.php';

// Validate input
if (!isset($_GET['job_card_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing job card ID.']);
    exit();
}

$job_card_id = intval($_GET['job_card_id']);
$parts = [];

try {
    // Assuming job_card_parts links to inventory for item details
    $stmt = $conn->prepare("SELECT inv.item_name, jcp.quantity_used, inv.unit_price FROM job_card_parts jcp JOIN inventory inv ON jcp.item_id = inv.item_id WHERE jcp.job_card_id = ?");
    if (!$stmt) {
        throw new Exception("Statement preparation failed: " . $conn->error);
    }
    $stmt->bind_param("i", $job_card_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $parts[] = $row;
    }

    $stmt->close();
    $conn->close();

    echo json_encode(['success' => true, 'parts' => $parts]);

} catch (Exception $e) {
    error_log("Error fetching parts: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to fetch parts.']);
    if ($conn) {
        $conn->close();
    }
}
?>
