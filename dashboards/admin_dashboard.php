<?php

if (session_status() == PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        'domain' => $_SERVER['HTTP_HOST'],
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// BASE_URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
define('BASE_URL', "{$protocol}://{$host}/lewa");

//CHECKING IF THE USER IS LOGGED IN
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/user_login.php');
    exit();
}

$allowed_admin_types = [
    'workshop_manager',
    'admin',
    'administrator',
    'manager',
    'mechanic', // Added mechanic to allowed types for job updates
    'service_advisor' // Added service_advisor to allowed types for job updates
];

if (!isset($_SESSION['user_type']) || !in_array(strtolower($_SESSION['user_type']), array_map('strtolower', $allowed_admin_types))) {
    error_log("Unauthorized access attempt to admin dashboard by user: " . ($_SESSION['username'] ?? 'unknown'));
    header('Location: ' . BASE_URL . '/user_login.php?error=no_access');
    exit();
}


require_once __DIR__ . '/../db_connect.php';

// CHECK CONNECTIONS WITH THE DATABASE
if (!isset($conn) || $conn->connect_error) {
    error_log("Database connection failed: " . ($conn->connect_error ?? 'Unknown error'));
    die("<h1>Service Unavailable</h1><p>Database connection failed. Please try again later.</p>");
}

//CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Helper function to fetch mechanics
function getMechanics($conn) {
    $mechanics = [];
    $result = $conn->query("SELECT user_id, full_name FROM users WHERE LOWER(user_type) = 'mechanic'");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $mechanics[] = $row;
        }
    }
    return $mechanics;
}
$mechanics_lookup = [];
$all_mechanics = getMechanics($conn);
foreach ($all_mechanics as $mechanic) {
    $mechanics_lookup[$mechanic['user_id']] = $mechanic['full_name'];
}

// Helper function to fetch Service Advisors
function getServiceAdvisors($conn) {
    $advisors = [];
    $result = $conn->query("SELECT user_id, full_name FROM users WHERE LOWER(user_type) = 'service_advisor'");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $advisors[] = $row;
        }
    }
    return $advisors;
}
$service_advisors_lookup = [];
$all_service_advisors = getServiceAdvisors($conn);
foreach ($all_service_advisors as $advisor) {
    $service_advisors_lookup[$advisor['user_id']] = $advisor['full_name'];
}


//AJAX HANDLING (for stats and vehicle details)
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    $response = [];

    try {
        switch ($_GET['ajax']) {
            case 'stats':
                // GET THE DASHBOARD STATISTICS
                $stats = [];

                // DRIVERS
                $result = $conn->query("SELECT COUNT(*) AS count FROM users WHERE LOWER(user_type) = 'driver'");
                $stats['total_drivers'] = ($result && $result->num_rows > 0) ? $result->fetch_assoc()['count'] : 0;

                // VEHICLES
                $result = $conn->query("SELECT COUNT(*) AS count FROM vehicles");
                $stats['total_vehicles'] = ($result && $result->num_rows > 0) ? $result->fetch_assoc()['count'] : 0;

                // JOBS
                $result = $conn->query("SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN status IN ('in_progress', 'in progress', 'assigned', 'assessment_requested', 'waiting_for_parts') THEN 1 ELSE 0 END) AS in_progress,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed
                    FROM job_cards");

                if ($result && $result->num_rows > 0) {
                    $job_stats = $result->fetch_assoc();
                    $stats = array_merge($stats, $job_stats);
                } else {
                    $stats['total'] = 0;
                    $stats['pending'] = 0;
                    $stats['in_progress'] = 0;
                    $stats['completed'] = 0;
                }

                //INVENTORIES
                $result = $conn->query("SELECT COUNT(*) AS total_items, SUM(quantity) AS total_quantity FROM inventory");
                if ($result && $row = $result->fetch_assoc()) {
                    $stats['total_inventory_items'] = $row['total_items'] ?? 0;
                    $stats['total_inventory_quantity'] = $row['total_quantity'] ?? 0;
                } else {
                    $stats['total_inventory_items'] = 0;
                    $stats['total_inventory_quantity'] = 0;
                }

                $response = $stats;
                break;
            case 'mechanics':
                $response = getMechanics($conn);
                break;
            case 'service_advisors': // New AJAX endpoint for service advisors
                $response = getServiceAdvisors($conn);
                break;
            case 'vehicle_details':
                if (empty($_GET['vehicle_id'])) {
                    throw new Exception("Vehicle ID is required.");
                }
                $vehicle_id = $_GET['vehicle_id'];
                $stmt = $conn->prepare("SELECT v.*, u.full_name AS driver_name FROM vehicles v LEFT JOIN users u ON v.driver_id = u.user_id WHERE v.vehicle_id = ?");
                $stmt->bind_param("i", $vehicle_id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    $response['success'] = true;
                    $response['data'] = $result->fetch_assoc();
                } else {
                    $response['success'] = false;
                    $response['message'] = "Vehicle not found.";
                }
                $stmt->close();
                break;
            default:
                throw new Exception('Invalid AJAX request');
        }
    } catch (Exception $e) {
        $response = ['error' => $e->getMessage()];
    }

    echo json_encode($response);
    $conn->close();
    exit();
}

//FORMS SUBMISSIONS (for job card updates and parts assignment)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    // CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $response['message'] = 'Invalid CSRF token';
        echo json_encode($response);
        exit();
    }

    // Check if the current user has permission to perform job updates
    // This ensures all allowed roles (admin, manager, mechanic, service_advisor) can update
    if (!in_array(strtolower($_SESSION['user_type']), array_map('strtolower', $allowed_admin_types))) {
        $response['message'] = 'Unauthorized to perform this action.';
        echo json_encode($response);
        exit();
    }

    try {
        switch ($_POST['action'] ?? '') {
            case 'update_job_status':
                if (empty($_POST['job_id']) || empty($_POST['status'])) {
                    throw new Exception("Job ID and status are required");
                }

                $job_id = $_POST['job_id'];
                $status = $_POST['status'];
                $mechanic_id = $_POST['mechanic_id'] === '' ? null : (int)$_POST['mechanic_id'];
                $labor_cost = isset($_POST['labor_cost']) && is_numeric($_POST['labor_cost']) ? (float)$_POST['labor_cost'] : 0.00;
                $service_advisor_id = isset($_POST['service_advisor_id']) && $_POST['service_advisor_id'] !== '' ? (int)$_POST['service_advisor_id'] : null;
                // $last_updated_timestamp = $_POST['last_updated_timestamp'] ?? null; // For optimistic locking - REMOVED

                $conn->begin_transaction(); // Start transaction

               

                $update_query = "UPDATE job_cards SET status = ?, labor_cost = ?";
                $types = "sd"; // For status (string) and labor_cost (double/decimal)

                // Add completed_at logic
                if ($status === 'completed') {
                    $update_query .= ", completed_at = NOW()";
                } else {
                    $update_query .= ", completed_at = NULL"; // Clear completion date if not completed
                }

                // Add mechanic_id logic
                if ($mechanic_id !== null) {
                    $update_query .= ", assigned_to_mechanic_id = ?"; // Corrected column name
                    $types .= "i"; // 'i' for integer type: mechanic_id
                } else {
                    $update_query .= ", assigned_to_mechanic_id = NULL"; // Set to NULL if no mechanic is selected
                }

                // Add service_advisor_id logic
                if ($service_advisor_id !== null) {
                    $update_query .= ", service_advisor_id = ?";
                    $types .= "i"; // 'i' for integer type: service_advisor_id
                } else {
                    $update_query .= ", service_advisor_id = NULL"; // Set to NULL if no service advisor is selected
                }


                $update_query .= " WHERE job_card_id = ?"; // No timestamp check here, as we did FOR UPDATE above
                $types .= "i"; // For job_card_id

                $stmt = $conn->prepare($update_query);

                if (!$stmt) {
                    throw new Exception("Failed to prepare update statement: " . $conn->error);
                }

                // Dynamically bind parameters based on whether mechanic_id and service_advisor_id are present
                $params = [$status, $labor_cost];
                if ($mechanic_id !== null) {
                    $params[] = $mechanic_id;
                }
                if ($service_advisor_id !== null) {
                    $params[] = $service_advisor_id;
                }
                $params[] = $job_id;

                // Use call_user_func_array to bind parameters dynamically
                $bind_names[] = $types;
                for ($i = 0; $i < count($params); $i++) {
                    $bind_name = 'bind' . $i;
                    $$bind_name = $params[$i];
                    $bind_names[] = &$$bind_name;
                }
                call_user_func_array([$stmt, 'bind_param'], $bind_names);

                if ($stmt->execute()) {
                    $conn->commit(); // Commit transaction
                    $response['success'] = true;
                    $response['message'] = "Job status, mechanic, labor cost, and service advisor updated successfully";
                } else {
                    $conn->rollback(); // Rollback on error
                    throw new Exception("Failed to update job status, mechanic, labor cost, or service advisor: " . $stmt->error);
                }
                $stmt->close();
                break;

            case 'assign_job_with_parts':
                $job_card_id = $_POST['job_id'] ?? null;
                $mechanic_id = $_POST['mechanic_id'] ?? null;
                $labor_cost = isset($_POST['labor_cost']) && is_numeric($_POST['labor_cost']) ? (float)$_POST['labor_cost'] : 0.00;
                $service_advisor_id = isset($_POST['service_advisor_id']) && $_POST['service_advisor_id'] !== '' ? (int)$_POST['service_advisor_id'] : null;
                $parts_requested = $_POST['parts'] ?? [];
                // $last_updated_timestamp = $_POST['last_updated_timestamp'] ?? null; // For optimistic locking - REMOVED


                if (!$job_card_id) {
                    throw new Exception("Job Card ID is required for assignment.");
                }

                $conn->begin_transaction();

                

                try {
                    // Update job card status, assign mechanic, labor cost, and service advisor
                    $update_query = "UPDATE job_cards SET status = 'assigned', assigned_to_mechanic_id = ?, labor_cost = ?, service_advisor_id = ? WHERE job_card_id = ?";
                    $stmt = $conn->prepare($update_query);
                    if (!$stmt) {
                        throw new Exception("Failed to prepare job update statement: " . $conn->error);
                    }
                    $stmt->bind_param("idii", $mechanic_id, $labor_cost, $service_advisor_id, $job_card_id); // 'i' for mechanic_id, 'd' for labor_cost, 'i' for service_advisor_id, 'i' for job_card_id
                    if (!$stmt->execute()) {
                        throw new Exception("Failed to update job card: " . $stmt->error);
                    }
                    $stmt->close();

                    // Process parts requests
                    foreach ($parts_requested as $item_id => $part_data) {
                        if (isset($part_data['selected']) && $part_data['selected'] == '1') {
                            $quantity_requested = (int)($part_data['quantity'] ?? 0);
                            $unit_price_at_assignment = (float)($part_data['unit_price'] ?? 0.00);
                            $total_cost_at_assignment = $quantity_requested * $unit_price_at_assignment;

                            if ($quantity_requested <= 0 || $unit_price_at_assignment < 0) {
                                throw new Exception("Invalid quantity or unit price for part ID: " . $item_id);
                            }

                            // Check current stock and lock row
                            $stock_stmt = $conn->prepare("SELECT quantity, item_name, item_number FROM inventory WHERE item_id = ? FOR UPDATE");
                            if (!$stock_stmt) {
                                throw new Exception("Failed to prepare stock check statement: " . $conn->error);
                            }
                            $stock_stmt->bind_param("i", $item_id);
                            $stock_stmt->execute();
                            $stock_result = $stock_stmt->get_result();
                            $part_info = $stock_result->fetch_assoc();
                            $current_stock = $part_info['quantity'] ?? 0;
                            $item_name = $part_info['item_name'] ?? 'Unknown Part';
                            $item_number = $part_info['item_number'] ?? 'N/A';
                            $stock_stmt->close();

                            if ($current_stock < $quantity_requested) {
                                throw new Exception("Insufficient stock for " . htmlspecialchars($item_name) . ". Available: " . $current_stock . ", Requested: " . $quantity_requested . ".");
                            }

                            // Deduct from inventory
                            $deduct_stmt = $conn->prepare("UPDATE inventory SET quantity = quantity - ? WHERE item_id = ?");
                            if (!$deduct_stmt) {
                                throw new Exception("Failed to prepare deduction statement: " . $conn->error);
                            }
                            $deduct_stmt->bind_param("ii", $quantity_requested, $item_id);
                            if (!$deduct_stmt->execute()) {
                                throw new Exception("Failed to deduct part from inventory: " . $deduct_stmt->error);
                            }
                            $deduct_stmt->close();

                            // Record part usage in job_parts (corrected table name and added price fields)
                            $record_stmt = $conn->prepare("INSERT INTO job_parts (job_card_id, item_id, quantity_used, unit_price_at_assignment, total_cost_at_assignment, assigned_by_user_id) VALUES (?, ?, ?, ?, ?, ?)");
                            if (!$record_stmt) {
                                throw new Exception("Failed to prepare part usage record statement: " . $conn->error);
                            }
                            $record_stmt->bind_param("iidddi", $job_card_id, $item_id, $quantity_requested, $unit_price_at_assignment, $total_cost_at_assignment, $_SESSION['user_id']);
                            if (!$record_stmt->execute()) {
                                throw new Exception("Failed to record part usage: " . $record_stmt->error);
                            }
                            $record_stmt->close();

                            // Log parts usage in job_card_updates
                            $log_description = "Assigned {$quantity_requested} x '" . htmlspecialchars($item_name) . "' (Item #{$item_number}) for KES " . number_format($total_cost_at_assignment, 2) . " to job.";
                            $sql_log = "INSERT INTO job_card_updates (job_card_id, mechanic_id, update_type, description) VALUES (?, ?, 'Parts Assignment', ?)";
                            if ($stmt_log = $conn->prepare($sql_log)) {
                                $stmt_log->bind_param("iis", $job_card_id, $_SESSION['user_id'], $log_description);
                                if (!$stmt_log->execute()) {
                                    error_log("Failed to log parts assignment for job {$job_card_id}: " . $stmt_log->error);
                                }
                                $stmt_log->close();
                            }
                        }
                    }

                    $conn->commit();
                    $response['success'] = true;
                    $response['message'] = "Job assigned and parts requested successfully!";

                } catch (Exception $e) {
                    $conn->rollback();
                    $response['message'] = $e->getMessage();
                }
                break;
            default:
                throw new Exception("Invalid action");
        }
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
    }

    echo json_encode($response);
    $conn->close();
    exit();
}


$stats = [
    'total_drivers' => 0,
    'total_vehicles' => 0,
    'total_jobs' => 0,
    'pending_jobs' => 0,
    'in_progress_jobs' => 0,
    'completed_jobs' => 0,
    'total_inventory_items' => 0,
    'total_inventory_quantity' => 0
];

// COUNT THE DRIVERS IN THE SYSTEM
$result = $conn->query("SELECT COUNT(*) AS count FROM users WHERE LOWER(user_type) = 'driver'");
if ($result && $result->num_rows > 0) $stats['total_drivers'] = $result->fetch_assoc()['count'];

//COUNT VEHICLES
$result = $conn->query("SELECT COUNT(*) AS count FROM vehicles");
if ($result && $result->num_rows > 0) $stats['total_vehicles'] = $result->fetch_assoc()['count'];

//GET THE JOB STATISTICS
$result = $conn->query("SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
    SUM(CASE WHEN status IN ('in_progress', 'in progress', 'assigned', 'assessment_requested', 'waiting_for_parts') THEN 1 ELSE 0 END) AS in_progress,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed
    FROM job_cards");

if ($result && $result->num_rows > 0) {
    $job_stats = $result->fetch_assoc();
    $stats['total_jobs'] = $job_stats['total'] ?? 0;
    $stats['pending_jobs'] = $job_stats['pending'] ?? 0;
    $stats['in_progress_jobs'] = $job_stats['in_progress'] ?? 0;
    $stats['completed_jobs'] = $job_stats['completed'] ?? 0;
}

//GET INVENTORIES(PARTS)
$result = $conn->query("SELECT COUNT(*) AS total_items, SUM(quantity) AS total_quantity FROM inventory");
if ($result && $row = $result->fetch_assoc()) {
    $stats['total_inventory_items'] = $row['total_items'] ?? 0;
    $stats['total_inventory_quantity'] = $row['total_quantity'] ?? 0;
}


$recent_activities = [];

// Fetch issue_description, mechanic_id, service_advisor_id, labor_cost for pre-filling the modal
// Also fetch last_updated_timestamp for optimistic locking
$result = $conn->query("SELECT jc.*, v.registration_number, v.make, v.model, v.year, v.color, v.v_milage AS v_mileage, v.vehicle_id, completed_at, u.full_name AS driver_name, jc.description AS issue_description, jc.assigned_to_mechanic_id, jc.labor_cost, jc.service_advisor_id, jc.last_updated_timestamp
                            FROM job_cards jc
                            LEFT JOIN vehicles v ON jc.vehicle_id = v.vehicle_id
                            LEFT JOIN users u ON jc.created_by_user_id = u.user_id
                            ORDER BY jc.created_at DESC LIMIT 5");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $recent_activities[] = $row;
    }
}

// Fetch all spare parts for the modal's parts selection, including price
$spare_parts = [];
$spare_parts_query = "SELECT item_id, item_name, quantity, price FROM inventory WHERE quantity > 0 ORDER BY item_name ASC";
$spare_parts_stmt = $conn->prepare($spare_parts_query);
if ($spare_parts_stmt) {
    $spare_parts_stmt->execute();
    $spare_parts = $spare_parts_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $spare_parts_stmt->close();
} else {
    error_log("Failed to prepare spare parts query: " . $conn->error);
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lewa Workshop - Admin Dashboard</title>


    <style>
        *, *::before, *::after {
            box-sizing: border-box;
        }

        :root {
            --primary: #3498db;
            --secondary: #2980b9;
            --success: #2ecc71;
            --danger: #e74c3c;
            --warning: #f39c12;
            --info: #17a2b8; /* Added for new status */
            --light: #ecf0f1;
            --dark: #2c3e50;
            --text-color: #333;
            --border-color: #ddd;
            --input-bg: #f9f9f9;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f5f5f5;
            color: var(--text-color);
        }

        .dashboard-container {
            display: grid;
            grid-template-columns: 250px 1fr;
            min-height: 100vh;
        }

        .sidebar {
            background-color: var(--dark);
            color: white;
            padding: 20px;
            flex-shrink: 0;
        }

        .sidebar h2 {
            text-align: center;
            color: var(--primary);
            margin-bottom: 30px;
        }

        .sidebar nav ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .sidebar nav ul li {
            margin-bottom: 10px;
        }

        .sidebar nav ul li a {
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 15px;
            border-radius: 5px;
            transition: background-color 0.3s ease;
        }

        .sidebar nav ul li a:hover,
        .sidebar nav ul li a.active {
            background-color: var(--secondary);
        }

        .main-content {
            padding: 20px;
            overflow-x: hidden;
        }

        h1 {
            color: var(--dark);
            margin-bottom: 30px;
        }

        .stat-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .stat-card h3 {
            margin-top: 0;
            color: var(--dark);
            font-size: 1rem;
        }

        .stat-card .value {
            font-size: 2rem;
            font-weight: bold;
            color: var(--primary);
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }


        .action-card {
            background: white;
            border-radius: 8px;
            padding: 25px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s ease-in-out;
        }

        .action-card:hover {
            transform: translateY(-5px);
        }

        .action-card h3 {
            color: var(--dark);
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 1.5rem;
            border-bottom: 2px solid var(--primary);
            padding-bottom: 10px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.95rem;
        }

        input[type="text"],
        input[type="email"],
        input[type="number"],
        select,
        textarea { /* Added textarea */
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background-color: var(--input-bg);
            font-size: 1rem;
            color: var(--text-color);
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        input[type="text"]:focus,
        input[type="email"]:focus,
        input[type="number"]:focus,
        select:focus,
        textarea:focus { /* Added textarea */
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
        }

        button {
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 6px;
            cursor: pointer;
            transition: background-color 0.3s ease, transform 0.2s ease;
            font-size: 1rem;
            font-weight: bold;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        button:hover {
            background-color: var(--secondary);
            transform: translateY(-2px);
        }
        button:active {
            transform: translateY(0);
            background-color: #2471a3;
        }


        .recent-activities, .new-user-registration-section {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            overflow-x: auto;
            margin-top: 30px;
        }
        .new-user-registration-section h2 {
            color: var(--dark);
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 1.5rem;
            border-bottom: 2px solid var(--primary);
            padding-bottom: 10px;
        }

        table {
            width: 100%;
            min-width: 600px;
            border-collapse: collapse;
            margin-top: 15px;
        }

        th,
        td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        th {
            background-color: var(--light);
            color: var(--dark);
            font-weight: 600;
        }

        td {
            color: #555;
        }

        .status-pending {
            color: var(--warning);
            font-weight: bold;
        }

        .status-in-progress {
            color: var(--primary);
            font-weight: bold;
        }

        .status-completed {
            color: var(--success);
            font-weight: bold;
        }
        .status-on-hold {
            color: var(--danger);
            font-weight: bold;
        }
        .status-assigned {
            color: #2196F3;
            font-weight: bold;
        }
        .status-assessment_requested {
            color: var(--info);
            font-weight: bold;
        }
        .status-waiting_for_parts {
            color: #9C27B0;
            font-weight: bold;
        }

        /* Styles for the new manage button */
        .manage-job-button {
            background-color: var(--primary);
            color: white;
            padding: 6px 10px;
            border: none;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.85em;
            cursor: pointer;
            transition: background-color 0.3s ease;
            margin-right: 5px;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .manage-job-button:hover {
            background-color: var(--secondary);
        }


        .view-details-button, .register-user-button {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background-color: var(--primary);
            color: white;
            padding: 6px 10px;
            border: none;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.85em;
            cursor: pointer;
            transition: background-color 0.3s ease;
            margin-right: 5px;
            white-space: nowrap;
        }

        .view-details-button:hover, .register-user-button:hover {
            background-color: var(--secondary);
        }

        /* Modal styles for jobManagementModal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.6);
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .modal-content {
            background-color: #fefefe;
            margin: auto;
            padding: 30px;
            border: 1px solid #888;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            width: 90%;
            max-width: 800px;
            position: relative;
            max-height: 90vh;
            overflow-y: auto;
        }

        .close-button {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            position: absolute;
            top: 15px;
            right: 25px;
        }

        .close-button:hover,
        .close-button:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }

        .modal .form-group {
            margin-bottom: 15px;
        }

        .modal label {
            font-weight: bold;
            margin-bottom: 5px;
        }

        .modal input[type="text"],
        .modal input[type="email"],
        .modal input[type="number"],
        .modal select,
        .modal textarea {
            width: 100%;
            padding: 10px;
            margin-top: 5px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .modal button {
            margin-top: 15px;
            width: auto;
            padding: 10px 20px;
        }

        .message-container {
            margin-top: 20px;
            padding: 10px;
            border-radius: 5px;
            font-weight: bold;
            display: none;
            border: 1px solid transparent;
        }

        .message-container.success {
            background-color: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }

        .message-container.error {
            background-color: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }

        /* Styles for parts selection in modal */
        .parts-selection {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid var(--border-color);
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
        }

        .part-item {
            display: flex;
            flex-wrap: wrap; /* Allow wrapping for smaller screens */
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            padding: 5px;
            background-color: var(--light);
            border-radius: 3px;
        }

        .part-item:nth-child(even) {
            background-color: #e9ecef;
        }

        .part-item label {
            margin-bottom: 0;
            font-weight: normal;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-grow: 1; /* Allow label to grow */
        }

        .part-item .part-inputs {
            display: flex;
            gap: 5px;
            align-items: center;
        }

        .part-item input[type="number"] {
            width: 70px;
            padding: 5px;
            border-radius: 3px;
            border: 1px solid var(--border-color);
            box-sizing: border-box;
        }
        .part-item .calculated-total {
            font-weight: bold;
            margin-left: 10px;
            min-width: 80px; /* Ensure space for total */
            text-align: right;
        }


        @media (max-width: 768px) {
            .dashboard-container {
                grid-template-columns: 1fr;
            }

            .sidebar {
                width: 100%;
                padding-bottom: 10px;
            }

            .sidebar h2 {
                margin-bottom: 15px;
            }

            .sidebar nav ul {
                display: flex;
                flex-wrap: wrap;
                justify-content: center;
                gap: 5px;
            }
            .sidebar nav ul li {
                margin-bottom: 0;
            }
            .sidebar nav ul li a {
                padding: 8px 10px;
                font-size: 0.85em;
                text-align: center;
                flex-direction: column;
                gap: 3px;
            }
            .sidebar nav ul li a i {
                margin-right: 0;
            }


            .main-content {
                padding: 15px;
            }

            h1 {
                font-size: 1.8rem;
                text-align: center;
            }

            .stat-cards,
            .quick-actions {
                grid-template-columns: 1fr;
            }

            .stat-card {
                padding: 15px;
            }

            .stat-card .value {
                font-size: 1.8rem;
            }

            .action-card {
                padding: 20px;
            }

            table {
                min-width: 100%;
            }

            th, td {
                padding: 10px;
                font-size: 0.9em;
            }

            .manage-job-button, .view-details-button, .register-user-button {
                padding: 5px 8px;
                font-size: 0.8em;
                margin-right: 3px;
            }

            .modal-content {
                width: 95%;
                padding: 15px;
            }
            .part-item .part-inputs {
                flex-direction: column; /* Stack inputs vertically on small screens */
                align-items: flex-end;
            }
            .part-item input[type="number"] {
                width: 100%; /* Full width for stacked inputs */
            }
        }

        @media (max-width: 480px) {
            .sidebar nav ul li a {
                font-size: 0.8em;
                padding: 6px 8px;
            }
            .sidebar h2 {
                font-size: 1.5rem;
            }
            .main-content {
                padding: 10px;
            }
            h1 {
                font-size: 1.5rem;
            }
            .stat-card .value {
                font-size: 1.5rem;
            }
            .action-card h3 {
                font-size: 1.2rem;
            }
            input[type="text"], input[type="email"], input[type="number"], select, button {
                font-size: 0.9rem;
            }
        }

        @media (min-width: 1200px) {
            .main-content {
                max-width: 1400px;
                margin-left: auto;
                margin-right: auto;
            }
            .dashboard-container {
                gap: 30px;
            }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>

<body>
    <div class="dashboard-container">
        <div class="sidebar">
            <h2>Lewa Workshop</h2>
            <p>Welcome, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?></p>

            <nav>
                <ul>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/admin_dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/create_job_card.php"><i class="fas fa-clipboard-list"></i> View/Edit Job Cards</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/reports.php"><i class="fas fa-chart-line"></i> Generate Reports</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/manage_users.php"><i class="fas fa-users-cog"></i> Manage User Roles</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/drivers.php"><i class="fas fa-users-cog"></i> Drivers</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/inventory.php"><i class="fas fa-warehouse"></i> Monitor Inventory</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/add_vehicle.php"><i class="fas fa-car-alt"></i> Vehicles</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </nav>
        </div>

        <div class="main-content">
            <h1>Admin Dashboard Overview</h1>

            <div class="stat-cards">
                <div class="stat-card">
                    <h3>Total Drivers</h3>
                    <div class="value" id="stat-drivers"><?php echo $stats['total_drivers']; ?></div>
                </div>

                <div class="stat-card">
                    <h3>Total Vehicles</h3>
                    <div class="value" id="stat-vehicles"><?php echo $stats['total_vehicles']; ?></div>
                </div>

                <div class="stat-card">
                    <h3>Total Jobs</h3>
                    <div class="value" id="stat-jobs"><?php echo $stats['total_jobs']; ?></div>
                </div>

                <div class="stat-card">
                    <h3>Pending Jobs</h3>
                    <div class="value" id="stat-pending"><?php echo $stats['pending_jobs']; ?></div>
                </div>

                <div class="stat-card">
                    <h3>In Progress Jobs</h3>
                    <div class="value" id="stat-in-progress"><?php echo $stats['in_progress_jobs']; ?></div>
                </div>

                <div class="stat-card">
                    <h3>Completed Jobs</h3>
                    <div class="value" id="stat-completed"><?php echo $stats['completed_jobs']; ?></div>
                </div>

                <div class="stat-card">
                    <h3>Total Inventory Items</h3>
                    <div class="value" id="stat-inventory-items"><?php echo $stats['total_inventory_items']; ?></div>
                </div>

                <div class="stat-card">
                    <h3>Total Inventory Quantity</h3>
                    <div class="value" id="stat-inventory-quantity"><?php echo $stats['total_inventory_quantity']; ?></div>
                </div>
            </div>

            <div class="quick-actions">
                <div class="action-card new-user-registration-section">
                    <h3>Register New User</h3>
                    <p>Click the button below to register a new user into the system.</p>
                    <a href="<?php echo BASE_URL; ?>/user_registration.php" class="button register-user-button"><i class="fas fa-user-plus"></i> Register User</a>
                </div>

            </div>

            <div class="recent-activities">
                <h2>Recent Job Card Activities</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Job ID</th>
                            <th>Vehicle</th>
                            <th>Driver</th>
                            <th>Issue</th>
                            <th>Status</th>
                            <th>Assigned Mechanic</th>
                            <th>Service Advisor</th>
                            <th>Labor Cost</th>
                            <th>Completed At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_activities)) : ?>
                            <tr>
                                <td colspan="10">No recent job card activities.</td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ($recent_activities as $activity) :
                                $mechanic_name = 'N/A';
                                if (!empty($activity['assigned_to_mechanic_id']) && isset($mechanics_lookup[$activity['assigned_to_mechanic_id']])) {
                                    $mechanic_name = htmlspecialchars($mechanics_lookup[$activity['assigned_to_mechanic_id']]);
                                }
                                $service_advisor_name = 'N/A';
                                if (!empty($activity['service_advisor_id']) && isset($service_advisors_lookup[$activity['service_advisor_id']])) {
                                    $service_advisor_name = htmlspecialchars($service_advisors_lookup[$activity['service_advisor_id']]);
                                }
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($activity['job_card_id']); ?></td>
                                    <td><?php echo htmlspecialchars(($activity['registration_number'] ?? 'N/A') . ' (' . ($activity['make'] ?? 'N/A') . ' ' . ($activity['model'] ?? 'N/A') . ')'); ?></td>
                                    <td>
                                        <?php
                                        if (!empty($activity['driver_name'])) {
                                            echo htmlspecialchars($activity['driver_name']);
                                        } elseif (!empty($activity['created_by_user__id'])) {
                                            echo 'Driver Not Found (ID: ' . htmlspecialchars($activity['created_by_user_id']) . ')';
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($activity['issue_description']); ?></td>
                                    <td><span class="status-<?php echo str_replace(' ', '-', strtolower($activity['status'])); ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $activity['status']))); ?></span></td>
                                    <td><?php echo $mechanic_name; ?></td>
                                    <td><?php echo $service_advisor_name; ?></td>
                                    <td><?php echo htmlspecialchars(number_format($activity['labor_cost'] ?? 0.00, 2)); ?></td>
                                    <td><?php echo $activity['completed_at'] ? htmlspecialchars(date('Y-m-d H:i', strtotime($activity['completed_at']))) : 'N/A'; ?></td>
                                    <td>
                                        <button class="manage-job-button"
                                            data-job-id="<?php echo htmlspecialchars($activity['job_card_id']); ?>"
                                            data-current-status="<?php echo htmlspecialchars($activity['status']); ?>"
                                            data-assigned-mechanic-id="<?php echo htmlspecialchars((string)($activity['assigned_to_mechanic_id'] ?? '')); ?>"
                                            data-issue-description="<?php echo htmlspecialchars($activity['issue_description']); ?>"
                                            data-vehicle-id="<?php echo htmlspecialchars($activity['vehicle_id']); ?>"
                                            data-labor-cost="<?php echo htmlspecialchars((string)($activity['labor_cost'] ?? 0.00)); ?>"
                                            data-service-advisor-id="<?php echo htmlspecialchars((string)($activity['service_advisor_id'] ?? '')); ?>"
                                            data-vehicle-reg="<?php echo htmlspecialchars($activity['registration_number'] ?? 'N/A'); ?>"
                                            data-vehicle-make="<?php echo htmlspecialchars($activity['make'] ?? 'N/A'); ?>"
                                            data-vehicle-model="<?php echo htmlspecialchars($activity['model'] ?? 'N/A'); ?>"
                                            data-vehicle-year="<?php echo htmlspecialchars($activity['year'] ?? 'N/A'); ?>"
                                            data-vehicle-color="<?php echo htmlspecialchars($activity['color'] ?? 'N/A'); ?>"
                                            data-vehicle-milage="<?php echo htmlspecialchars($activity['v_milage'] ?? 'N/A'); ?>"
                                            data-driver-name="<?php echo htmlspecialchars($activity['driver_name'] ?? 'N/A'); ?>"
                                            data-last-updated-timestamp="<?php echo htmlspecialchars($activity['last_updated_timestamp'] ?? ''); ?>">
                                            <i class="fas fa-cog"></i> Manage
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Job Management Modal (Copied from service_dashboard.php) -->
    <div id="jobManagementModal" class="modal">
        <div class="modal-content">
            <span class="close-button">&times;</span>
            <h2>Manage Job Card</h2>
            <form id="updateJobCardForm" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="update_job_status"> <!-- Default action -->
                <input type="hidden" id="modalJobCardId" name="job_id">
                <input type="hidden" id="modalVehicleId" name="vehicle_id">
                <input type="hidden" id="modalLastUpdatedTimestamp" name="last_updated_timestamp"> <!-- For optimistic locking - KEPT FOR JS SIDE BUT NOT USED IN PHP FOR NOW -->


                <div class="form-group">
                    <label for="modalVehicleInfo">Vehicle (Reg No. and Model):</label>
                    <input type="text" id="modalVehicleInfo" class="form-control" readonly>
                </div>

                <!-- Detailed Vehicle Info Display -->
                <div class="form-group">
                    <label for="modal-detail-reg-number">Registration Number:</label>
                    <input type="text" id="modal-detail-reg-number" class="form-control" readonly>
                </div>
                <div class="form-group">
                    <label for="modal-detail-make">Make:</label>
                    <input type="text" id="modal-detail-make" class="form-control" readonly>
                </div>
                <div class="form-group">
                    <label for="modal-detail-model">Model:</label>
                    <input type="text" id="modal-detail-model" class="form-control" readonly>
                </div>
                <div class="form-group">
                    <label for="modal-detail-year">Year:</label>
                    <input type="text" id="modal-detail-year" class="form-control" readonly>
                </div>
                <div class="form-group">
                    <label for="modal-detail-color">Color:</label>
                    <input type="text" id="modal-detail-color" class="form-control" readonly>
                </div>
                <div class="form-group">
                    <label for="modal-detail-mileage">Mileage:</label>
                    <input type="text" id="modal-detail-mileage" class="form-control" readonly>
                </div>
                <div class="form-group">
                    <label for="modal-detail-driver">Driver:</label>
                    <input type="text" id="modal-detail-driver" class="form-control" readonly>
                </div>
                <!-- End Detailed Vehicle Info Display -->

                <div class="form-group">
                    <label for="modalJobDescription">Job Description:</label>
                    <textarea id="modalJobDescription" class="form-control" rows="3" readonly></textarea>
                </div>

                <div class="form-group">
                    <label for="modalStatusSelect">Status:</label>
                    <select name="status" id="modalStatusSelect" class="form-control" required>
                        <option value="pending">Pending</option>
                        <option value="assigned">Assigned</option>
                        <option value="in_progress">In Progress</option>
                        <option value="completed">Completed</option>
                        <option value="on_hold">On Hold</option>
                        <option value="assessment_requested">Assessment Requested</option>
                        <option value="waiting_for_parts">Waiting for Parts</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="modalMechanicSelect">Assign Mechanic:</label>
                    <select name="mechanic_id" id="modalMechanicSelect" class="form-control">
                        <option value="">Select Mechanic</option>
                        <?php foreach ($all_mechanics as $mechanic): ?>
                            <option value="<?php echo htmlspecialchars($mechanic['user_id']); ?>">
                                <?php echo htmlspecialchars($mechanic['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="modalServiceAdvisor">Assign Service Advisor:</label>
                    <select id="modalServiceAdvisor" name="service_advisor_id" class="form-control">
                        <option value="">-- Unassigned --</option>
                        <?php foreach ($all_service_advisors as $advisor) : ?>
                            <option value="<?php echo htmlspecialchars($advisor['user_id']); ?>">
                                <?php echo htmlspecialchars($advisor['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="modalLaborCost">Labor Cost (KSh):</label>
                    <input type="number" id="modalLaborCost" name="labor_cost" class="form-control" step="0.01" min="0" value="0.00">
                </div>

                <div class="form-group">
                    <label>Required Spare Parts:</label>
                    <div class="parts-selection">
                        <?php if (count($spare_parts) > 0): ?>
                            <?php foreach ($spare_parts as $part): ?>
                                <div class="part-item">
                                    <label>
                                        <input type="checkbox" name="parts[<?php echo htmlspecialchars($part['item_id']); ?>][selected]"
                                                value="1"
                                                data-part-id="<?php echo htmlspecialchars($part['item_id']); ?>"
                                                data-max-quantity="<?php echo htmlspecialchars($part['quantity']); ?>"
                                                data-unit-price="<?php echo htmlspecialchars(number_format($part['price'], 2, '.', '')); ?>">
                                        <?php echo htmlspecialchars($part['item_name']); ?> (In Stock: <?php echo htmlspecialchars($part['quantity']); ?> @ KES <?php echo htmlspecialchars(number_format($part['price'], 2)); ?>)
                                    </label>
                                    <div class="part-inputs">
                                        <input type="number"
                                                name="parts[<?php echo htmlspecialchars($part['item_id']); ?>][quantity]"
                                                value="1" min="1"
                                                max="<?php echo htmlspecialchars($part['quantity']); ?>"
                                                class="form-control part-quantity-input"
                                                style="display: none;">
                                        <input type="hidden"
                                                name="parts[<?php echo htmlspecialchars($part['item_id']); ?>][unit_price]"
                                                value="<?php echo htmlspecialchars(number_format($part['price'], 2, '.', '')); ?>"
                                                class="part-unit-price-input">
                                        <span class="calculated-total" style="display: none;">KES 0.00</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p>No spare parts available in stock.</p>
                        <?php endif; ?>
                    </div>
                    <div style="text-align: right; margin-top: 10px; font-weight: bold;">
                        Total Parts Cost: <span id="totalPartsCost">KES 0.00</span>
                    </div>
                </div>

                <div class="form-group text-center">
                    <button type="submit" class="btn btn-primary" id="updateJobBtn">
                        <i class="fas fa-save"></i> Update Job Card
                    </button>
                    <button type="button" class="btn btn-secondary" id="cancelJobManagementBtn">Cancel</button>
                </div>
            </form>
            <div id="jobManagementMessage" class="message-container"></div>
        </div>
    </div>

    <script>
        const baseUrl = '<?php echo BASE_URL; ?>';
        const csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';
        const mechanicsLookup = <?php echo json_encode($mechanics_lookup); ?>; // Used for displaying mechanic names in table

        // Function to display notifications (re-used for all messages)
        function showNotification(message, type) {
            const notification = document.getElementById('jobManagementMessage'); // Using this as the main notification area
            notification.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
                ${message}
            `;
            notification.className = `message-container ${type}`;
            notification.style.display = 'flex';
            setTimeout(() => {
                notification.style.display = 'none';
            }, 5000);
        }

        // Modal elements
        const jobManagementModal = document.getElementById('jobManagementModal');
        const closeButton = jobManagementModal.querySelector('.close-button');
        const cancelJobManagementBtn = document.getElementById('cancelJobManagementBtn');
        const updateJobBtn = document.getElementById('updateJobBtn');
        const updateJobCardForm = document.getElementById('updateJobCardForm');

        // Form fields within the modal
        const modalJobCardId = document.getElementById('modalJobCardId');
        const modalVehicleId = document.getElementById('modalVehicleId');
        const modalVehicleInfo = document.getElementById('modalVehicleInfo');
        const modalJobDescription = document.getElementById('modalJobDescription');
        const modalStatusSelect = document.getElementById('modalStatusSelect');
        const modalMechanicSelect = document.getElementById('modalMechanicSelect');
        const modalServiceAdvisor = document.getElementById('modalServiceAdvisor'); // New
        const modalLaborCost = document.getElementById('modalLaborCost');
        const modalLastUpdatedTimestamp = document.getElementById('modalLastUpdatedTimestamp'); // New for optimistic locking - KEPT FOR JS SIDE BUT NOT USED IN PHP FOR NOW

        // Detailed Vehicle Info Inputs
        const detailRegNumber = document.getElementById('modal-detail-reg-number');
        const detailMake = document.getElementById('modal-detail-make');
        const detailModel = document.getElementById('modal-detail-model');
        const detailYear = document.getElementById('modal-detail-year');
        const detailColor = document.getElementById('modal-detail-color');
        const detailMileage = document.getElementById('modal-detail-mileage');
        const detailDriver = document.getElementById('modal-detail-driver');

        const partCheckboxes = document.querySelectorAll('.parts-selection input[type="checkbox"]');
        const totalPartsCostSpan = document.getElementById('totalPartsCost');


        // Function to calculate and update total parts cost
        function updatePartsTotal() {
            let totalCost = 0;
            partCheckboxes.forEach(checkbox => {
                if (checkbox.checked) {
                    const partItem = checkbox.closest('.part-item');
                    const quantityInput = partItem.querySelector('.part-quantity-input');
                    const unitPriceInput = partItem.querySelector('.part-unit-price-input');
                    const calculatedTotalSpan = partItem.querySelector('.calculated-total');

                    const quantity = parseFloat(quantityInput.value) || 0;
                    const unitPrice = parseFloat(unitPriceInput.value) || 0;
                    const itemTotal = quantity * unitPrice;

                    calculatedTotalSpan.textContent = `KES ${itemTotal.toFixed(2)}`;
                    calculatedTotalSpan.style.display = 'inline';
                    totalCost += itemTotal;
                } else {
                    const partItem = checkbox.closest('.part-item');
                    const calculatedTotalSpan = partItem.querySelector('.calculated-total');
                    calculatedTotalSpan.style.display = 'none'; // Hide if not checked
                }
            });
            totalPartsCostSpan.textContent = `KES ${totalCost.toFixed(2)}`;
        }


        // Function to open the job management modal and populate it
        function openJobManagementModalHandler() {
            // Get data from the clicked button's data attributes
            const jobId = this.dataset.jobId;
            const currentStatus = this.dataset.currentStatus;
            const assignedMechanicId = this.dataset.assignedMechanicId;
            const issueDescription = this.dataset.issueDescription;
            const vehicleId = this.dataset.vehicleId;
            const laborCost = this.dataset.laborCost;
            const serviceAdvisorId = this.dataset.serviceAdvisorId;
            const lastUpdatedTimestamp = this.dataset.lastUpdatedTimestamp; // Get timestamp

            // Populate main form fields
            modalJobCardId.value = jobId;
            modalVehicleId.value = vehicleId;
            modalVehicleInfo.value = `${this.dataset.vehicleReg} (${this.dataset.vehicleMake} ${this.dataset.vehicleModel})`;
            modalJobDescription.value = issueDescription;
            modalStatusSelect.value = currentStatus.toLowerCase().replace(' ', '_');
            modalMechanicSelect.value = assignedMechanicId || '';
            modalServiceAdvisor.value = serviceAdvisorId || ''; // Populate service advisor
            modalLaborCost.value = parseFloat(laborCost).toFixed(2);
            modalLastUpdatedTimestamp.value = lastUpdatedTimestamp; // Set timestamp

            // Populate detailed vehicle info fields
            detailRegNumber.value = this.dataset.vehicleReg;
            detailMake.value = this.dataset.vehicleMake;
            detailModel.value = this.dataset.vehicleModel;
            detailYear.value = this.dataset.vehicleYear;
            detailColor.value = this.dataset.vehicleColor;
            detailMileage.value = this.dataset.vehicleMileage;
            detailDriver.value = this.dataset.driverName;

            // Reset parts selection (if any parts were previously selected)
            partCheckboxes.forEach(checkbox => {
                checkbox.checked = false;
                const quantityInput = checkbox.closest('.part-item').querySelector('.part-quantity-input');
                const calculatedTotalSpan = checkbox.closest('.part-item').querySelector('.calculated-total');
                if (quantityInput) {
                    quantityInput.style.display = 'none';
                    quantityInput.value = 1;
                }
                if (calculatedTotalSpan) {
                    calculatedTotalSpan.style.display = 'none';
                    calculatedTotalSpan.textContent = 'KES 0.00';
                }
            });
            updatePartsTotal(); // Recalculate total parts cost after resetting

            // Show the modal
            jobManagementModal.style.display = 'flex';
        }

        // Attach event listeners to all "Manage" buttons
        document.querySelectorAll('.manage-job-button').forEach(button => {
            button.addEventListener('click', openJobManagementModalHandler);
        });

        // Close modal when clicking on the close button
        closeButton.addEventListener('click', () => {
            jobManagementModal.style.display = 'none';
        });

        // Close modal when clicking on the "Cancel" button
        cancelJobManagementBtn.addEventListener('click', () => {
            jobManagementModal.style.display = 'none';
        });

        // Close modal when clicking outside of it
        window.addEventListener('click', (event) => {
            if (event.target === jobManagementModal) {
                jobManagementModal.style.display = 'none';
            }
        });

        // Toggle quantity input visibility and update total based on checkbox
        partCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const quantityInput = this.closest('.part-item').querySelector('.part-quantity-input');
                const calculatedTotalSpan = this.closest('.part-item').querySelector('.calculated-total');
                if (quantityInput) {
                    quantityInput.style.display = this.checked ? 'block' : 'none';
                    if (!this.checked) {
                        quantityInput.value = 1;
                        calculatedTotalSpan.style.display = 'none';
                    }
                }
                updatePartsTotal();
            });
        });

        // Update total when quantity changes
        document.querySelectorAll('.part-quantity-input').forEach(input => {
            input.addEventListener('input', updatePartsTotal);
        });


        // Handle job card update form submission
        updateJobCardForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            const formData = new FormData(this);

            // Basic validation for mechanic selection
            const selectedStatus = modalStatusSelect.value;
            const selectedMechanic = modalMechanicSelect.value;

            if (!selectedMechanic && (selectedStatus === 'in_progress' || selectedStatus === 'assigned' || selectedStatus === 'on_hold' || selectedStatus === 'waiting_for_parts')) {
                showNotification('Please select a mechanic for this job or set status to pending/completed.', 'error');
                return;
            }

            // Validate parts quantities if selected
            let partsValid = true;
            const selectedPartsData = {}; // To track selected parts, quantities, and unit prices
            partCheckboxes.forEach(checkbox => {
                if (checkbox.checked) {
                    const partId = checkbox.dataset.partId;
                    const quantityInput = checkbox.closest('.part-item').querySelector('.part-quantity-input');
                    const unitPriceInput = checkbox.closest('.part-item').querySelector('.part-unit-price-input');

                    const quantity = parseInt(quantityInput.value);
                    const maxQuantity = parseInt(checkbox.dataset.maxQuantity);
                    const unitPrice = parseFloat(unitPriceInput.value);

                    if (isNaN(quantity) || quantity <= 0 || quantity > maxQuantity) {
                        showNotification(`Invalid quantity for ${checkbox.closest('label').textContent.split(' (In Stock:')[0]}. Max available: ${maxQuantity}`, 'error');
                        partsValid = false;
                        return; // Exit forEach early if invalid
                    }
                    if (isNaN(unitPrice) || unitPrice < 0) {
                        showNotification(`Invalid unit price for ${checkbox.closest('label').textContent.split(' (In Stock:')[0]}.`, 'error');
                        partsValid = false;
                        return;
                    }

                    selectedPartsData[partId] = {
                        selected: '1',
                        quantity: quantity,
                        unit_price: unitPrice
                    };
                }
            });

            if (!partsValid) {
                return;
            }

            // If parts are selected, change the action to 'assign_job_with_parts'
            // Otherwise, keep it as 'update_job_status'
            if (Object.keys(selectedPartsData).length > 0) {
                formData.set('action', 'assign_job_with_parts');
                // Manually append selected parts data to formData
                for (const partId in selectedPartsData) {
                    formData.append(`parts[${partId}][selected]`, selectedPartsData[partId].selected);
                    formData.append(`parts[${partId}][quantity]`, selectedPartsData[partId].quantity);
                    formData.append(`parts[${partId}][unit_price]`, selectedPartsData[partId].unit_price);
                }
            } else {
                formData.set('action', 'update_job_status');
            }

            updateJobBtn.disabled = true;
            updateJobBtn.textContent = 'Updating...';

            try {
                const response = await fetch(`${baseUrl}/dashboards/admin_dashboard.php`, {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const result = await response.json();

                if (result.success) {
                    showNotification(result.message, 'success');
                    jobManagementModal.style.display = 'none';
                    location.reload(); // Reload to reflect changes in the table and stats
                } else {
                    showNotification(result.message || 'Error updating job card.', 'error');
                }
            } catch (error) {
                console.error('Job update failed:', error);
                showNotification('An unexpected error occurred during job update.', 'error');
            } finally {
                updateJobBtn.disabled = false;
                updateJobBtn.innerHTML = '<i class="fas fa-save"></i> Update Job Card';
            }
        });

        // Initial fetch of stats on page load
        document.addEventListener('DOMContentLoaded', () => {
            // If there's a page message from a previous redirect (e.g., login success/error)
            <?php if (!empty($page_message)): ?>
                showNotification('<?php echo $page_message; ?>', '<?php echo $page_message_type; ?>');
            <?php endif; ?>
        });
    </script>
</body>

</html>
