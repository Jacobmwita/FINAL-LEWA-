<?php
session_start();

// BASE_URL definition - crucial for consistent linking
if (!defined('BASE_URL')) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    define('BASE_URL', "{$protocol}://{$host}/lewa");
}

// SECURITY CHECKUP
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'driver') {
    header("Location: " . BASE_URL . "/user_login.php");
    exit();
}

include '../db_connect.php';
// Include the functions file to make formatDate available
include '../functions.php';

// DATABASE CONNECTION VALIDATION
if (!isset($conn) || $conn->connect_error) {
    error_log("FATAL ERROR: Database connection failed in " . __FILE__ . ": " . ($conn->connect_error ?? 'Connection object not set.'));
    $_SESSION['error_message'] = "A critical system error occurred. Please try again later.";
    header("Location: " . BASE_URL . "/user_login.php");
    exit();
}

// DRIVER DETAILS
$driver_id = $_SESSION['user_id'];
$driver_full_name = 'Driver';
$driver_initial = '?';

$driver_query = "SELECT full_name, email FROM users WHERE user_id = ?";
$stmt = $conn->prepare($driver_query);

if ($stmt === false) {
    error_log("Failed to prepare statement for driver details: " . $conn->error);
    $_SESSION['error_message'] = "Error fetching driver details. Please try again.";
    header("Location: " . BASE_URL . "/dashboards/driver_portal.php");
    exit();
}

if (!$stmt->bind_param("i", $driver_id) || !$stmt->execute()) {
    error_log("Failed to execute statement for driver details: " . $stmt->error);
    $stmt->close();
    $_SESSION['error_message'] = "Error fetching driver details. Please try again.";
    header("Location: " . BASE_URL . "/dashboards/driver_portal.php");
    exit();
}

$result = $stmt->get_result();
if ($result && $driver_data = $result->fetch_assoc()) {
    $driver_full_name = htmlspecialchars($driver_data['full_name']);
    $driver_initial = strtoupper(substr($driver_data['full_name'], 0, 1));
} else {
    error_log("SECURITY ALERT: Driver with user_id {$driver_id} not found in DB");
    $_SESSION['error_message'] = "Your user account could not be found. Please log in again.";
    header("Location: " . BASE_URL . "/user_login.php");
    exit();
}
$stmt->close();

// --- Handle Form Submissions (Vehicle Registration & Service Request) ---
$page_message = '';
$page_message_type = '';
$target_tab_after_redirect = 'driverDashboard'; // Default tab to show after redirect

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'register_vehicle') {
        $make = $conn->real_escape_string($_POST['make'] ?? '');
        $model = $conn->real_escape_string($_POST['model'] ?? '');
        $registration_number = $conn->real_escape_string($_POST['registration_number'] ?? '');
        $year = filter_input(INPUT_POST, 'year', FILTER_VALIDATE_INT);
        $color = $conn->real_escape_string($_POST['color'] ?? '');
        $v_milage = filter_input(INPUT_POST, 'v_milage', FILTER_VALIDATE_INT);
        $engine_number = $conn->real_escape_string($_POST['engine_number'] ?? '');
        $chassis_number = $conn->real_escape_string($_POST['chassis_number'] ?? '');
        $fuel_type = $conn->real_escape_string($_POST['fuel_type'] ?? '');
        $v_notes = $conn->real_escape_string($_POST['v_notes'] ?? '');

        // Basic validation
        if (empty($make) || empty($model) || empty($registration_number) || !$year || $year === false || !$v_milage || $v_milage === false) {
            $page_message = "Please fill all required vehicle fields (Make, Model, Registration Number, Year, Mileage).";
            $page_message_type = 'error';
        } else {
            // Check if registration number already exists
            $check_stmt = $conn->prepare("SELECT COUNT(*) FROM vehicles WHERE registration_number = ?");
            $check_stmt->bind_param("s", $registration_number);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result()->fetch_row();
            $check_stmt->close();

            if ($check_result[0] > 0) {
                $page_message = "A vehicle with this registration number already exists.";
                $page_message_type = 'error';
            } else {
                $insert_query = "INSERT INTO vehicles (driver_id, make, model, registration_number, year, color, v_milage, engine_number, chassis_number, fuel_type, v_notes)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($insert_query);
                if ($stmt) {
                    $stmt->bind_param("isssissssss", $driver_id, $make, $model, $registration_number, $year, $color, $v_milage, $engine_number, $chassis_number, $fuel_type, $v_notes);
                    if ($stmt->execute()) {
                        $page_message = "Vehicle registered successfully!";
                        $page_message_type = 'success';
                        $target_tab_after_redirect = 'myVehicles'; // Go to my vehicles tab after successful registration
                    } else {
                        $page_message = "Error registering vehicle: " . $stmt->error;
                        $page_message_type = 'error';
                    }
                    $stmt->close();
                } else {
                    $page_message = "Database error: Could not prepare vehicle insertion statement.";
                    $page_message_type = 'error';
                }
            }
        }
        // Redirect after processing to prevent form resubmission and pass tab parameter
        header('Location: ' . BASE_URL . '/dashboards/driver_portal.php?message=' . urlencode($page_message) . '&type=' . urlencode($page_message_type) . '&tab=' . urlencode($target_tab_after_redirect));
        exit();

    } elseif (isset($_POST['action']) && $_POST['action'] === 'submit_service_request') {
        // Handle service request submission (from the "Quick Service Request" form)
        $vehicle_id = filter_input(INPUT_POST, 'vehicle_id', FILTER_VALIDATE_INT);
        // --- START HIGHLIGHT: Retrieving service description from POST data ---
        $description = $conn->real_escape_string($_POST['description'] ?? ''); // This is the issue/problem description
        // --- END HIGHLIGHT ---
        $urgency = $conn->real_escape_string($_POST['urgency'] ?? 'normal');

        if (!$vehicle_id || empty($description) || strlen($description) < 10) {
            $page_message = 'Please select a vehicle and provide a detailed description (at least 10 characters).';
            $page_message_type = 'error';
        } else {
            // Check if there's already a pending job card for this vehicle
            $check_pending_query = "SELECT job_card_id FROM job_cards WHERE vehicle_id = ? AND status = 'pending'";
            $check_stmt = $conn->prepare($check_pending_query);
            $check_stmt->bind_param("i", $vehicle_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();

            if ($check_result->num_rows > 0) {
                $page_message = 'There is already a pending service request for this vehicle.';
                $page_message_type = 'warning';
                $target_tab_after_redirect = 'pendingRequests'; // Go to pending requests tab
            } else {
                // Fetch vehicle and driver details for job card
                $vehicle_info_query = "SELECT v.make, v.model, v.registration_number, u.full_name as driver_full_name
                                       FROM vehicles v JOIN users u ON v.driver_id = u.user_id
                                       WHERE v.vehicle_id = ?";
                $info_stmt = $conn->prepare($vehicle_info_query);
                $info_stmt->bind_param("i", $vehicle_id);
                $info_stmt->execute();
                $vehicle_info = $info_stmt->get_result()->fetch_assoc();
                $info_stmt->close();

                if ($vehicle_info) {
                    $driver_name_for_job_card = $vehicle_info['driver_full_name'];
                    $vehicle_model_for_job_card = $vehicle_info['make'] . ' ' . $vehicle_info['model'];
                    $vehicle_license_for_job_card = $vehicle_info['registration_number'];

                    // --- START HIGHLIGHT: Inserting service description into 'issue_description' column ---
                    $insert_query = "INSERT INTO job_cards (vehicle_id, driver_name, vehicle_model, vehicle_license, issue_description, status, urgency, created_by_user_id)
                                     VALUES (?, ?, ?, ?, ?, 'pending', ?, ?)";
                    $insert_stmt = $conn->prepare($insert_query);
                    if ($insert_stmt) {
                        $insert_stmt->bind_param("isssssi", $vehicle_id, $driver_name_for_job_card, $vehicle_model_for_job_card, $vehicle_license_for_job_card, $description, $urgency, $driver_id);
                        // --- END HIGHLIGHT ---
                        if ($insert_stmt->execute()) {
                            $page_message = 'Service request submitted successfully!';
                            $page_message_type = 'success';
                            $target_tab_after_redirect = 'pendingRequests'; // Go to pending requests tab
                        } else {
                            $page_message = 'Error submitting service request: ' . $insert_stmt->error;
                            $page_message_type = 'error';
                        }
                        $insert_stmt->close();
                    } else {
                        $page_message = 'Database error: Could not prepare service request statement.';
                        $page_message_type = 'error';
                    }
                } else {
                    $page_message = 'Vehicle not found or linked to driver.';
                    $page_message_type = 'error';
                }
            }
            $check_stmt->close();
        }
        // Redirect to prevent form resubmission and display message with target tab
        header('Location: ' . BASE_URL . '/dashboards/driver_portal.php?message=' . urlencode($page_message) . '&type=' . urlencode($page_message_type) . '&tab=' . urlencode($target_tab_after_redirect));
        exit();
    }
}

// Capture messages and target tab from GET parameters after redirection
if (isset($_GET['message']) && isset($_GET['type'])) {
    $page_message = htmlspecialchars($_GET['message']);
    $page_message_type = htmlspecialchars($_GET['type']);
    if (isset($_GET['tab'])) {
        $target_tab_after_redirect = htmlspecialchars($_GET['tab']);
    }
    // Clear GET parameters to prevent re-display on refresh, but keep the tab info for JS
    $redirect_url = strtok($_SERVER["REQUEST_URI"], '?');
    header('Location: ' . $redirect_url);
    exit();
}


// VEHICLE QUERY (fetched after possible new vehicle registration)
$vehicles_query = "
    SELECT
        v.vehicle_id,
        v.make,
        v.model,
        v.registration_number,
        MAX(j.created_at) AS last_service
    FROM
        vehicles v
    LEFT JOIN
        job_cards j ON v.vehicle_id = j.vehicle_id
    WHERE
        v.driver_id = ?
    GROUP BY
        v.vehicle_id, v.make, v.model, v.registration_number
    ORDER BY
        v.make, v.model";
$vehicles = [];

$stmt = $conn->prepare($vehicles_query);
if ($stmt === false) {
    error_log("Failed to prepare statement for vehicles: " . $conn->error);
    // Use $page_message system
    if (empty($page_message)) { // Don't overwrite existing error messages if already set by form submission
        $page_message = "Error fetching vehicle list. Please try again.";
        $page_message_type = 'error';
    }
} else {
    if (!$stmt->bind_param("i", $driver_id) || !$stmt->execute()) {
        error_log("Failed to execute statement for vehicles: " . $stmt->error);
        if (empty($page_message)) {
            $page_message = "Error fetching vehicle list. Please try again.";
            $page_message_type = 'error';
        }
    } else {
        $result = $stmt->get_result();
        if ($result) {
            $vehicles = $result->fetch_all(MYSQLI_ASSOC);
        }
    }
    $stmt->close();
}

// SERVICES UPDATES
$pending_requests_query = "
    SELECT
        j.job_card_id,
        j.description,
        j.created_at,
        v.make,
        v.model,
        v.registration_number,
        v.year,
        v.color,
        v.v_milage,
        v.engine_number,
        v.chassis_number,
        v.fuel_type
    FROM
        job_cards j
    JOIN
        vehicles v ON j.vehicle_id = v.vehicle_id
    WHERE
        v.driver_id = ? AND j.status = 'pending'
    ORDER BY
        j.created_at DESC";
$pending_requests = [];

$stmt = $conn->prepare($pending_requests_query);
if ($stmt === false) {
    error_log("Failed to prepare statement for pending requests: " . $conn->error);
    if (empty($page_message)) { // Don't overwrite existing error
        $page_message = "Error fetching pending requests. Please try again.";
        $page_message_type = 'error';
    }
} else {
    if (!$stmt->bind_param("i", $driver_id) || !$stmt->execute()) {
        error_log("Failed to execute statement for pending requests: " . $stmt->error);
        if (empty($page_message)) { // Don't overwrite existing error
            $page_message = "Error fetching pending requests. Please try again.";
            $page_message_type = 'error';
        }
    } else {
        $result = $stmt->get_result();
        if ($result) {
            $pending_requests = $result->fetch_all(MYSQLI_ASSOC);
        }
    }
    $stmt->close();
}

// Ensure the connection is closed at the very end
if (isset($conn)) {
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver Portal - Lewa Workshop</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --primary: #3498db;
            --secondary: #2c3e50;
            --success: #2ecc71;
            --danger: #e74c3c;
            --warning: #f39c12;
            --info: #17a2b8;
            --light: #ecf0f1;
            --dark: #34495e; /* Slightly darker than secondary for sidebar */
            --text-color: #333;
            --border-color: #ddd;
            --card-bg: #fff;
            --header-bg: #ecf0f1;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f5f6fa;
            color: var(--text-color);
        }

        .dashboard {
            display: grid;
            grid-template-columns: 250px 1fr;
            min-height: 100vh;
        }

        .sidebar {
            background-color: var(--dark);
            color: white;
            padding: 20px 0;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
        }

        .sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            text-align: center;
        }
        .sidebar-header h2 {
            color: var(--primary);
            margin-bottom: 5px;
            font-size: 1.8em;
        }
        .sidebar-header p {
            color: var(--light);
            font-size: 0.9em;
            margin: 0;
        }

        .sidebar-menu {
            padding: 20px 0;
        }

        .menu-item {
            padding: 12px 20px;
            color: var(--light);
            text-decoration: none;
            display: flex; /* Changed to flex for icon alignment */
            align-items: center;
            transition: all 0.3s;
            font-size: 1.05em;
        }

        .menu-item:hover, .menu-item.active {
            background-color: rgba(255,255,255,0.1);
            color: white;
        }

        .menu-item i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }

        /* Styles for the new tabbed content area */
        .main-content {
            padding: 20px;
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 0 15px rgba(0,0,0,0.05);
            margin: 20px; /* Add margin to separate from sidebar */
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-color); /* Use variable */
        }
        .header h1 {
            margin: 0;
            color: var(--secondary);
            font-size: 2em;
        }

        .user-info {
            display: flex;
            align-items: center;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 10px;
            font-weight: bold;
            font-size: 1.2em;
        }

        .card {
            background-color: var(--card-bg); /* Use variable */
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }

        .card-title {
            margin-top: 0;
            color: var(--secondary);
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 15px; /* Add spacing below title */
            font-size: 1.4em;
            font-weight: bold;
        }

        .btn {
            padding: 10px 20px; /* Slightly larger buttons */
            border-radius: 5px; /* More rounded */
            border: none;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 10px; /* Space between buttons */
        }

        .btn i {
            margin-right: 8px; /* Space between icon and text */
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background-color: #2980b9;
            transform: translateY(-2px); /* Lift effect */
        }

        .btn-success {
            background-color: var(--success);
            color: white;
        }

        .btn-success:hover {
            background-color: #27ae60;
            transform: translateY(-2px);
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            border-radius: 8px; /* Apply to table */
            overflow: hidden; /* Ensures rounded corners apply */
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .table th, .table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color); /* Use variable */
        }

        .table th {
            background-color: var(--header-bg); /* Use variable */
            color: var(--secondary);
            text-transform: uppercase;
            font-size: 0.9em;
        }
        .table tr:nth-child(even) {
            background-color: var(--light); /* Alternate row background */
        }
        .table tr:hover {
            background-color: #f1f1f1;
        }

        .status-badge {
            padding: 4px 10px; /* Slightly more padding */
            border-radius: 15px; /* More rounded */
            font-size: 0.75em;
            font-weight: bold;
            color: white;
            display: inline-block;
        }

        .status-pending { background-color: var(--warning); color: #856404; } /* Adjusted color for better contrast */
        .status-in-progress { background-color: var(--info); color: #004085; }
        .status-completed { background-color: var(--success); color: #155724; }

        .logout-btn {
            background: none;
            border: none;
            color: var(--light);
            cursor: pointer;
            padding: 12px 20px;
            text-align: left;
            width: 100%;
            font-size: 1.05em; /* Consistent with menu-item */
            display: flex; /* For icon alignment */
            align-items: center;
        }

        .logout-btn:hover {
            color: white;
            background-color: rgba(255,255,255,0.1);
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--secondary);
        }

        .form-control {
            width: calc(100% - 2px); /* Adjust for border, full width */
            padding: 10px;
            border: 1px solid var(--border-color); /* Use variable */
            border-radius: 4px;
            box-sizing: border-box;
            transition: border-color 0.3s ease;
        }
        .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
        }

        .text-center {
            text-align: center;
        }

        /* Tab specific styles */
        .tabs-container {
            display: flex;
            border-bottom: 2px solid var(--border-color);
            margin-bottom: 20px;
        }

        .tab-button {
            padding: 12px 20px;
            cursor: pointer;
            border: none;
            background-color: var(--header-bg);
            border-top-left-radius: 8px;
            border-top-right-radius: 8px;
            margin-right: 5px;
            font-weight: bold;
            color: var(--secondary);
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        .tab-button.active {
            background-color: var(--primary);
            color: white;
            border-bottom: 2px solid var(--primary); /* Highlight active tab */
        }
        .tab-button:hover:not(.active) {
            background-color: #e0e0e0;
        }

        .tab-content {
            display: none; /* Hidden by default */
            padding-top: 10px; /* Space between tabs and content */
        }
        .tab-content.active {
            display: block;
        }

        /* Notification Styling (similar to finance_dashboard) */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 8px;
            color: white;
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
            z-index: 1000;
            display: flex;
            align-items: center;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease, transform 0.3s ease;
            transform: translateX(100%);
        }
        .notification.show {
            opacity: 1;
            visibility: visible;
            transform: translateX(0);
        }
        .notification-success { background-color: var(--success); }
        .notification-error { background-color: var(--danger); }
        .notification-warning { background-color: var(--warning); }
        .notification i { margin-right: 10px; }


        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .dashboard {
                grid-template-columns: 1fr; /* Stack sidebar and main content */
            }

            .sidebar {
                width: 100%;
                padding-bottom: 10px;
            }

            .sidebar-header {
                padding-bottom: 10px;
            }

            .sidebar-menu {
                display: flex;
                flex-wrap: wrap;
                justify-content: center;
                padding: 10px 0;
            }
            .menu-item {
                flex-direction: column; /* Stack icon and text */
                padding: 8px 10px;
                font-size: 0.85em;
                margin-right: 5px; /* Adjust spacing */
            }
            .menu-item i {
                margin-right: 0;
                margin-bottom: 5px;
            }
            .logout-btn {
                flex-direction: column;
                padding: 8px 10px;
                font-size: 0.85em;
            }
            .logout-btn i {
                margin-right: 0;
                margin-bottom: 5px;
            }


            .main-content {
                padding: 15px;
                margin: 10px;
            }

            .header {
                flex-direction: column; /* Stack header elements */
                align-items: flex-start;
            }
            .header h1 {
                margin-bottom: 10px;
                font-size: 1.8em;
            }
            .user-info {
                width: 100%;
                justify-content: flex-end; /* Align user info to the right */
            }

            .btn {
                font-size: 0.8em;
                padding: 8px 12px;
                margin-bottom: 10px; /* Add vertical spacing for stacked buttons */
            }
            .card > div { /* Apply flex wrap to immediate children of card for button layout */
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
            }

            .table th, .table td {
                padding: 10px;
                font-size: 0.85em;
            }
            .status-badge {
                font-size: 0.7em;
            }

            .tabs-container {
                flex-wrap: wrap;
            }
            .tab-button {
                flex: 1 1 auto; /* Allow tabs to grow and shrink */
                margin-bottom: 5px;
                font-size: 0.9em;
                text-align: center;
            }

            .notification {
                top: 10px;
                right: 10px;
                left: 10px;
                font-size: 0.9em;
                padding: 10px 15px;
            }
        }

        @media (max-width: 480px) {
            .sidebar-header h2 {
                font-size: 1.5em;
            }
            .sidebar-header p {
                font-size: 0.8em;
            }
            .menu-item, .logout-btn {
                font-size: 0.75em;
                padding: 6px 8px;
            }
            .main-content {
                margin: 5px;
                padding: 10px;
            }
            .header h1 {
                font-size: 1.5em;
            }
            .card-title {
                font-size: 1.2em;
            }
            .btn {
                padding: 6px 10px;
                font-size: 0.75em;
            }
            .table th, .table td {
                padding: 8px;
                font-size: 0.75em;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>Driver Portal</h2>
                <p>Lewa Workshop</p>
            </div>
            
            <div class="sidebar-menu">
                <a href="#" class="menu-item" onclick="openTab(event, 'driverDashboard')">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="#" class="menu-item" onclick="openTab(event, 'myVehicles')">
                    <i class="fas fa-car"></i> My Vehicles
                </a>
                <a href="#" class="menu-item" onclick="openTab(event, 'registerVehicle')">
                    <i class="fas fa-plus-square"></i> Register My Vehicle
                </a>
                <a href="#" class="menu-item" onclick="openTab(event, 'pendingRequests')">
                    <i class="fas fa-clock"></i> Pending Requests
                </a>
                <a href="#" class="menu-item" onclick="openTab(event, 'requestService')">
                    <i class="fas fa-tools"></i> Request Service
                </a>
                
                <form action="<?php echo BASE_URL; ?>/logout.php" method="post" class="menu-item" style="padding: 0;">
                    <button type="submit" class="logout-btn">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </button>
                </form>
            </div>
        </div>
        
        <div class="main-content">
            <div class="header">
                <h1>Driver Dashboard</h1>
                <div class="user-info">
                    <div class="user-avatar"><?php echo htmlspecialchars($driver_initial); ?></div>
                    <div>
                        <div><?php echo htmlspecialchars($driver_full_name); ?></div>
                        <small>Driver</small>
                    </div>
                </div>
            </div>
            
            <div id="notification" class="notification"></div>

            <div class="tabs-container">
                <button class="tab-button" onclick="openTab(event, 'driverDashboard')">Dashboard</button>
                <button class="tab-button" onclick="openTab(event, 'myVehicles')">My Vehicles</button>
                <button class="tab-button" onclick="openTab(event, 'registerVehicle')">Register Vehicle</button>
                <button class="tab-button" onclick="openTab(event, 'pendingRequests')">Pending Requests</button>
                <button class="tab-button" onclick="openTab(event, 'requestService')">Request Service</button>
            </div>

            <!-- Dashboard Overview Tab -->
            <div id="driverDashboard" class="tab-content">
                <div class="card">
                    <div class="card-title">Quick Actions</div>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <a href="#" class="btn btn-primary" onclick="openTab(event, 'requestService')">
                            <i class="fas fa-plus"></i> Request New Service
                        </a>
                        <a href="#" class="btn btn-success" onclick="openTab(event, 'myVehicles')">
                            <i class="fas fa-car"></i> View My Vehicles
                        </a>
                           <a href="#" class="btn btn-info" style="background-color: var(--info); color: white;" onclick="openTab(event, 'registerVehicle')">
                                <i class="fas fa-plus-square"></i> Register My Vehicle
                            </a>
                    </div>
                </div>

                <div class="card">
                    <div class="card-title">My Vehicles Overview</div>
                    <?php if (!empty($vehicles)): ?>
                        <p>You have **<?php echo count($vehicles); ?>** vehicles registered or assigned to you.</p>
                        <ul style="list-style: none; padding: 0;">
                            <?php foreach ($vehicles as $vehicle): ?>
                                <li style="margin-bottom: 5px;">
                                    <strong><?php echo htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model']); ?></strong> 
                                    (<?php echo htmlspecialchars($vehicle['registration_number']); ?>) - Last service: <?php echo $vehicle['last_service'] ? formatDate($vehicle['last_service']) : 'Never'; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-center">No vehicles assigned or registered to you. Use the "Register My Vehicle" tab to add one.</p>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="card-title">Pending Service Requests Summary</div>
                    <?php if (!empty($pending_requests)): ?>
                        <p>You have **<?php echo count($pending_requests); ?>** pending service request(s).</p>
                        <ul style="list-style: none; padding: 0;">
                            <?php foreach ($pending_requests as $request): ?>
                                <li style="margin-bottom: 5px;">
                                    Job #<?php echo htmlspecialchars($request['job_card_id']); ?> for 
                                    **<?php echo htmlspecialchars($request['make'] . ' ' . $request['model']); ?>** (<?php echo htmlspecialchars($request['registration_number']); ?>) - 
                                    Requested on: <?php echo formatDate($request['created_at']); ?>
                                    <!-- Display the description here for quick overview -->
                                    <br><small>Description: <?php echo htmlspecialchars($request['description']); ?></small>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-center">No pending service requests.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- My Vehicles Tab -->
            <div id="myVehicles" class="tab-content">
                <div class="card">
                    <div class="card-title">My Registered Vehicles</div>
                    <?php if (!empty($vehicles)): ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Vehicle ID</th>
                                    <th>Make</th>
                                    <th>Model</th>
                                    <th>Registration No.</th>
                                    <th>Last Service</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($vehicle['vehicle_id']); ?></td>
                                        <td><?php echo htmlspecialchars($vehicle['make']); ?></td>
                                        <td><?php echo htmlspecialchars($vehicle['model']); ?></td>
                                        <td><?php echo htmlspecialchars($vehicle['registration_number']); ?></td>
                                        <td><?php echo $vehicle['last_service'] ? formatDate($vehicle['last_service']) : 'N/A'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="text-center">You have no vehicles assigned or registered yet.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Register Vehicle Tab -->
            <div id="registerVehicle" class="tab-content">
                <div class="card">
                    <div class="card-title">Register a New Vehicle</div>
                    <form action="<?php echo BASE_URL; ?>/dashboards/driver_portal.php" method="POST">
                        <input type="hidden" name="action" value="register_vehicle">
                        
                        <div class="form-group">
                            <label for="make">Make <span style="color: red;">*</span></label>
                            <input type="text" id="make" name="make" class="form-control" placeholder="e.g., Toyota" required>
                        </div>
                        <div class="form-group">
                            <label for="model">Model <span style="color: red;">*</span></label>
                            <input type="text" id="model" name="model" class="form-control" placeholder="e.g., Camry" required>
                        </div>
                        <div class="form-group">
                            <label for="registration_number">Registration Number <span style="color: red;">*</span></label>
                            <input type="text" id="registration_number" name="registration_number" class="form-control" placeholder="e.g., KBC 123X" required>
                        </div>
                        <div class="form-group">
                            <label for="year">Year <span style="color: red;">*</span></label>
                            <input type="number" id="year" name="year" class="form-control" placeholder="e.g., 2018" min="1900" max="<?php echo date('Y') + 1; ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="color">Color</label>
                            <input type="text" id="color" name="color" class="form-control" placeholder="e.g., Blue">
                        </div>
                        <div class="form-group">
                            <label for="v_milage">Current Mileage <span style="color: red;">*</span></label>
                            <input type="number" id="v_milage" name="v_milage" class="form-control" placeholder="e.g., 50000" min="0" required>
                        </div>
                        <div class="form-group">
                            <label for="engine_number">Engine Number</label>
                            <input type="text" id="engine_number" name="engine_number" class="form-control" placeholder="e.g., ABC123DEF456">
                        </div>
                        <div class="form-group">
                            <label for="chassis_number">Chassis Number</label>
                            <input type="text" id="chassis_number" name="chassis_number" class="form-control" placeholder="e.g., GHI789JKL012">
                        </div>
                        <div class="form-group">
                            <label for="fuel_type">Fuel Type</label>
                            <select id="fuel_type" name="fuel_type" class="form-control">
                                <option value="Petrol">Petrol</option>
                                <option value="Diesel">Diesel</option>
                                <option value="Electric">Electric</option>
                                <option value="Hybrid">Hybrid</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="v_notes">Notes</label>
                            <textarea id="v_notes" name="v_notes" class="form-control" rows="3" placeholder="Any additional notes about the vehicle"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-car"></i> Register Vehicle</button>
                    </form>
                </div>
            </div>

            <!-- Pending Service Requests Tab -->
            <div id="pendingRequests" class="tab-content">
                <div class="card">
                    <div class="card-title">My Pending Service Requests</div>
                    <?php if (!empty($pending_requests)): ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Job Card ID</th>
                                    <th>Vehicle</th>
                                    <th>Registration No.</th>
                                    <th>Description</th>
                                    <th>Requested On</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending_requests as $request): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($request['job_card_id']); ?></td>
                                        <td><?php echo htmlspecialchars($request['make'] . ' ' . $request['model']); ?></td>
                                        <td><?php echo htmlspecialchars($request['registration_number']); ?></td>
                                        <td><?php echo htmlspecialchars($request['description']); ?></td>
                                        <td><?php echo formatDate($request['created_at']); ?></td>
                                        <td><span class="status-badge status-pending">Pending</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="text-center">No pending service requests at this time. You can submit a new request below.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Request Service Tab -->
            <div id="requestService" class="tab-content">
                <div class="card">
                    <div class="card-title">Quick Service Request</div>
                    <form action="<?php echo BASE_URL; ?>/dashboards/driver_portal.php" method="POST">
                        <input type="hidden" name="action" value="submit_service_request">
                        
                        <div class="form-group">
                            <label for="vehicle_id">Select Vehicle <span style="color: red;">*</span></label>
                            <select id="vehicle_id" name="vehicle_id" class="form-control" required>
                                <option value="">-- Select your vehicle --</option>
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <option value="<?php echo htmlspecialchars($vehicle['vehicle_id']); ?>">
                                        <?php echo htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model'] . ' (' . $vehicle['registration_number'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="description">Service Description (Issue/Problem) <span style="color: red;">*</span></label>
                            <!-- This is the textarea where the service description is input -->
                            <textarea id="description" name="description" class="form-control" rows="5" placeholder="Describe the issue or service needed (e.g., 'Engine making strange knocking noises', 'Oil change and tire rotation needed', 'Brake pads worn out')" required minlength="10"></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="urgency">Urgency</label>
                            <select id="urgency" name="urgency" class="form-control">
                                <option value="normal">Normal</option>
                                <option value="high">High Priority</option>
                                <option value="emergency">Emergency</option>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> Submit Request
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Function to show notifications (similar to other dashboards)
        function showNotification(message, type) {
            const notification = document.getElementById('notification');
            notification.className = 'notification'; // Reset classes
            notification.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' : (type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle')}"></i>
                ${message}
            `;
            notification.classList.add(`notification-${type}`, 'show');

            setTimeout(() => {
                notification.classList.remove('show');
            }, 5000);
        }

        // Tab functionality
        function openTab(evt, tabName) {
            const tabContents = document.getElementsByClassName('tab-content');
            for (let i = 0; i < tabContents.length; i++) {
                tabContents[i].style.display = 'none';
                tabContents[i].classList.remove('active'); // Ensure no active class remains
            }

            const tabButtons = document.getElementsByClassName('tab-button');
            for (let i = 0; i < tabButtons.length; i++) {
                tabButtons[i].classList.remove('active');
            }

            document.getElementById(tabName).style.display = 'block';
            document.getElementById(tabName).classList.add('active'); // Add active class to content
            if (evt) { // Check if event exists (for clicks)
                evt.currentTarget.classList.add('active');
            }
        }

        // Handle initial page message and tab activation from URL parameters
        document.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            const message = urlParams.get('message');
            const type = urlParams.get('type');
            const tab = urlParams.get('tab');

            if (message && type) {
                showNotification(decodeURIComponent(message), decodeURIComponent(type));
            }

            // Activate the correct tab after loading
            if (tab) {
                // Find the button associated with the tab and simulate a click
                const tabButtons = document.getElementsByClassName('tab-button');
                for (let i = 0; i < tabButtons.length; i++) {
                    if (tabButtons[i].textContent.trim().toLowerCase().replace(' ', '') === tab.toLowerCase().replace(' ', '')) {
                        tabButtons[i].click(); // Simulate click on the correct tab button
                        break;
                    }
                }
            } else {
                // Default to 'driverDashboard' tab if no tab parameter is specified
                openTab(null, 'driverDashboard'); 
                document.querySelector('.tab-button').classList.add('active'); // Set default button active
            }
        });

        // Client-side validation for service request form (added minlength)
        document.querySelector('form[name="action"][value="submit_service_request"]').addEventListener('submit', function(e) {
            const description = document.getElementById('description').value.trim();
            const vehicleId = document.getElementById('vehicle_id').value;

            if (vehicleId === "") {
                showNotification('Please select a vehicle.', 'error');
                e.preventDefault();
                return;
            }

            if (description.length < 10) {
                showNotification('Please provide a more detailed description of the service needed (at least 10 characters).', 'error');
                e.preventDefault();
            }
        });
    </script>
</body>
</html>
