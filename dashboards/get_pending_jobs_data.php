<?php
// get_pending_jobs_data.php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once __DIR__ . '/../db_connect.php';

if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit();
}

header('Content-Type: application/json');

$response = ['success' => false, 'data' => []];

$jobs_query = "SELECT j.job_card_id, j.description, j.created_at, j.status, j.mechanic_id, j.labor_cost,
                    v.make, v.model, v.registration_number, v.vehicle_id,
                    d.full_name as driver_name
                FROM job_cards j
                JOIN vehicles v ON j.vehicle_id = v.vehicle_id
                JOIN users d ON j.driver_id = d.user_id
                WHERE j.status IN ('pending', 'in_progress', 'on_hold', 'assessment_requested')
                ORDER BY j.created_at DESC";

$jobs_stmt = $conn->prepare($jobs_query);
if ($jobs_stmt) {
    $jobs_stmt->execute();
    $jobs = $jobs_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $jobs_stmt->close();
    $response['success'] = true;
    $response['data'] = $jobs;
} else {
    $response['message'] = "Failed to prepare jobs query: " . $conn->error;
}

echo json_encode($response);
$conn->close();
exit();
?>
