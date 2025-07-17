<?php
session_start(); // Start the session at the very beginning

// --- SECURITY CHECKUP ---
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'supervisor') {
    error_log("Unauthorized access attempt to finance_dashboard.php by user_id: " . ($_SESSION['user_id'] ?? 'N/A') . " (User Type: " . ($_SESSION['user_type'] ?? 'N/A') . ")");
    header("Location: ../user_login.php");
    exit();
}

// --- DATABASE CONNECTION ---
include '../db_connect.php'; // Adjust path if your db_connect.php is in a different location relative to this file

// --- DATABASE CONNECTION VALIDATION ---
if (!isset($conn) || $conn->connect_error) {
    error_log("FATAL ERROR: Database connection failed in " . __FILE__ . ": " . ($conn->connect_error ?? 'Connection object not set.'));
    $_SESSION['error_message'] = "A critical system error occurred. Please try again later.";
    header("Location: ../user_login.php");
    exit();
}

// Include other necessary files AFTER the session and database connection are established
require_once '../auth_check.php'; // For hasPermission function
require_once '../config.php';
require_once '../functions.php'; // For formatDate and other utilities

// AJAX HANDLING for fetching job card details
if (isset($_GET['ajax']) && $_GET['ajax'] === 'job_card_details') {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'An unknown error occurred during AJAX request.']; // Default error message

    try {
        if (isset($_GET['job_card_id']) && is_numeric($_GET['job_card_id'])) {
            $job_card_id = (int)$_GET['job_card_id'];

            $query = "SELECT
                        jc.*,
                        u_created.full_name AS created_by_name,
                        v.make AS vehicle_make,
                        v.model AS vehicle_model,
                        v.year AS vehicle_year,
                        v.color AS vehicle_color,
                        v.registration_number AS vehicle_license,
                        v.v_milage AS vehicle_mileage,
                        u_driver.full_name AS driver_name,
                        u_mechanic.full_name AS mechanic_name,
                        u_advisor.full_name AS service_advisor_name
                      FROM job_cards jc
                      LEFT JOIN users u_created ON jc.created_by_user_id = u_created.user_id
                      LEFT JOIN vehicles v ON jc.vehicle_id = v.vehicle_id
                      LEFT JOIN users u_driver ON jc.driver_id = u_driver.user_id
                      LEFT JOIN users u_mechanic ON jc.mechanic_id = u_mechanic.user_id
                      LEFT JOIN users u_advisor ON jc.service_advisor_id = u_advisor.user_id
                      WHERE jc.job_card_id = ?";

            $stmt = $conn->prepare($query);
            if ($stmt) {
                $stmt->bind_param("i", $job_card_id);
                if ($stmt->execute()) { // Check execute success
                    $result = $stmt->get_result();
                    if ($result->num_rows > 0) {
                        $job_card_data = $result->fetch_assoc();

                        // Fetch parts used for this job card
                        $parts_used = [];
                        $parts_query = "SELECT jcp.quantity_used, inv.item_name, inv.price AS unit_price
                                        FROM job_card_parts jcp
                                        JOIN inventory inv ON jcp.item_id = inv.item_id
                                        WHERE jcp.job_card_id = ?";
                        $parts_stmt = $conn->prepare($parts_query);
                        if ($parts_stmt) {
                            $parts_stmt->bind_param("i", $job_card_id);
                            if ($parts_stmt->execute()) { // Check execute success
                                $parts_result = $parts_stmt->get_result();
                                while ($row = $parts_result->fetch_assoc()) {
                                    $parts_used[] = $row;
                                }
                            } else {
                                // Error during parts query execution
                                throw new Exception("SQL Error (parts execution): " . $parts_stmt->error);
                            }
                            $parts_stmt->close();
                        } else {
                            // Error during parts query preparation
                            throw new Exception("SQL Error (parts preparation): " . $conn->error);
                        }
                        $job_card_data['parts_used'] = $parts_used;

                        $response['success'] = true;
                        $response['data'] = $job_card_data;
                    } else {
                        $response['message'] = "Job card not found.";
                    }
                } else {
                    // Error during main query execution
                    throw new Exception("SQL Error (job card execution): " . $stmt->error);
                }
                $stmt->close();
            } else {
                throw new Exception("SQL Error (job card preparation): " . $conn->error);
            }
        } else {
            $response['message'] = "Invalid Job Card ID.";
        }
    } catch (Exception $e) {
        // Catch any exception and set the response message
        $response['message'] = $e->getMessage();
        error_log("AJAX Error in finance_dashboard.php: " . $e->getMessage()); // Log the error for server-side debugging
    } finally {
        // Ensure connection is closed even if an error occurs
        if (isset($conn) && $conn->ping()) { // ping() checks if connection is still alive
            $conn->close();
        }
    }
    echo json_encode($response);
    exit();
}

// NEW AJAX HANDLING for updating job card status from Finance Dashboard
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_job_card_status_finance') {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $response['message'] = 'Invalid CSRF token. Please refresh the page.';
        echo json_encode($response);
        exit();
    }

    $job_card_id = filter_input(INPUT_POST, 'job_card_id', FILTER_VALIDATE_INT);
    $new_status = filter_input(INPUT_POST, 'new_status', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $cancellation_reason = filter_input(INPUT_POST, 'cancellation_reason', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    if (!$job_card_id || !in_array($new_status, ['finance_received', 'finance_canceled'])) {
        $response['message'] = 'Invalid job card ID or status provided.';
        echo json_encode($response);
        exit();
    }

    try {
        $update_query = "UPDATE job_cards SET status = ?";
        $params = [$new_status];
        $types = "s";

        if ($new_status === 'finance_canceled') {
            $update_query .= ", cancellation_reason = ?, completed_at = NULL";
            $params[] = $cancellation_reason;
            $types .= "s";
        } elseif ($new_status === 'finance_received') {
            // If marking as received, clear cancellation reason and set completed_at if not already set by mechanic
            $update_query .= ", cancellation_reason = NULL";
            // Only update completed_at if it's currently NULL. This prevents overwriting mechanic's completion time.
            // Or, if finance_received implies a final completion timestamp from finance, you might set it here.
            // For now, let's keep it simple and just clear cancellation reason.
        }

        $update_query .= " WHERE job_card_id = ?";
        $params[] = $job_card_id;
        $types .= "i";

        $stmt = $conn->prepare($update_query);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Job card status updated successfully!';
            } else {
                $response['message'] = 'Database error: ' . $stmt->error;
                error_log("Finance status update SQL error: " . $stmt->error);
            }
            $stmt->close();
        } else {
            $response['message'] = 'Failed to prepare SQL statement: ' . $conn->error;
            error_log("Finance status update prepare error: " . $conn->error);
        }
    } catch (Exception $e) {
        $response['message'] = 'An error occurred: ' . $e->getMessage();
        error_log("Finance status update exception: " . $e->getMessage());
    } finally {
        if (isset($conn) && $conn->ping()) {
            $conn->close();
        }
    }
    echo json_encode($response);
    exit();
}


// Get all job cards
$jobCards = [];
// This query fetches all job cards regardless of status
$jobCardsQuery = "SELECT j.*, u.full_name as created_by_name_for_job_card, v.make as vehicle_make, v.model as vehicle_model, v.registration_number as vehicle_license, d.full_name as driver_name,
                      mech.full_name as mechanic_name, adv.full_name as service_advisor_name
                  FROM job_cards j
                  JOIN users u ON j.created_by_user_id = u.user_id
                  JOIN vehicles v ON j.vehicle_id = v.vehicle_id
                  LEFT JOIN users d ON j.created_by_user_id = d.user_id
                  LEFT JOIN users mech ON j.assigned_to_mechanic_id = mech.user_id
                  LEFT JOIN users adv ON j.service_advisor_id = adv.user_id
                  ORDER BY j.created_at DESC";
$jobCardsResult = $conn->query($jobCardsQuery);

if ($jobCardsResult) {
    while ($row = $jobCardsResult->fetch_assoc()) {
        $jobCards[] = $row;
    }
    $jobCardsResult->free();
} else {
    error_log("Error fetching job cards: " . $conn->error);
    $_SESSION['error_message'] = "Could not load job cards. Please try again.";
    error_log("Job Cards Query Error: " . $conn->error);
}

// Get all invoices
$invoices = [];
// Corrected JOIN conditions for users and qualified ambiguous columns
// Ensure mechanic_id and service_advisor_id are selected from invoices table if they are stored there
$invoicesQuery = "SELECT i.invoice_id, i.job_card_id, i.labor_cost, i.parts_cost, i.total_amount, i.invoice_date,
                         jc.description AS job_description, jc.created_at as job_card_created_at,
                         v.make AS vehicle_make, v.model AS vehicle_model, v.registration_number AS vehicle_license,
                         d.full_name AS driver_name,
                         mech.full_name AS mechanic_name,
                         adv.full_name AS service_advisor_name,
                         u_gen.full_name as generated_by_name
                  FROM invoices i
                  JOIN job_cards jc ON i.job_card_id = jc.job_card_id
                  JOIN vehicles v ON jc.vehicle_id = v.vehicle_id
                  LEFT JOIN users d ON jc.created_by_user_id = d.user_id
                  LEFT JOIN users mech ON i.assigned_to_mechanic_id = mech.user_id -- Join on invoice's mechanic_id
                  LEFT JOIN users adv ON i.service_advisor_id = adv.user_id -- Join on invoice's service_advisor_id
                  JOIN users u_gen ON i.generated_by = u_gen.user_id
                  ORDER BY i.invoice_date DESC"; // Order by invoice creation date
$invoicesResult = $conn->query($invoicesQuery);
if ($invoicesResult) {
    while ($row = $invoicesResult->fetch_assoc()) {
        $invoices[] = $row;
    }
    $invoicesResult->free();
    error_log("Invoices fetched: " . print_r($invoices, true));
} else {
    error_log("Error fetching invoices: " . $conn->error);
    $_SESSION['error_message'] = "Could not load invoices. Please try again.";
    error_log("Invoices Query Error: " . $conn->error);
}

// Get all mechanics for the dropdown (for display in modal, not for selection to create new job)
$mechanics = [];
$mechanicsQuery = "SELECT user_id, full_name FROM users WHERE user_type = 'mechanic' ORDER BY full_name ASC";
$mechanicsResult = $conn->query($mechanicsQuery);
if ($mechanicsResult) {
    while ($row = $mechanicsResult->fetch_assoc()) {
        $mechanics[] = $row;
    }
    $mechanicsResult->free();
} else {
    error_log("Error fetching mechanics: " . $conn->error);
}


// Get supervisor's name from session if available
$supervisorName = $_SESSION['full_name'] ?? 'Supervisor';

// Check for any page messages from previous operations
$page_message = '';
$page_message_type = '';
if (isset($_SESSION['success_message'])) {
    $page_message = $_SESSION['success_message'];
    $page_message_type = 'success';
    unset($_SESSION['success_message']);
} elseif (isset($_SESSION['error_message'])) {
    $page_message = $_SESSION['error_message'];
    $page_message_type = 'error';
    unset($_SESSION['error_message']);
}

// Close database connection
if (isset($conn)) {
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finance Dashboard - Lewa Workshop</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/xlsx/dist/xlsx.full.min.js"></script> <!-- For export to Excel -->
    <style>
        /* Your existing CSS styles remain here, with the addition of
           the corrected and complete closing tags for the form. */
        :root {
            --primary: #3498db;
            --secondary: #2c3e50;
            --success: #2ecc71;
            --danger: #e74c3c;
            --warning: #f39c12;
            --info: #17a2b8;
            --light: #ecf0f1;
            --dark: #2c3e50;
            --text-color: #333;
            --border-color: #ddd;
            --card-bg: #fff;
            --header-bg: #ecf0f1;
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
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
        }

        .sidebar h2 {
            text-align: center;
            color: var(--primary);
            margin-bottom: 30px;
            font-size: 1.8em;
            border-bottom: 2px solid var(--primary);
            padding-bottom: 15px;
        }

        .sidebar p {
            text-align: center;
            margin-bottom: 20px;
            font-size: 1.1em;
            color: var(--light);
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
            padding: 12px 15px;
            border-radius: 5px;
            transition: background-color 0.3s ease, color 0.3s ease;
            font-size: 1.05em;
        }

        .sidebar nav ul li a i {
            margin-right: 10px;
            font-size: 1.2em;
        }

        .sidebar nav ul li a:hover,
        .sidebar nav ul li a.active {
            background-color: var(--secondary);
            color: var(--primary);
        }

        .sidebar nav ul li a.active {
            border-left: 5px solid var(--primary);
            padding-left: 10px;
        }

        .main-content {
            padding: 30px;
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 0 15px rgba(0,0,0,0.05);
            margin: 20px;
        }

        h1, h2 {
            color: var(--secondary);
            margin-bottom: 20px;
        }

        .header-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 15px;
        }

        .user-greeting {
            font-size: 1.2em;
            font-weight: bold;
            color: var(--dark);
        }

        .tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 2px solid var(--border-color);
        }

        .tab-btn {
            background-color: var(--header-bg);
            border: none;
            padding: 10px 20px;
            cursor: pointer;
            font-size: 1em;
            border-top-left-radius: 5px;
            border-top-right-radius: 5px;
            transition: background-color 0.3s ease;
            color: var(--dark);
        }

        .tab-btn:hover {
            background-color: #e2e2e2;
        }

        .tab-btn.active {
            background-color: var(--primary);
            color: white;
            border-bottom: 2px solid var(--primary);
        }

        .tab-content {
            padding: 20px 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border-radius: 8px;
            overflow: hidden;
        }

        table th, table td {
            border: 1px solid var(--border-color);
            padding: 12px 15px;
            text-align: left;
        }

        table th {
            background-color: var(--header-bg);
            color: var(--secondary);
            font-weight: bold;
            text-transform: uppercase;
            font-size: 0.9em;
        }

        table tr:nth-child(even) {
            background-color: var(--light);
        }

        table tr:hover {
            background-color: #f1f1f1;
        }

        .btn {
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9em;
            transition: background-color 0.3s ease, transform 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn.small {
            padding: 6px 12px;
            font-size: 0.85em;
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background-color: #288ad6;
            transform: translateY(-2px);
        }

        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background-color: #5a6268;
            transform: translateY(-2px);
        }

        .status-badge {
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 0.75em;
            font-weight: bold;
            color: white;
            display: inline-block;
        }

        .status-badge.pending { background-color: var(--warning); }
        .status-badge.in-progress { background-color: var(--info); }
        .status-badge.completed { background-color: var(--success); }
        .status-badge.assigned { background-color: var(--primary); }
        .status-badge.invoiced { background-color: #9b59b6; }
        .status-badge.cancelled { background-color: var(--danger); }
        .status-badge.finance-received { background-color: #28a745; } /* New color for received */
        .status-badge.finance-canceled { background-color: #dc3545; } /* New color for canceled */


        /* Report options grid */
        .report-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 25px;
            margin-top: 30px;
        }

        .report-card {
            background-color: var(--header-bg);
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.08);
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            text-align: center;
        }

        .report-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.15);
        }

        .report-card h3 {
            margin-top: 0;
            color: var(--secondary);
            font-size: 1.3em;
            margin-bottom: 10px;
        }
        .report-card p {
            color: #555;
            font-size: 0.9em;
        }

        /* Modal Styles */
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
        }

        .modal-content {
            background-color: #fefefe;
            padding: 30px;
            border: 1px solid #888;
            width: 90%;
            max-width: 550px;
            border-radius: 10px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.35);
            position: relative;
        }

        .close-button {
            color: #aaa;
            float: right;
            font-size: 32px;
            font-weight: bold;
            position: absolute;
            top: 15px;
            right: 25px;
            cursor: pointer;
        }

        .close-button:hover,
        .close-button:focus {
            color: #333;
            text-decoration: none;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: var(--secondary);
            font-size: 0.95em;
        }

        .form-group input[type="date"],
        .form-group select,
        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group textarea {
            width: calc(100% - 24px);
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            box-sizing: border-box;
            font-size: 1em;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .form-group input[type="date"]:focus,
        .form-group select:focus,
        .form-group input[type="text"]:focus,
        .form-group input[type="number"]:focus,
        .form-group textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
            outline: none;
        }

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

        .notification-success {
            background-color: var(--success);
        }

        .notification-error {
            background-color: var(--danger);
        }

        .notification i {
            margin-right: 10px;
        }

        /* Responsive Adjustments */
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
                margin: 10px;
            }

            h1 {
                font-size: 1.8rem;
                text-align: center;
            }
            h2 {
                font-size: 1.5rem;
            }

            .report-options {
                grid-template-columns: 1fr;
            }

            table {
                font-size: 0.85em;
            }
            table th, table td {
                padding: 10px;
            }

            .modal-content {
                width: 95%;
                margin: 10px auto;
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
            .report-card h3 {
                font-size: 1.1rem;
            }
            .btn {
                font-size: 0.8rem;
                padding: 6px 10px;
            }
            .notification {
                font-size: 0.9em;
                padding: 10px 15px;
                right: 10px;
                left: 10px;
                top: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="sidebar">
            <h2>Lewa Workshop</h2>
            <p>Welcome, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Supervisor'); ?></p>

            <nav>
                <ul>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/admin_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/finance_dashboard.php" class="active"><i class="fas fa-dollar-sign"></i> Finance</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </nav>
        </div>

        <div class="main-content">
            <div class="header-section">
                <h1>Finance Dashboard</h1>
                <div class="user-greeting">
                    Welcome, <?php echo htmlspecialchars($supervisorName); ?> (Supervisor)
                </div>
            </div>

            <div id="notification" class="notification"></div>

            <div class="tabs">
                <button class="tab-btn active" onclick="openTab(event, 'jobCards')">Job Cards</button>
                <button class="tab-btn" onclick="openTab(event, 'invoices')">Invoices</button>
                <button class="tab-btn" onclick="openTab(event, 'reports')">Reports</button>
            </div>

            <div id="jobCards" class="tab-content" style="display: block;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h2>All Job Cards</h2>
                    <button class="btn btn-secondary" onclick="location.reload();"><i class="fas fa-sync-alt"></i> Refresh Job Cards</button>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Driver</th>
                            <th>Vehicle</th>
                            <th>License</th>
                            <th>Status</th>
                            <th>Mechanic</th>
                            <th>Labor Cost</th>
                            <th>Service Advisor</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($jobCards)): ?>
                            <?php foreach ($jobCards as $job): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($job['job_card_id']); ?></td>
                                <td><?php echo htmlspecialchars($job['driver_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($job['vehicle_make'] . ' ' . $job['vehicle_model'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($job['vehicle_license'] ?? 'N/A'); ?></td>
                                <td><span class="status-badge <?php echo str_replace('_', '-', $job['status'] ?? 'unknown'); ?>">
                                        <?php echo ucwords(str_replace('_', ' ', $job['status'] ?? 'Unknown')); ?>
                                </span></td>
                                <td><?php echo htmlspecialchars($job['mechanic_name'] ?? 'N/A'); ?></td>
                                <td>KSh <?php echo htmlspecialchars(number_format($job['labor_cost'] ?? 0.00, 2)); ?></td>
                                <td><?php echo htmlspecialchars($job['service_advisor_name'] ?? 'N/A'); ?></td>
                                <td><?php echo formatDate($job['created_at'] ?? ''); ?></td>
                                <td>
                                    <!-- Modified "View" button to open a modal -->
                                    <button onclick="openJobCardDetailsModal(<?php echo htmlspecialchars($job['job_card_id']); ?>)" class="btn small btn-secondary"><i class="fas fa-eye"></i> View</button>
                                    <?php if (($job['status'] ?? '') === 'completed' && !empty($job['labor_cost'])): ?>
                                        <button onclick="openCreateInvoiceModal(<?php echo htmlspecialchars(json_encode($job)); ?>)" class="btn small btn-primary"><i class="fas fa-file-invoice"></i> Create Invoice</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="10" style="text-align: center;">No job cards found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div id="invoices" class="tab-content">
                <h2>Generated Invoices</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Job Card ID</th>
                            <th>Vehicle</th>
                            <th>Driver</th>
                            <th>Mechanic</th>
                            <th>Service Advisor</th>
                            <th>Labor Cost</th>
                            <th>Parts Cost</th>
                            <th>Total Amount</th>
                            <th>Invoice Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($invoices)): ?>
                            <?php foreach ($invoices as $invoice): ?>
                            <tr>
                                <td>INV-<?php echo htmlspecialchars($invoice['invoice_id']); ?></td>
                                <td>JC-<?php echo htmlspecialchars($invoice['job_card_id']); ?></td>
                                <td><?php echo htmlspecialchars($invoice['vehicle_make'] . ' ' . $invoice['vehicle_model'] . ' (' . $invoice['vehicle_license'] . ')'); ?></td>
                                <td><?php echo htmlspecialchars($invoice['driver_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($invoice['mechanic_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($invoice['service_advisor_name'] ?? 'N/A'); ?></td>
                                <td>KSh <?php echo number_format($invoice['labor_cost'] ?? 0, 2); ?></td>
                                <td>KSh <?php echo number_format($invoice['parts_cost'] ?? 0, 2); ?></td>
                                <td>KSh <?php echo number_format($invoice['total_amount'] ?? 0, 2); ?></td>
                                <td><?php echo formatDate($invoice['invoice_date'] ?? ''); ?></td>
                                <td>
                                    <a href="view_invoice.php?invoice_id=<?php echo htmlspecialchars($invoice['invoice_id']); ?>" target="_blank" class="btn small btn-secondary"><i class="fas fa-eye"></i> View/Print</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="11" style="text-align: center;">No invoices found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div id="reports" class="tab-content">
                <h2>Financial Reports</h2>
                <div class="report-options">
                    <div class="report-card" onclick="generateReport('daily')">
                        <h3>Daily Report</h3>
                        <p>Generate today's financial summary</p>
                    </div>
                    <div class="report-card" onclick="generateReport('weekly')">
                        <h3>Weekly Report</h3>
                        <p>Generate this week's financial summary</p>
                    </div>
                    <div class="report-card" onclick="generateReport('monthly')">
                        <h3>Monthly Report</h3>
                        <p>Generate this month's financial summary</p>
                    </div>
                    <div class="report-card" onclick="openCustomReportModal()">
                        <h3>Custom Report</h3>
                        <p>Generate report for custom date range</p>
                    </div>
                </div>

                <div id="reportResults" style="margin-top: 20px; display: none;">
                    <h3>Report Results</h3>
                    <div id="reportData"></div>
                    <button onclick="printReport()" class="btn btn-primary"><i class="fas fa-print"></i> Print Report</button>
                    <button onclick="exportToExcel()" class="btn btn-primary"><i class="fas fa-file-excel"></i> Export to Excel</button>
                </div>
            </div>
        </div>
    </div>

    <div id="createInvoiceModal" class="modal">
        <div class="modal-content">
            <span class="close-button" onclick="closeModal('createInvoiceModal')">&times;</span>
            <h2>Create Invoice</h2>
            <form id="createInvoiceForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="job_card_id" id="invoiceJobCardId">
                <input type="hidden" name="parts_cost" id="hiddenInvoicePartsCost"> <!-- Added hidden input for parts_cost -->

                <div class="form-group">
                    <label for="invoiceVehicleInfo">Vehicle:</label>
                    <input type="text" id="invoiceVehicleInfo" class="form-control" readonly>
                </div>
                <div class="form-group">
                    <label for="invoiceJobDescription">Job Description:</label>
                    <textarea id="invoiceJobDescription" class="form-control" rows="3" readonly></textarea>
                </div>
                <div class="form-group">
                    <label for="invoiceServiceAdvisor">Service Advisor:</label>
                    <input type="text" id="invoiceServiceAdvisor" class="form-control" readonly>
                </div>
                <div class="form-group">
                    <label for="invoiceJobDate">Job Card Date:</label>
                    <input type="date" id="invoiceJobDate" class="form-control" readonly>
                </div>
                <div class="form-group">
                    <label for="invoiceMechanicSelect">Assigned Mechanic:</label>
                    <!-- This dropdown will display the assigned mechanic but will be readonly -->
                    <select id="invoiceMechanicSelect" name="mechanic_id" class="form-control" disabled>
                        <option value="">Select Mechanic</option>
                        <?php foreach ($mechanics as $mechanic): ?>
                            <option value="<?php echo htmlspecialchars($mechanic['user_id']); ?>">
                                <?php echo htmlspecialchars($mechanic['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <!-- A hidden input to actually send the mechanic_id if needed, as the select is disabled -->
                    <input type="hidden" name="mechanic_id_hidden" id="mechanicIdHidden">
                </div>
                <div class="form-group">
                    <label for="invoiceLaborCost">Labor Cost (KSh):</label>
                    <input type="number" id="invoiceLaborCost" name="labor_cost" class="form-control" step="0.01" min="0" required>
                </div>

                <h3>Parts Used:</h3>
                <div id="invoicePartsUsed" style="max-height: 150px; overflow-y: auto; border: 1px solid #eee; padding: 10px; border-radius: 5px;">
                    <p>No parts recorded for this job card.</p>
                </div>
                <p style="text-align: right; margin-top: 10px;"><strong>Parts Total: KSh <span id="invoicePartsTotal">0.00</span></strong></p>
                <div class="form-group">
                    <label for="invoiceTotalAmount">Total Amount (Labor + Parts):</label>
                    <input type="number" id="invoiceTotalAmount" name="total_amount" class="form-control" step="0.01" min="0" readonly>
                </div>
                <div style="text-align: right;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-file-invoice"></i> Generate Invoice</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Custom Report Modal (moved here for better structure) -->
    <div id="customReportModal" class="modal">
        <div class="modal-content">
            <span class="close-button" onclick="closeModal('customReportModal')">&times;</span>
            <h2>Generate Custom Report</h2>
            <form onsubmit="event.preventDefault(); generateCustomReport();">
                <div class="form-group">
                    <label for="startDate">Start Date:</label>
                    <input type="date" id="startDate" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="endDate">End Date:</label>
                    <input type="date" id="endDate" class="form-control" required>
                </div>
                <div style="text-align: right;">
                    <button type="submit" class="btn btn-primary">Generate</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Job Card Details Modal -->
    <div id="jobCardDetailsModal" class="modal">
        <div class="modal-content">
            <span class="close-button" onclick="closeModal('jobCardDetailsModal')">&times;</span>
            <h2>Job Card Details</h2>
            <div id="jobCardDetailsContent">
                <!-- Details will be loaded here via JavaScript -->
                <div class="form-group">
                    <label>Job Card ID:</label>
                    <p id="detail-job-card-id"></p>
                </div>
                <div class="form-group">
                    <label>Vehicle:</label>
                    <p id="detail-vehicle"></p>
                </div>
                <div class="form-group">
                    <label>Driver:</label>
                    <p id="detail-driver"></p>
                </div>
                <div class="form-group">
                    <label>Issue Description:</label>
                    <p id="detail-issue-description"></p>
                </div>
                <div class="form-group">
                    <label>Status:</label>
                    <p id="detail-status"></p>
                </div>
                <div class="form-group">
                    <label>Assigned Mechanic:</label>
                    <p id="detail-mechanic"></p>
                </div>
                <div class="form-group">
                    <label>Service Advisor:</label>
                    <p id="detail-service-advisor"></p>
                </div>
                <div class="form-group">
                    <label>Labor Cost:</label>
                    <p id="detail-labor-cost"></p>
                </div>
                <div class="form-group">
                    <label>Created At:</label>
                    <p id="detail-created-at"></p>
                </div>
                <div class="form-group">
                    <label>Completed At:</label>
                    <p id="detail-completed-at"></p>
                </div>
                <h3>Parts Used:</h3>
                <div id="detail-parts-used" style="max-height: 150px; overflow-y: auto; border: 1px solid #eee; padding: 10px; border-radius: 5px;">
                    <p>No parts recorded.</p>
                </div>
                <hr style="margin: 25px 0; border-top: 1px solid #eee;">
                <h3>Finance Actions</h3>
                <input type="hidden" id="finance-job-card-id-hidden">
                <div class="form-group">
                    <label for="finance-status-select">Update Job Status:</label>
                    <select id="finance-status-select" class="form-control">
                        <option value="">Select Action</option>
                        <option value="finance_received">Mark as Received (Finance)</option>
                        <option value="finance_canceled">Mark as Canceled (Finance)</option>
                    </select>
                </div>
                <div class="form-group" id="cancellationReasonGroup" style="display: none;">
                    <label for="cancellation-reason">Cancellation Reason:</label>
                    <textarea id="cancellation-reason" class="form-control" rows="2" placeholder="Enter reason for cancellation (optional)"></textarea>
                </div>
                <div style="text-align: right;">
                    <button id="updateFinanceStatusBtn" class="btn btn-primary"><i class="fas fa-check-circle"></i> Update Finance Status</button>
                </div>
            </div>
        </div>
    </div>


    <script>
    // Show a notification message
    function showNotification(message, type) {
        const notification = document.getElementById('notification');
        notification.textContent = message;
        notification.className = `notification notification-${type}`;

        // Show the notification
        notification.classList.add('show');

        // Hide the notification after 5 seconds
        setTimeout(() => {
            notification.classList.remove('show');
        }, 5000);
    }

    // Function to handle tab switching
    function openTab(evt, tabName) {
        let i, tabContent, tabBtn;
        tabContent = document.getElementsByClassName("tab-content");
        for (i = 0; i < tabContent.length; i++) {
            tabContent[i].style.display = "none";
        }
        tabBtn = document.getElementsByClassName("tab-btn");
        for (i = 0; i < tabBtn.length; i++) {
            tabBtn[i].className = tabBtn[i].className.replace(" active", "");
        }
        document.getElementById(tabName).style.display = "block";
        if (evt) { // Check if event is passed (it won't be on initial load)
            evt.currentTarget.className += " active";
        }
    }

    // Modal functions
    function closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.style.display = 'none';
            // Remove the dynamically added custom report modal if it exists
            if (modalId === 'customReportModal') {
                modal.remove();
            }
        }
    }

    // Function to open the Create Invoice Modal
    async function openCreateInvoiceModal(jobCard) {
        console.log("Job Card Data:", jobCard); // Debugging
        const modal = document.getElementById('createInvoiceModal');
        modal.style.display = 'flex';

        // Populate modal fields
        document.getElementById('invoiceJobCardId').value = jobCard.job_card_id;
        document.getElementById('invoiceVehicleInfo').value = `${jobCard.vehicle_make} ${jobCard.vehicle_model} (${jobCard.vehicle_license})`;
        document.getElementById('invoiceJobDescription').value = jobCard.description;
        document.getElementById('invoiceServiceAdvisor').value = jobCard.service_advisor_name || 'N/A';
        document.getElementById('invoiceLaborCost').value = parseFloat(jobCard.labor_cost).toFixed(2); // Ensure 2 decimal places

        // Format the date for the date input
        const jobCardDate = new Date(jobCard.created_at);
        const formattedDate = jobCardDate.toISOString().split('T')[0];
        document.getElementById('invoiceJobDate').value = formattedDate;

        // Set assigned mechanic and make it readonly
        const mechanicSelect = document.getElementById('invoiceMechanicSelect');
        mechanicSelect.value = jobCard.mechanic_id;
        document.getElementById('mechanicIdHidden').value = jobCard.mechanic_id; // Set hidden input value

        // Fetch parts used for the job card
        try {
            const response = await fetch(`fetch_job_card_parts.php?job_card_id=${jobCard.job_card_id}`);
            const data = await response.json();
            console.log("Parts Data:", data); // Debugging

            const partsList = document.getElementById('invoicePartsUsed');
            let partsTotal = 0;
            partsList.innerHTML = ''; // Clear previous content

            if (data.success && data.parts && data.parts.length > 0) {
                let listHtml = '<ul>';
                data.parts.forEach(part => {
                    const subtotal = parseFloat(part.unit_price) * parseInt(part.quantity_used);
                    partsTotal += subtotal;
                    listHtml += `<li><strong>${part.item_name}</strong> - Quantity: ${part.quantity_used}, Unit Price: KSh ${parseFloat(part.unit_price).toFixed(2)}, Subtotal: KSh ${subtotal.toFixed(2)}</li>`;
                });
                listHtml += '</ul>';
                partsList.innerHTML = listHtml;
            } else {
                partsList.innerHTML = '<p>No parts recorded for this job card.</p>';
            }

            document.getElementById('invoicePartsTotal').textContent = partsTotal.toFixed(2);
            document.getElementById('hiddenInvoicePartsCost').value = partsTotal.toFixed(2); // Set hidden parts cost

            // Calculate and set total amount
            const laborCost = parseFloat(jobCard.labor_cost) || 0;
            const totalAmount = laborCost + partsTotal;
            document.getElementById('invoiceTotalAmount').value = totalAmount.toFixed(2);
        } catch (error) {
            console.error('Error fetching parts:', error);
            showNotification("Failed to load parts. Please try again.", "error");
            document.getElementById('invoicePartsUsed').innerHTML = '<p style="color:red;">Error loading parts.</p>';
            document.getElementById('invoicePartsTotal').textContent = '0.00';
            document.getElementById('hiddenInvoicePartsCost').value = '0.00';
            const laborCost = parseFloat(jobCard.labor_cost) || 0;
            document.getElementById('invoiceTotalAmount').value = laborCost.toFixed(2);
        }
    }

    // Function to handle invoice form submission
    document.getElementById('createInvoiceForm').addEventListener('submit', async function(event) {
        event.preventDefault();

        const form = event.target;
        const formData = new FormData(form);

        // Add the hidden mechanic ID and parts cost to formData explicitly
        formData.set('mechanic_id', document.getElementById('mechanicIdHidden').value);
        formData.set('parts_cost', document.getElementById('hiddenInvoicePartsCost').value);

        try {
            const response = await fetch('create_invoice.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                showNotification(result.message, 'success');
                closeModal('createInvoiceModal');
                // Reload the page to show the new invoice and updated job card status
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                showNotification(result.message, 'error');
            }
        } catch (error) {
            console.error('Error:', error);
            showNotification('An unexpected error occurred. Please try again.', 'error');
        }
    });

    // Function to open Job Card Details Modal
    async function openJobCardDetailsModal(jobCardId) {
        const modal = document.getElementById('jobCardDetailsModal');
        const contentDiv = document.getElementById('jobCardDetailsContent');
        // Clear previous content and show loading indicator
        contentDiv.innerHTML = '<p style="text-align: center; font-weight: bold; color: #3498db;">Loading job card details...</p>';
        modal.style.display = 'flex';

        // Set the job card ID in the hidden input for finance actions
        document.getElementById('finance-job-card-id-hidden').value = jobCardId;
        // Reset the finance status dropdown and cancellation reason
        document.getElementById('finance-status-select').value = '';
        document.getElementById('cancellationReasonGroup').style.display = 'none';
        document.getElementById('cancellation-reason').value = '';


        try {
            const response = await fetch(`finance_dashboard.php?ajax=job_card_details&job_card_id=${jobCardId}`);
            if (!response.ok) { // Check for HTTP errors (e.g., 404, 500)
                const errorText = await response.text(); // Get raw response text
                contentDiv.innerHTML = `<p style="text-align: center; color: red; font-weight: bold;">HTTP Error! Status: ${response.status}. Server Response: ${errorText}</p>`;
                console.error('HTTP Error fetching job card details:', errorText);
                return; // Exit function
            }
            const result = await response.json();

            if (result.success) {
                const job = result.data;
                // Re-populate the contentDiv with the actual structure and data
                contentDiv.innerHTML = `
                    <div class="form-group">
                        <label>Job Card ID:</label>
                        <p id="detail-job-card-id">${job.job_card_id}</p>
                    </div>
                    <div class="form-group">
                        <label>Vehicle:</label>
                        <p id="detail-vehicle">${job.vehicle_make} ${job.vehicle_model} (${job.vehicle_license})</p>
                    </div>
                    <div class="form-group">
                        <label>Driver:</label>
                        <p id="detail-driver">${job.driver_name || 'N/A'}</p>
                    </div>
                    <div class="form-group">
                        <label>Issue Description:</label>
                        <p id="detail-issue-description">${job.description}</p>
                    </div>
                    <div class="form-group">
                        <label>Status:</label>
                        <p id="detail-status"><span class="status-badge ${job.status.replace('_', '-')}">${job.status.replace('_', ' ').split(' ').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ')}</span></p>
                    </div>
                    <div class="form-group">
                        <label>Assigned Mechanic:</label>
                        <p id="detail-mechanic">${job.mechanic_name || 'N/A'}</p>
                    </div>
                    <div class="form-group">
                        <label>Service Advisor:</label>
                        <p id="detail-service-advisor">${job.service_advisor_name || 'N/A'}</p>
                    </div>
                    <div class="form-group">
                        <label>Labor Cost:</label>
                        <p id="detail-labor-cost">KSh ${parseFloat(job.labor_cost).toFixed(2)}</p>
                    </div>
                    <div class="form-group">
                        <label>Created At:</label>
                        <p id="detail-created-at">${new Date(job.created_at).toLocaleString()}</p>
                    </div>
                    <div class="form-group">
                        <label>Completed At:</label>
                        <p id="detail-completed-at">${job.completed_at ? new Date(job.completed_at).toLocaleString() : 'N/A'}</p>
                    </div>
                    <h3>Parts Used:</h3>
                    <div id="detail-parts-used" style="max-height: 150px; overflow-y: auto; border: 1px solid #eee; padding: 10px; border-radius: 5px;">
                        <p>No parts recorded.</p>
                    </div>
                    <hr style="margin: 25px 0; border-top: 1px solid #eee;">
                    <h3>Finance Actions</h3>
                    <input type="hidden" id="finance-job-card-id-hidden" value="${jobCardId}">
                    <div class="form-group">
                        <label for="finance-status-select">Update Job Status:</label>
                        <select id="finance-status-select" class="form-control">
                            <option value="">Select Action</option>
                            <option value="finance_received">Mark as Received (Finance)</option>
                            <option value="finance_canceled">Mark as Canceled (Finance)</option>
                        </select>
                    </div>
                    <div class="form-group" id="cancellationReasonGroup" style="display: none;">
                        <label for="cancellation-reason">Cancellation Reason:</label>
                        <textarea id="cancellation-reason" class="form-control" rows="2" placeholder="Enter reason for cancellation (optional)"></textarea>
                    </div>
                    <div style="text-align: right;">
                        <button id="updateFinanceStatusBtn" class="btn btn-primary"><i class="fas fa-check-circle"></i> Update Finance Status</button>
                    </div>
                `;

                // Set the finance status dropdown to the current job status if applicable
                const financeStatusSelect = document.getElementById('finance-status-select');
                if (job.status === 'finance_received' || job.status === 'finance_canceled') {
                    financeStatusSelect.value = job.status;
                    // If it's canceled, show the reason and populate it
                    if (job.status === 'finance_canceled') {
                        document.getElementById('cancellationReasonGroup').style.display = 'block';
                        document.getElementById('cancellation-reason').value = job.cancellation_reason || '';
                    }
                }


                // Re-attach event listener for the new select element
                document.getElementById('finance-status-select').addEventListener('change', function() {
                    const selectedStatus = this.value;
                    const cancellationReasonGroup = document.getElementById('cancellationReasonGroup');
                    if (selectedStatus === 'finance_canceled') {
                        cancellationReasonGroup.style.display = 'block';
                    } else {
                        cancellationReasonGroup.style.display = 'none';
                        document.getElementById('cancellation-reason').value = ''; // Clear reason if not canceled
                    }
                });

                // Re-attach event listener for the new update button
                document.getElementById('updateFinanceStatusBtn').addEventListener('click', async function() {
                    const currentJobCardId = document.getElementById('finance-job-card-id-hidden').value;
                    const newStatus = document.getElementById('finance-status-select').value;
                    const cancellationReason = document.getElementById('cancellation-reason').value;
                    const csrfToken = "<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>";

                    if (!newStatus) {
                        showNotification('Please select a status to update.', 'error');
                        return;
                    }

                    if (newStatus === 'finance_canceled' && !cancellationReason.trim()) {
                        showNotification('Please provide a reason for cancellation.', 'error');
                        return;
                    }

                    const formData = new FormData();
                    formData.append('action', 'update_job_card_status_finance');
                    formData.append('job_card_id', currentJobCardId);
                    formData.append('new_status', newStatus);
                    formData.append('cancellation_reason', cancellationReason);
                    formData.append('csrf_token', csrfToken);

                    try {
                        const updateResponse = await fetch('finance_dashboard.php', {
                            method: 'POST',
                            body: formData
                        });
                        const updateResult = await updateResponse.json();

                        if (updateResult.success) {
                            showNotification(updateResult.message, 'success');
                            closeModal('jobCardDetailsModal');
                            setTimeout(() => {
                                window.location.reload();
                            }, 1000);
                        } else {
                            showNotification(updateResult.message, 'error');
                        }
                    } catch (updateError) {
                        console.error('Error updating finance status:', updateError);
                        showNotification('An error occurred while updating the job card status. Details: ' + updateError.message, 'error');
                    }
                });


                // Populate parts used after the contentDiv is updated
                const partsUsedDiv = document.getElementById('detail-parts-used');
                if (job.parts_used && job.parts_used.length > 0) {
                    let partsHtml = '<ul>';
                    job.parts_used.forEach(part => {
                        partsHtml += `<li><strong>${part.item_name}</strong> - Quantity: ${part.quantity_used}, Unit Price: KSh ${parseFloat(part.unit_price).toFixed(2)}, Subtotal: KSh ${(parseFloat(part.unit_price) * parseInt(part.quantity_used)).toFixed(2)}</li>`;
                    });
                    partsHtml += '</ul>';
                    partsUsedDiv.innerHTML = partsHtml;
                } else {
                    partsUsedDiv.innerHTML = '<p>No parts recorded for this job card.</p>';
                }

            } else {
                contentDiv.innerHTML = `<p style="text-align: center; color: red; font-weight: bold;">Error: ${result.message}</p>`;
            }
        } catch (error) {
            console.error('Error fetching job card details:', error);
            contentDiv.innerHTML = `<p style="text-align: center; color: red; font-weight: bold;">An error occurred while fetching job card details. Details: ${error.message}</p>`;
        }
    }


    // Function to handle report generation
    async function generateReport(type, startDate = null, endDate = null) {
        const reportDataDiv = document.getElementById('reportData');
        const reportResultsDiv = document.getElementById('reportResults');
        const params = new URLSearchParams({ type: type });

        if (startDate) params.append('start_date', startDate);
        if (endDate) params.append('end_date', endDate);

        reportDataDiv.innerHTML = '<p>Generating report...</p>';
        reportResultsDiv.style.display = 'block';

        try {
            const response = await fetch(`generate_report.php?${params.toString()}`);
            const data = await response.json();

            if (data.success) {
                // Display the report data
                reportDataDiv.innerHTML = `
                    <div style="border: 1px solid #ccc; padding: 15px; border-radius: 8px; background-color: #f9f9f9; margin-bottom: 20px;">
                        <h4>Financial Report (${data.report_period})</h4>
                        <p>Total Invoices: <strong>${data.total_invoices}</strong></p>
                        <p>Total Revenue: <strong>KSh ${parseFloat(data.total_revenue).toFixed(2)}</strong></p>
                        <p>Total Labor Cost: <strong>KSh ${parseFloat(data.total_labor_cost).toFixed(2)}</strong></p>
                        <p>Total Parts Cost: <strong>KSh ${parseFloat(data.total_parts_cost).toFixed(2)}</strong></p>
                    </div>
                    <h4>Detailed Invoice List:</h4>
                    ${data.invoices_table}
                `;
            } else {
                reportDataDiv.innerHTML = `<p style="color: red;">${data.message}</p>`;
            }
        } catch (error) {
            console.error('Error fetching report:', error);
            reportDataDiv.innerHTML = '<p style="color: red;">Failed to generate report. An unexpected error occurred.</p>';
        }
    }

    // Function to open the custom report modal
    function openCustomReportModal() {
        // Ensure only one custom report modal exists
        let customModal = document.getElementById('customReportModal');
        if (!customModal) {
            const modalHtml = `
                <div id="customReportModal" class="modal">
                    <div class="modal-content">
                        <span class="close-button" onclick="closeModal('customReportModal')">&times;</span>
                        <h2>Generate Custom Report</h2>
                        <form onsubmit="event.preventDefault(); generateCustomReport();">
                            <div class="form-group">
                                <label for="startDate">Start Date:</label>
                                <input type="date" id="startDate" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label for="endDate">End Date:</label>
                                <input type="date" id="endDate" class="form-control" required>
                            </div>
                            <div style="text-align: right;">
                                <button type="submit" class="btn btn-primary">Generate</button>
                            </div>
                        </form>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            customModal = document.getElementById('customReportModal');
        }
        customModal.style.display = 'flex';
    }

    // Function to generate the custom report from modal
    function generateCustomReport() {
        const startDate = document.getElementById('startDate').value;
        const endDate = document.getElementById('endDate').value;
        if (startDate && endDate) {
            closeModal('customReportModal');
            generateReport('custom', startDate, endDate);
        } else {
            showNotification('Please select both start and end dates.', 'error');
        }
    }

    // Helper functions for print and export
    function printReport() {
        const reportContent = document.getElementById('reportData').innerHTML;
        const printWindow = window.open('', '', 'height=600,width=800');
        printWindow.document.write('<html><head><title>Financial Report</title>');
        printWindow.document.write('<style>');
        printWindow.document.write('body { font-family: Arial, sans-serif; }');
        printWindow.document.write('table { width: 100%; border-collapse: collapse; margin-top: 20px; }');
        printWindow.document.write('th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }');
        printWindow.document.write('th { background-color: #f2f2f2; }');
        printWindow.document.write('</style>');
        printWindow.document.write('</head><body>');
        printWindow.document.write(reportContent);
        printWindow.document.write('</body></html>');
        printWindow.document.close();
        printWindow.print();
    }

    function exportToExcel() {
        // Check if the reportData contains a table to export
        const reportTable = document.getElementById('reportData').querySelector('table');
        if (!reportTable) {
            showNotification("No table data found in the report to export.", "error");
            return;
        }

        // Use SheetJS (xlsx.full.min.js) for robust Excel export
        const ws = XLSX.utils.table_to_sheet(reportTable);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, "Financial Report");
        XLSX.writeFile(wb, "financial_report.xlsx");
        showNotification("Report exported to Excel successfully!", "success");
    }

    // Initial load logic
    window.onload = function() {
        openTab(null, 'jobCards'); // Initial tab open, pass null for event

        // Handle PHP-generated session messages
        const pageMessage = "<?php echo $page_message; ?>";
        const pageMessageType = "<?php echo $page_message_type; ?>";
        if (pageMessage) {
            showNotification(pageMessage, pageMessageType);
        }
    };
    </script>
</body>
</html>
