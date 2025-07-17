<?php
// get_vehicle_details.php
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

$response = ['success' => false, 'message' => ''];

if (isset($_GET['vehicle_id'])) {
    $vehicle_id = $_GET['vehicle_id'];

    $stmt = $conn->prepare("SELECT v.*, u.full_name AS driver_name FROM vehicles v LEFT JOIN users u ON v.driver_id = u.user_id WHERE v.vehicle_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $vehicle_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $response['success'] = true;
            $response['data'] = $result->fetch_assoc();
        } else {
            $response['message'] = "Vehicle not found.";
        }
        $stmt->close();
    } else {
        $response['message'] = "Failed to prepare statement: " . $conn->error;
    }
} else {
    $response['message'] = "Vehicle ID is required.";
}

echo json_encode($response);
$conn->close();
exit();
?>
