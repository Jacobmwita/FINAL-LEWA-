<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'service_advisor') {
    header("Location: ../user_login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: service_dashboard.php?error=Invalid request method.");
    exit();
}

if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    header("Location: service_dashboard.php?error=CSRF token mismatch.");
    exit();
}

include __DIR__ . '/../db_connect.php';

if (!$conn) {
    error_log("Database connection failed in process_job_card.php: " . mysqli_connect_error());
    header("Location: service_dashboard.php?error=Database connection failed.");
    exit();
}

$registration_number = trim($_POST['registration_number']);
$description = trim($_POST['description']);
$driver_name = trim($_POST['driver_name']);
$service_advisor_id = $_SESSION['user_id'];

if (empty($registration_number) || empty($description)) {
    header("Location: service_dashboard.php?error=Vehicle registration and job description are required.");
    $conn->close();
    exit();
}

$conn->begin_transaction();

try {
    // Find or create vehicle
    $vehicle_id = null;
    $stmt_vehicle = $conn->prepare("SELECT vehicle_id FROM vehicles WHERE registration_number = ?");
    $stmt_vehicle->bind_param("s", $registration_number);
    $stmt_vehicle->execute();
    $result_vehicle = $stmt_vehicle->get_result();
    if ($result_vehicle->num_rows > 0) {
        $vehicle_id = $result_vehicle->fetch_assoc()['vehicle_id'];
    } else {
        // If vehicle doesn't exist, create a new one (minimal details)
        $insert_vehicle_sql = "INSERT INTO vehicles (registration_number, make, model, year, current_mileage, status) VALUES (?, 'Unknown', 'Unknown', NULL, 0, 'active')";
        $stmt_insert_vehicle = $conn->prepare($insert_vehicle_sql);
        $stmt_insert_vehicle->bind_param("s", $registration_number);
        if (!$stmt_insert_vehicle->execute()) {
            throw new Exception("Failed to create new vehicle: " . $stmt_insert_vehicle->error);
        }
        $vehicle_id = $conn->insert_id;
        $stmt_insert_vehicle->close();
    }
    $stmt_vehicle->close();

    // Find or create driver
    $driver_id = null;
    if (!empty($driver_name)) {
        $stmt_driver = $conn->prepare("SELECT user_id FROM users WHERE full_name = ? AND user_type = 'driver'");
        $stmt_driver->bind_param("s", $driver_name);
        $stmt_driver->execute();
        $result_driver = $stmt_driver->get_result();
        if ($result_driver->num_rows > 0) {
            $driver_id = $result_driver->fetch_assoc()['user_id'];
        } else {
            // Create new driver (assuming some default values for new users)
            $hashed_password = password_hash("password123", PASSWORD_DEFAULT); // Default password for new drivers
            $insert_driver_sql = "INSERT INTO users (full_name, email, phone_number, password, user_type, is_active, created_at) VALUES (?, ?, ?, ?, 'driver', 1, NOW())";
            $email = strtolower(str_replace(' ', '.', $driver_name)) . '@example.com'; // Placeholder email
            $phone = 'N/A'; // Placeholder phone
            $stmt_insert_driver = $conn->prepare($insert_driver_sql);
            $stmt_insert_driver->bind_param("ssss", $driver_name, $email, $phone, $hashed_password);
            if (!$stmt_insert_driver->execute()) {
                throw new Exception("Failed to create new driver: " . $stmt_insert_driver->error);
            }
            $driver_id = $conn->insert_id;
            $stmt_insert_driver->close();
        }
        $stmt_driver->close();
    } else {
        // If no driver name provided, try to find a driver associated with the vehicle if exists
        $stmt_vehicle_driver = $conn->prepare("SELECT driver_id FROM vehicles WHERE vehicle_id = ?");
        $stmt_vehicle_driver->bind_param("i", $vehicle_id);
        $stmt_vehicle_driver->execute();
        $result_vehicle_driver = $stmt_vehicle_driver->get_result();
        if ($result_vehicle_driver->num_rows > 0) {
            $existing_driver_id = $result_vehicle_driver->fetch_assoc()['driver_id'];
            if ($existing_driver_id) {
                $driver_id = $existing_driver_id;
            }
        }
        $stmt_vehicle_driver->close();
    }


    // Create job card
    $insert_job_card_sql = "INSERT INTO job_cards (vehicle_id, driver_id, description, status, created_at, created_by_user_id) VALUES (?, ?, ?, 'pending', NOW(), ?)";
    $stmt_insert_job_card = $conn->prepare($insert_job_card_sql);
    if (!$stmt_insert_job_card) {
        throw new Exception("Failed to prepare job card insert statement: " . $conn->error);
    }
    // Handle cases where driver_id might still be null (if no driver provided and no existing driver for vehicle)
    if ($driver_id === null) {
        $stmt_insert_job_card->bind_param("isii", $vehicle_id, $driver_id, $description, $service_advisor_id);
    } else {
        $stmt_insert_job_card->bind_param("isii", $vehicle_id, $driver_id, $description, $service_advisor_id);
    }
    
    if (!$stmt_insert_job_card->execute()) {
        throw new Exception("Failed to create job card: " . $stmt_insert_job_card->error);
    }
    $stmt_insert_job_card->close();

    $conn->commit();
    header("Location: service_dashboard.php?success=Job card created successfully!");
    exit();

} catch (Exception $e) {
    $conn->rollback();
    error_log("Job card creation failed: " . $e->getMessage());
    header("Location: service_dashboard.php?error=" . urlencode("Failed to create job card: " . $e->getMessage()));
    exit();
} finally {
    $conn->close();
}
?>