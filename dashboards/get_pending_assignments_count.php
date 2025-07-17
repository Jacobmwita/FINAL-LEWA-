<?php
session_start();
header('Content-Type: application/json');
include __DIR__ . '/../db_connect.php';

if (!$conn) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    exit();
}

$query = "SELECT COUNT(*) AS count FROM job_cards WHERE status = 'pending'";
$stmt = $conn->prepare($query);
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    $count = $result->fetch_assoc()['count'];
    $stmt->close();
    echo json_encode(['status' => 'success', 'count' => $count]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Query preparation failed.']);
}
$conn->close();
?>