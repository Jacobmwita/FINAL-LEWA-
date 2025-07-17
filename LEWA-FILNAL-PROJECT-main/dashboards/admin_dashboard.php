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
    'manager'
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


//AJAX HANDLING
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
                    SUM(CASE WHEN status IN ('in_progress', 'in progress') THEN 1 ELSE 0 END) AS in_progress,
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

//FORMS SUBMISSIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    // CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $response['message'] = 'Invalid CSRF token';
        echo json_encode($response);
        exit();
    }

    try {
        switch ($_POST['action'] ?? '') {
            case 'add_driver':
                // VALIDATIONS
                $required = ['username', 'email', 'full_name', 'phone_number', 'id_number', 'department'];
                foreach ($required as $field) {
                    if (empty($_POST[$field])) {
                        throw new Exception("$field is required");
                    }
                }

                // ADMIN ADDING NEW DRIVER
                $stmt = $conn->prepare("INSERT INTO users (username, email, password, full_name, user_type, phone_number, id_number, department)
                                         VALUES (?, ?, ?, ?, 'driver', ?, ?, ?)");
                $temp_password = password_hash('Temp123!', PASSWORD_DEFAULT);
                $stmt->bind_param(
                    "sssssss", // 's' for string types: username, email, password, full_name, user_type, phone_number, id_number, department
                    $_POST['username'],
                    $_POST['email'],
                    $temp_password,
                    $_POST['full_name'],
                    $_POST['phone_number'],
                    $_POST['id_number'],
                    $_POST['department']
                );

                if ($stmt->execute()) {
                    $response['success'] = true;
                    $response['message'] = "Driver added successfully";
                } else {
                    throw new Exception("Failed to add driver: " . $stmt->error);
                }
                $stmt->close();
                break;

            case 'update_job_status':
                if (empty($_POST['job_id']) || empty($_POST['status'])) {
                    throw new Exception("Job ID and status are required");
                }

                $job_id = $_POST['job_id'];
                $status = $_POST['status'];
                $mechanic_id = $_POST['mechanic_id'] ?? null; 

                $update_query = "UPDATE job_cards SET status = ?";
                $types = "s"; 

            
                if ($status === 'completed') {
                    $update_query .= ", completed_at = NOW()";
                } else {

                    $update_query .= ", completed_at = NULL";
                }

                if ($mechanic_id !== null && $mechanic_id !== '') {
                    $update_query .= ", assigned_to_mechanic_id = ?";
                    $types .= "i"; 
                } else {

                    $update_query .= ", assigned_to_mechanic_id = NULL";
                }

                $update_query .= " WHERE job_card_id = ?";
                $types .= "i"; 

                $stmt = $conn->prepare($update_query);

                if (!$stmt) {
                    throw new Exception("Failed to prepare update statement: " . $conn->error);
                }

                if ($mechanic_id !== null && $mechanic_id !== '') {
                    $stmt->bind_param($types, $status, $mechanic_id, $job_id);
                } else {
                    $stmt->bind_param($types, $status, $job_id);
                }

                if ($stmt->execute()) {
                    $response['success'] = true;
                    $response['message'] = "Job status updated";
                } else {
                    throw new Exception("Failed to update job status: " . $stmt->error);
                }
                $stmt->close();
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
    SUM(CASE WHEN status IN ('in_progress', 'in progress') THEN 1 ELSE 0 END) AS in_progress,
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
    $stats['total_inventory_quantity'] = $row['total_quantity'] ?? 0; // Fixed typo here
}


$recent_activities = [];

$result = $conn->query("SELECT jc.*, v.registration_number, v.make, v.model, v.year, v.color, v_mileage AS v_mileage, completed_at, v.driver_id, u.full_name AS driver_name
                            FROM job_cards jc
                            LEFT JOIN vehicles v ON jc.vehicle_id = v.vehicle_id
                            LEFT JOIN users u ON v.driver_id = u.user_id
                            ORDER BY jc.created_at DESC LIMIT 5");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $recent_activities[] = $row;
    }
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
        select {
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
        select:focus {
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


        .recent-activities {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            overflow-x: auto; 
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

        .action-button, .manage-button, .view-details-button {
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

        .action-button:hover, .manage-button:hover, .view-details-button:hover {
            background-color: var(--secondary);
        }

    
        .modal {
            display: none; 
            position: fixed; 
            z-index: 1000; 
            left: 0;
            top: 0;
            width: 100%; 
            height: 100%; 
            overflow: auto; 
            background-color: rgba(0,0,0,0.4); 
            justify-content: center; 
            align-items: center;
        }

        .modal-content {
            background-color: #fefefe;
            margin: 20px; 
            padding: 20px;
            border: 1px solid #888;
            border-radius: 8px;
            width: 90%; 
            max-width: 600px; 
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            position: relative;
        }

        .close-button {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
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

        #vehicleDetailsModal .detail-item {
            margin-bottom: 10px;
            display: flex; 
            flex-wrap: wrap; 
        }
        #vehicleDetailsModal .detail-item strong {
            display: inline-block;
            width: 120px; 
            flex-shrink: 0; 
        }
        #vehicleDetailsModal .detail-item span {
            flex-grow: 1; 
        }

        .message-container {
            margin-top: 20px;
            padding: 10px;
            border-radius: 5px;
            font-weight: bold;
            display: none; 
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

            .action-button, .manage-button, .view-details-button {
                padding: 5px 8px; 
                font-size: 0.8em; 
                margin-right: 3px; 
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
</head>

<body>
    <div class="dashboard-container">
        <div class="sidebar">
            <h2>Lewa Workshop</h2>
            <p>Welcome, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?></p>

            <nav>
                <ul>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/admin_dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/job_card.php"><i class="fas fa-clipboard-list"></i> View/Edit Job Cards</a></li>
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
                    <h3>In Progress</h3>
                    <div class="value" id="stat-progress"><?php echo $stats['in_progress_jobs']; ?></div>
                </div>

                <div class="stat-card">
                    <h3>Completed</h3>
                    <div class="value" id="stat-completed"><?php echo $stats['completed_jobs']; ?></div>
                </div>

                <div class="stat-card">
                    <h3>Inventory Items</h3>
                    <div class="value" id="stat-inventory-items"><?php echo $stats['total_inventory_items']; ?></div>
                </div>

                <div class="stat-card">
                    <h3>Total Parts Qty</h3>
                    <div class="value" id="stat-inventory-quantity"><?php echo $stats['total_inventory_quantity']; ?></div>
                </div>
            </div>

            <div class="quick-actions">
                <div class="action-card">
                    <h3>Quick Add Driver</h3>
                    <form id="quickDriverForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="add_driver">
                        <div class="form-group">
                            <label for="username">Username:</label>
                            <input type="text" id="username" name="username" required>
                        </div>
                        <div class="form-group">
                            <label for="email">Email:</label>
                            <input type="email" id="email" name="email" required>
                        </div>
                        <div class="form-group">
                            <label for="full_name">Full Name:</label>
                            <input type="text" id="full_name" name="full_name" required>
                        </div>
                        <div class="form-group">
                            <label for="phone_number">Phone Number:</label>
                            <input type="text" id="phone_number" name="phone_number" required>
                        </div>
                        <div class="form-group">
                            <label for="id_number">ID Number:</label>
                            <input type="text" id="id_number" name="id_number" required>
                        </div>
                        <div class="form-group">
                            <label for="department">Department:</label>
                            <input type="text" id="department" name="department" required>
                        </div>
                        <button type="submit"><i class="fas fa-plus"></i> Add Driver</button>
                        <div id="driverMessage" class="message-container"></div>
                    </form>
                </div>

                <div class="action-card">
                    <h3>Job Card Management</h3>
                    <p>Access and manage all job cards in the system.</p>
                    <a href="<?php echo BASE_URL; ?>/dashboards/job_card.php" class="action-button">
                        <i class="fas fa-clipboard-list"></i> View All Job Cards
                    </a>
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
                            <th>Created At</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_activities)) : ?>
                            <tr>
                                <td colspan="7">No recent job card activities found.</td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ($recent_activities as $activity) : ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($activity['job_card_id']); ?></td>
                                    <td><?php echo htmlspecialchars($activity['registration_number'] . ' (' . $activity['make'] . ' ' . $activity['model'] . ')'); ?></td>
                                    <td><?php echo htmlspecialchars($activity['driver_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($activity['description']); ?></td>
                                    <td>
                                        <span class="status-<?php echo strtolower(str_replace(' ', '-', $activity['status'])); ?>">
                                            <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $activity['status']))); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($activity['created_at']))); ?></td>
                                    <td>
                                        <button class="manage-button" data-job-id="<?php echo htmlspecialchars($activity['job_card_id']); ?>" data-current-status="<?php echo htmlspecialchars($activity['status']); ?>" data-assigned-mechanic="<?php echo htmlspecialchars($activity['assigned_to_mechanic_id'] ?? ''); ?>">
                                            <i class="fas fa-edit"></i> Manage
                                        </button>
                                        <button class="view-details-button" data-vehicle-id="<?php echo htmlspecialchars($activity['vehicle_id']); ?>">
                                            <i class="fas fa-info-circle"></i> Vehicle Details
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

    <div id="updateJobModal" class="modal">
        <div class="modal-content">
            <span class="close-button">&times;</span>
            <h2>Update Job Card Status</h2>
            <form id="updateJobForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="update_job_status">
                <input type="hidden" id="modalJobId" name="job_id">

                <div class="form-group">
                    <label for="modalStatus">Status:</label>
                    <select id="modalStatus" name="status" required>
                        <option value="pending">Pending</option>
                        <option value="in_progress">In Progress</option>
                        <option value="completed">Completed</option>
                        <option value="on_hold">On Hold</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="modalMechanic">Assign Mechanic:</label>
                    <select id="modalMechanic" name="mechanic_id">
                        <option value="">Select Mechanic (Optional)</option>
                        <?php foreach ($all_mechanics as $mechanic) : ?>
                            <option value="<?php echo htmlspecialchars($mechanic['user_id']); ?>">
                                <?php echo htmlspecialchars($mechanic['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit"><i class="fas fa-save"></i> Save Changes</button>
                <div id="updateJobMessage" class="message-container"></div>
            </form>
        </div>
    </div>

    <div id="vehicleDetailsModal" class="modal">
        <div class="modal-content">
            <span class="close-button">&times;</span>
            <h2>Vehicle Details</h2>
            <div id="vehicleDetailsContent">
                <div class="detail-item"><strong>Registration:</strong> <span id="detailRegNumber"></span></div>
                <div class="detail-item"><strong>Make:</strong> <span id="detailMake"></span></div>
                <div class="detail-item"><strong>Model:</strong> <span id="detailModel"></span></div>
                <div class="detail-item"><strong>Year:</strong> <span id="detailYear"></span></div>
                <div class="detail-item"><strong>Color:</strong> <span id="detailColor"></span></div>
                <div class="detail-item"><strong>Mileage:</strong> <span id="detailMileage"></span></div>
                <div class="detail-item"><strong>Assigned Driver:</strong> <span id="detailDriverName"></span></div>
            </div>
        </div>
    </div>


    <script>
        document.addEventListener('DOMContentLoaded', function() {
            //  URL REQUEST
            const BASE_URL = '<?php echo BASE_URL; ?>';

    
            const quickDriverForm = document.getElementById('quickDriverForm');
            const driverMessage = document.getElementById('driverMessage');

            if (quickDriverForm) {
                quickDriverForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    driverMessage.style.display = 'none'; 

                    const formData = new FormData(quickDriverForm);

                    fetch(window.location.href, { 
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                driverMessage.classList.remove('error');
                                driverMessage.classList.add('success');
                                quickDriverForm.reset(); 

                            } else {
                                driverMessage.classList.remove('success');
                                driverMessage.classList.add('error');
                            }
                            driverMessage.textContent = data.message;
                            driverMessage.style.display = 'block';
                        })
                        .catch(error => {
                            console.error('Error adding driver:', error);
                            driverMessage.classList.remove('success');
                            driverMessage.classList.add('error');
                            driverMessage.textContent = 'An unexpected error occurred. Please try again.';
                            driverMessage.style.display = 'block';
                        });
                });
            }

            const updateJobModal = document.getElementById('updateJobModal');
            const vehicleDetailsModal = document.getElementById('vehicleDetailsModal');

            document.querySelectorAll('.close-button').forEach(button => {
                button.addEventListener('click', function() {
                    this.closest('.modal').style.display = 'none';
                });
            });

            window.addEventListener('click', function(event) {
                if (event.target == updateJobModal) {
                    updateJobModal.style.display = 'none';
                }
                if (event.target == vehicleDetailsModal) {
                    vehicleDetailsModal.style.display = 'none';
                }
            });

        
            document.querySelectorAll('.manage-button').forEach(button => {
                button.addEventListener('click', function() {
                    const jobId = this.dataset.jobId;
                    const currentStatus = this.dataset.currentStatus;
                    const assignedMechanic = this.dataset.assignedMechanic;

                    document.getElementById('modalJobId').value = jobId;
                    document.getElementById('modalStatus').value = currentStatus;
                    document.getElementById('modalMechanic').value = assignedMechanic;

                    document.getElementById('updateJobMessage').style.display = 'none'; 
                    updateJobModal.style.display = 'flex'; 
                });
            });

            
            const updateJobForm = document.getElementById('updateJobForm');
            const updateJobMessage = document.getElementById('updateJobMessage');

            if (updateJobForm) {
                updateJobForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    updateJobMessage.style.display = 'none';

                    const formData = new FormData(updateJobForm);

                    fetch(window.location.href, { 
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                updateJobMessage.classList.remove('error');
                                updateJobMessage.classList.add('success');
                                updateJobMessage.textContent = data.message + ". Refreshing table...";

                                setTimeout(() => {
                                    location.reload(); 
                                }, 1500);
                            } else {
                                updateJobMessage.classList.remove('success');
                                updateJobMessage.classList.add('error');
                                updateJobMessage.textContent = data.message;
                            }
                            updateJobMessage.style.display = 'block';
                        })
                        .catch(error => {
                            console.error('Error updating job:', error);
                            updateJobMessage.classList.remove('success');
                            updateJobMessage.classList.add('error');
                            updateJobMessage.textContent = 'An unexpected error occurred. Please try again.';
                            updateJobMessage.style.display = 'block';
                        });
                });
            }


            document.querySelectorAll('.view-details-button').forEach(button => {
                button.addEventListener('click', function() {
                    const vehicleId = this.dataset.vehicleId;
                    
        
                    document.getElementById('detailRegNumber').textContent = '';
                    document.getElementById('detailMake').textContent = '';
                    document.getElementById('detailModel').textContent = '';
                    document.getElementById('detailYear').textContent = '';
                    document.getElementById('detailColor').textContent = '';
                    document.getElementById('detailMileage').textContent = '';
                    document.getElementById('detailDriverName').textContent = '';

                    fetch(`${BASE_URL}/dashboards/admin_dashboard.php?ajax=vehicle_details&vehicle_id=${vehicleId}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success && data.data) {
                                document.getElementById('detailRegNumber').textContent = data.data.registration_number;
                                document.getElementById('detailMake').textContent = data.data.make;
                                document.getElementById('detailModel').textContent = data.data.model;
                                document.getElementById('detailYear').textContent = data.data.year;
                                document.getElementById('detailColor').textContent = data.data.color;
                                document.getElementById('detailMileage').textContent = data.data.mileage + ' km';
                                document.getElementById('detailDriverName').textContent = data.data.driver_name || 'N/A';
                                vehicleDetailsModal.style.display = 'flex';
                            } else {
                                alert(data.message || 'Failed to load vehicle details.');
                            }
                        })
                        .catch(error => {
                            console.error('Error fetching vehicle details:', error);
                            alert('An error occurred while fetching vehicle details.');
                        });
                });
            });
        });
    </script>
</body>

</html>