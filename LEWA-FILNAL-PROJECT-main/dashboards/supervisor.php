<?php
session_start(); // Start the session at the very beginning

// --- SECURITY CHECKUP ---
// This dashboard is specifically for 'supervisor' user type.
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'supervisor') {
    error_log("Unauthorized access attempt to supervisor_dashboard.php by user_id: " . ($_SESSION['user_id'] ?? 'N/A') . " (User Type: " . ($_SESSION['user_type'] ?? 'N/A') . ")");
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

// Initialize messages
$page_message = '';
$page_message_type = '';

// Check for session messages (e.g., from a redirect after an action)
if (isset($_SESSION['success_message'])) {
    $page_message = $_SESSION['success_message'];
    $page_message_type = 'success';
    unset($_SESSION['success_message']);
} elseif (isset($_SESSION['error_message'])) {
    $page_message = $_SESSION['error_message'];
    $page_message_type = 'error';
    unset($_SESSION['error_message']);
}


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
                      LEFT JOIN users u_mechanic ON jc.assigned_to_mechanic_id = u_mechanic.user_id
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
                                         FROM job_parts jcp
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
        error_log("AJAX Error in supervisor_dashboard.php: " . $e->getMessage()); // Log the error for server-side debugging
    } finally {
        // Ensure connection is closed even if an error occurs
        if (isset($conn) && $conn->ping()) { // ping() checks if connection is still alive
            $conn->close();
        }
    }
    echo json_encode($response);
    exit();
}

// NEW AJAX HANDLING for updating job card status from Supervisor Dashboard
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
                error_log("Supervisor status update SQL error: " . $stmt->error);
            }
            $stmt->close();
        } else {
            $response['message'] = 'Failed to prepare SQL statement: ' . $conn->error;
            error_log("Supervisor status update prepare error: " . $conn->error);
        }
    } catch (Exception $e) {
        $response['message'] = 'An error occurred: ' . $e->getMessage();
        error_log("Supervisor status update exception: " . $e->getMessage());
    } finally {
        if (isset($conn) && $conn->ping()) {
            $conn->close();
        }
    }
    echo json_encode($response);
    exit();
}


// --- Fetch Dashboard Statistics (PHP direct fetch) ---
date_default_timezone_set('Africa/Nairobi'); // Ensure consistent timezone for date calculations

// Completed Jobs Pending Invoice
$completed_pending_invoice_count = 0;
$stmt_pending_invoice = $conn->prepare("SELECT COUNT(jc.job_card_id) AS count
                                        FROM job_cards jc
                                        LEFT JOIN invoices i ON jc.job_card_id = i.job_card_id
                                        WHERE jc.status = 'completed' AND i.invoice_id IS NULL");
if ($stmt_pending_invoice) {
    $stmt_pending_invoice->execute();
    $result_pending_invoice = $stmt_pending_invoice->get_result()->fetch_assoc();
    $completed_pending_invoice_count = $result_pending_invoice['count'];
    $stmt_pending_invoice->close();
} else {
    error_log("Error fetching completed pending invoice count: " . $conn->error);
}


// Get all job cards
$jobCards = [];
// This query fetches all job cards regardless of status
$jobCardsQuery = "SELECT j.*, u.full_name as created_by_name_for_job_card, v.make as vehicle_make, v.model as vehicle_model, v.registration_number as vehicle_license, d.full_name as driver_name,
                      mech.full_name as mechanic_name, adv.full_name as service_advisor_name
                      FROM job_cards j
                      JOIN users u ON j.created_by_user_id = u.user_id
                      JOIN vehicles v ON j.vehicle_id = v.vehicle_id
                      LEFT JOIN users d ON j.driver_id = d.user_id -- Corrected: Link driver_id from job_cards to users table
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

// Fetch job cards that are 'completed' but do NOT have an associated invoice
$completed_unbilled_jobs = [];
$completed_unbilled_query = "SELECT
                                jc.*,
                                u.full_name as created_by_name_for_job_card,
                                v.make as vehicle_make,
                                v.model as vehicle_model,
                                v.registration_number as vehicle_license,
                                d.full_name as driver_name,
                                mech.full_name as mechanic_name,
                                adv.full_name as service_advisor_name
                            FROM job_cards jc
                            JOIN users u ON jc.created_by_user_id = u.user_id
                            JOIN vehicles v ON jc.vehicle_id = v.vehicle_id
                            LEFT JOIN users d ON jc.driver_id = d.user_id -- Corrected: Link driver_id from job_cards to users table
                            LEFT JOIN users mech ON jc.assigned_to_mechanic_id = mech.user_id
                            LEFT JOIN users adv ON jc.service_advisor_id = adv.user_id
                            LEFT JOIN invoices i ON jc.job_card_id = i.job_card_id
                            WHERE jc.status = 'completed' AND i.invoice_id IS NULL
                            ORDER BY jc.completed_at DESC";

$completed_unbilled_result = $conn->query($completed_unbilled_query);
if ($completed_unbilled_result) {
    while ($row = $completed_unbilled_result->fetch_assoc()) {
        $completed_unbilled_jobs[] = $row;
    }
    $completed_unbilled_result->free();
} else {
    error_log("Error fetching completed unbilled job cards: " . $conn->error);
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
                      LEFT JOIN users d ON jc.driver_id = d.user_id -- Corrected: Link driver_id from job_cards to users table
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
    <title>Supervisor Dashboard - Lewa Workshop</title>
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
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/supervisor_dashboard.php" class="active"><i class="fas fa-user-tie"></i> Supervisor</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </nav>
        </div>

        <div class="main-content">
            <div class="header-section">
                <!-- Removed the <h1>Supervisor Dashboard</h1> heading -->
                <div class="user-greeting">
                    Welcome, <?php echo htmlspecialchars($supervisorName); ?> (Supervisor)
                </div>
            </div>

            <div id="notification" class="notification"></div>

            <div class="tabs">
                <button class="tab-btn active" onclick="openTab(event, 'jobCards')">All Job Cards</button>
                <button class="tab-btn" onclick="openTab(event, 'completedUnbilled')">Completed Awaiting Invoice</button>
                <button class="tab-btn" onclick="openTab(event, 'invoices')">Invoices</button>
                <button class="tab-btn" onclick="openTab(event, 'reports')">Reports</button>
            </div>

            <div class="stat-cards">
                <div class="stat-card">
                    <h3>Completed Jobs (Pending Invoice)</h3>
                    <p class="value" id="pendingInvoiceJobs"><?php echo htmlspecialchars($completed_pending_invoice_count); ?></p>
                </div>
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

            <div id="completedUnbilled" class="tab-content">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h2>Completed Jobs Awaiting Invoice</h2>
                    <button class="btn btn-secondary" onclick="location.reload();"><i class="fas fa-sync-alt"></i> Refresh List</button>
                </div>
                <?php if (!empty($completed_unbilled_jobs)): ?>
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
                            <th>Completed At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($completed_unbilled_jobs as $job): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($job['job_card_id']); ?></td>
                            <td><?php echo htmlspecialchars($job['driver_name'] ?? 'N/A'); ?></td>
                            <td><?php htmlspecialchars($job['vehicle_make'] . ' ' . $job['vehicle_model'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($job['vehicle_license'] ?? 'N/A'); ?></td>
                            <td><span class="status-badge <?php echo str_replace('_', '-', $job['status'] ?? 'unknown'); ?>">
                                    <?php echo ucwords(str_replace('_', ' ', $job['status'] ?? 'Unknown')); ?>
                                </span></td>
                            <td><?php echo htmlspecialchars($job['mechanic_name'] ?? 'N/A'); ?></td>
                            <td>KSh <?php echo htmlspecialchars(number_format($job['labor_cost'] ?? 0.00, 2)); ?></td>
                            <td><?php echo htmlspecialchars($job['service_advisor_name'] ?? 'N/A'); ?></td>
                            <td><?php echo formatDate($job['completed_at'] ?? ''); ?></td>
                            <td>
                                <button onclick="openCreateInvoiceModal(<?php echo htmlspecialchars(json_encode($job)); ?>)" class="btn small btn-primary"><i class="fas fa-file-invoice"></i> Create Invoice</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <p style="text-align: center; padding: 20px; color: #555;">No completed jobs currently awaiting invoice.</p>
                <?php endif; ?>
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
                            <th>Generated By</th>
                            <th>Invoice Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($invoices)): ?>
                            <?php foreach ($invoices as $invoice): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($invoice['invoice_id']); ?></td>
                                <td><?php echo htmlspecialchars($invoice['job_card_id']); ?></td>
                                <td><?php echo htmlspecialchars($invoice['vehicle_make'] . ' ' . $invoice['vehicle_model'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($invoice['driver_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($invoice['mechanic_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($invoice['service_advisor_name'] ?? 'N/A'); ?></td>
                                <td>KSh <?php echo htmlspecialchars(number_format($invoice['labor_cost'] ?? 0.00, 2)); ?></td>
                                <td>KSh <?php echo htmlspecialchars(number_format($invoice['parts_cost'] ?? 0.00, 2)); ?></td>
                                <td>KSh <?php echo htmlspecialchars(number_format($invoice['total_amount'] ?? 0.00, 2)); ?></td>
                                <td><?php echo htmlspecialchars($invoice['generated_by_name'] ?? 'N/A'); ?></td>
                                <td><?php echo formatDate($invoice['invoice_date'] ?? ''); ?></td>
                                <td>
                                    <a href="view_invoice.php?invoice_id=<?php echo htmlspecialchars($invoice['invoice_id']); ?>" target="_blank" class="btn small btn-info"><i class="fas fa-print"></i> View/Print</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="12" style="text-align: center;">No invoices found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div id="reports" class="tab-content">
                <h2>Financial Reports</h2>
                <div class="report-options">
                    <div class="report-card" onclick="generateReport('daily')">
                        <h3>Daily Report</h3>
                        <p>View today's financial summary.</p>
                    </div>
                    <div class="report-card" onclick="generateReport('weekly')">
                        <h3>Weekly Report</h3>
                        <p>Analyze financial data for the current week.</p>
                    </div>
                    <div class="report-card" onclick="generateReport('monthly')">
                        <h3>Monthly Report</h3>
                        <p>Review financial performance for the current month.</p>
                    </div>
                    <div class="report-card" onclick="openCustomReportModal()">
                        <h3>Custom Report</h3>
                        <p>Generate reports for a specific date range.</p>
                    </div>
                </div>
                <div id="reportData" style="margin-top: 30px; padding: 20px; background-color: #f9f9f9; border-radius: 8px; border: 1px solid #eee;">
                    <p style="text-align: center; color: #777;">Select a report type above to generate a financial report.</p>
                </div>
                <div style="text-align: center; margin-top: 20px;">
                    <button class="btn btn-success" onclick="exportToExcel()"><i class="fas fa-file-excel"></i> Export to Excel</button>
                    <button class="btn btn-primary" onclick="printReport()"><i class="fas fa-print"></i> Print Report</button>
                </div>
            </div>

        </div>
    </div>

    <!-- Job Card Details Modal -->
    <div id="jobCardDetailsModal" class="modal">
        <div class="modal-content">
            <span class="close-button" onclick="closeJobCardDetailsModal()">&times;</span>
            <h2>Job Card Details</h2>
            <div id="jobCardDetailsContent">
                <!-- Details will be loaded here via AJAX -->
            </div>
            <div class="form-group" style="margin-top: 20px;">
                <label for="financeStatusSelect">Update Job Status (Finance):</label>
                <select id="financeStatusSelect" class="form-control">
                    <option value="">-- Select Status --</option>
                    <option value="finance_received">Mark as Finance Received</option>
                    <option value="finance_canceled">Cancel Job (Finance)</option>
                </select>
                <textarea id="cancellationReason" class="form-control" placeholder="Reason for cancellation (if applicable)" style="display: none; margin-top: 10px;"></textarea>
                <button id="updateFinanceStatusBtn" class="btn btn-primary" style="margin-top: 10px;"><i class="fas fa-save"></i> Update Finance Status</button>
            </div>
        </div>
    </div>

    <!-- Create Invoice Modal -->
    <div id="createInvoiceModal" class="modal">
        <div class="modal-content">
            <span class="close-button" onclick="closeCreateInvoiceModal()">&times;</span>
            <h2>Create Invoice</h2>
            <form id="createInvoiceForm">
                <input type="hidden" id="invoiceJobCardId" name="job_card_id">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                <div class="form-group">
                    <label for="invoiceVehicle">Vehicle:</label>
                    <input type="text" id="invoiceVehicle" class="form-control" readonly>
                </div>
                <div class="form-group">
                    <label for="invoiceLicense">License Plate:</label>
                    <input type="text" id="invoiceLicense" class="form-control" readonly>
                </div>
                <div class="form-group">
                    <label for="invoiceDescription">Job Description:</label>
                    <textarea id="invoiceDescription" class="form-control" rows="3" readonly></textarea>
                </div>
                <div class="form-group">
                    <label for="invoiceMechanic">Assigned Mechanic:</label>
                    <input type="text" id="invoiceMechanic" class="form-control" readonly>
                </div>
                <div class="form-group">
                    <label for="invoiceServiceAdvisor">Service Advisor:</label>
                    <input type="text" id="invoiceServiceAdvisor" class="form-control" readonly>
                </div>

                <div class="form-group">
                    <label for="laborCost">Labor Cost (KES):</label>
                    <input type="number" id="laborCost" name="labor_cost" step="0.01" min="0" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="partsCost">Parts Cost (KES):</label>
                    <input type="number" id="partsCost" name="parts_cost" step="0.01" min="0" class="form-control" required>
                </div>

                <button type="submit" class="btn btn-primary"><i class="fas fa-file-invoice"></i> Generate Invoice</button>
            </form>
        </div>
    </div>

    <!-- Custom Report Modal -->
    <div id="customReportModal" class="modal">
        <div class="modal-content">
            <span class="close-button" onclick="closeCustomReportModal()">&times;</span>
            <h2>Generate Custom Report</h2>
            <form id="customReportForm">
                <div class="form-group">
                    <label for="reportStartDate">Start Date:</label>
                    <input type="date" id="reportStartDate" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="reportEndDate">End Date:</label>
                    <input type="date" id="reportEndDate" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-chart-bar"></i> Generate Report</button>
            </form>
        </div>
    </div>


    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        // Function to display notifications
        function showNotification(message, type) {
            const notification = $('#notification');
            notification.removeClass().addClass('notification ' + type).text(message);
            notification.addClass('show');
            setTimeout(() => {
                notification.removeClass('show');
            }, 5000);
        }

        // Tab functionality
        function openTab(evt, tabName) {
            var i, tabcontent, tablinks;
            tabcontent = document.getElementsByClassName("tab-content");
            for (i = 0; i < tabcontent.length; i++) {
                tabcontent[i].style.display = "none";
            }
            tablinks = document.getElementsByClassName("tab-btn");
            for (i = 0; i < tablinks.length; i++) {
                tablinks[i].className = tablinks[i].className.replace(" active", "");
            }
            document.getElementById(tabName).style.display = "block";
            if (evt) {
                evt.currentTarget.className += " active";
            }
        }

        // Job Card Details Modal
        const jobCardDetailsModal = document.getElementById('jobCardDetailsModal');
        const jobCardDetailsContent = document.getElementById('jobCardDetailsContent');
        const financeStatusSelect = document.getElementById('financeStatusSelect');
        const cancellationReasonInput = document.getElementById('cancellationReason');
        const updateFinanceStatusBtn = document.getElementById('updateFinanceStatusBtn');
        let currentJobCardIdForModal = null; // To store the job card ID for status updates

        function openJobCardDetailsModal(jobCardId) {
            currentJobCardIdForModal = jobCardId;
            // Reset form elements
            financeStatusSelect.value = '';
            cancellationReasonInput.value = '';
            cancellationReasonInput.style.display = 'none';

            $.ajax({
                url: `supervisor_dashboard.php?ajax=job_card_details&job_card_id=${jobCardId}`,
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        const job = response.data;
                        let partsHtml = '';
                        if (job.parts_used && job.parts_used.length > 0) {
                            partsHtml = '<h3>Parts Used:</h3><table><thead><tr><th>Item</th><th>Quantity</th><th>Unit Price</th><th>Total</th></tr></thead><tbody>';
                            job.parts_used.forEach(part => {
                                const total = (parseFloat(part.quantity_used) * parseFloat(part.unit_price)).toFixed(2);
                                partsHtml += `<tr><td>${part.item_name}</td><td>${part.quantity_used}</td><td>KES ${parseFloat(part.unit_price).toFixed(2)}</td><td>KES ${total}</td></tr>`;
                            });
                            partsHtml += '</tbody></table>';
                        } else {
                            partsHtml = '<p>No parts recorded for this job.</p>';
                        }

                        jobCardDetailsContent.innerHTML = `
                            <p><strong>Job Card ID:</strong> ${job.job_card_id}</p>
                            <p><strong>Vehicle:</strong> ${job.vehicle_make} ${job.vehicle_model} (${job.vehicle_license})</p>
                            <p><strong>Mileage:</strong> ${job.vehicle_mileage} km</p>
                            <p><strong>Driver:</strong> ${job.driver_name || 'N/A'}</p>
                            <p><strong>Description:</strong> ${job.description}</p>
                            <p><strong>Current Status:</strong> <span class="status-badge ${job.status.replace('_', '-')}">${job.status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}</span></p>
                            <p><strong>Created By:</strong> ${job.created_by_name || 'N/A'}</p>
                            <p><strong>Created At:</strong> ${formatDate(job.created_at)}</p>
                            <p><strong>Assigned Mechanic:</strong> ${job.mechanic_name || 'N/A'}</p>
                            <p><strong>Service Advisor:</strong> ${job.service_advisor_name || 'N/A'}</p>
                            <p><strong>Labor Cost:</strong> KES ${parseFloat(job.labor_cost || 0).toFixed(2)}</p>
                            ${partsHtml}
                        `;
                        jobCardDetailsModal.style.display = 'flex';
                    } else {
                        showNotification(response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    showNotification("Error fetching job card details: " + error, 'error');
                }
            });
        }

        function closeJobCardDetailsModal() {
            jobCardDetailsModal.style.display = 'none';
            jobCardDetailsContent.innerHTML = '';
            currentJobCardIdForModal = null;
        }

        financeStatusSelect.addEventListener('change', function() {
            if (this.value === 'finance_canceled') {
                cancellationReasonInput.style.display = 'block';
                cancellationReasonInput.setAttribute('required', 'required');
            } else {
                cancellationReasonInput.style.display = 'none';
                cancellationReasonInput.removeAttribute('required');
            }
        });

        updateFinanceStatusBtn.addEventListener('click', function() {
            const newStatus = financeStatusSelect.value;
            const cancellationReason = cancellationReasonInput.value;

            if (!newStatus) {
                showNotification('Please select a status to update.', 'error');
                return;
            }

            if (newStatus === 'finance_canceled' && !cancellationReason.trim()) {
                showNotification('Cancellation reason is required for "Finance Canceled" status.', 'error');
                return;
            }

            if (!currentJobCardIdForModal) {
                showNotification('No job card selected for update.', 'error');
                return;
            }

            $.ajax({
                url: 'supervisor_dashboard.php',
                method: 'POST',
                data: {
                    action: 'update_job_card_status_finance',
                    job_card_id: currentJobCardIdForModal,
                    new_status: newStatus,
                    cancellation_reason: cancellationReason,
                    csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showNotification(response.message, 'success');
                        closeJobCardDetailsModal();
                        // Reload the page to reflect the status change in the tables
                        location.reload();
                    } else {
                        showNotification(response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    showNotification("Error updating job card status: " + error, 'error');
                }
            });
        });


        // Create Invoice Modal
        const createInvoiceModal = document.getElementById('createInvoiceModal');
        const createInvoiceForm = document.getElementById('createInvoiceForm');
        const invoiceJobCardId = document.getElementById('invoiceJobCardId');
        const invoiceVehicle = document.getElementById('invoiceVehicle');
        const invoiceLicense = document.getElementById('invoiceLicense');
        const invoiceDescription = document.getElementById('invoiceDescription');
        const invoiceMechanic = document.getElementById('invoiceMechanic');
        const invoiceServiceAdvisor = document.getElementById('invoiceServiceAdvisor');
        const laborCostInput = document.getElementById('laborCost');
        const partsCostInput = document.getElementById('partsCost');


        function openCreateInvoiceModal(jobData) {
            invoiceJobCardId.value = jobData.job_card_id;
            invoiceVehicle.value = `${jobData.vehicle_make} ${jobData.vehicle_model}`;
            invoiceLicense.value = jobData.vehicle_license;
            invoiceDescription.value = jobData.description;
            invoiceMechanic.value = jobData.mechanic_name || 'N/A';
            invoiceServiceAdvisor.value = jobData.service_advisor_name || 'N/A';
            laborCostInput.value = parseFloat(jobData.labor_cost || 0).toFixed(2);

            // Fetch parts cost for the specific job card
            $.ajax({
                url: `<?php echo BASE_URL; ?>/api/fetch_job_card_parts.php?job_card_id=${jobData.job_card_id}`,
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.data.length > 0) {
                        let totalPartsCost = 0;
                        response.data.forEach(part => {
                            totalPartsCost += parseFloat(part.quantity_used) * parseFloat(part.unit_price);
                        });
                        partsCostInput.value = totalPartsCost.toFixed(2);
                    } else {
                        partsCostInput.value = (0).toFixed(2); // Set to 0 if no parts or error
                    }
                    createInvoiceModal.style.display = 'flex'; // Display modal after parts cost is set
                },
                error: function(xhr, status, error) {
                    console.error("Error fetching parts cost:", status, error);
                    partsCostInput.value = (0).toFixed(2); // Default to 0 on error
                    showNotification("Could not fetch parts cost. Please enter manually if known.", "warning");
                    createInvoiceModal.style.display = 'flex'; // Still open modal
                }
            });
        }

        function closeCreateInvoiceModal() {
            createInvoiceModal.style.display = 'none';
            createInvoiceForm.reset();
        }

        createInvoiceForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const jobCardId = formData.get('job_card_id');
            const laborCost = parseFloat(formData.get('labor_cost'));
            const partsCost = parseFloat(formData.get('parts_cost'));

            if (isNaN(laborCost) || isNaN(partsCost) || laborCost < 0 || partsCost < 0) {
                showNotification('Please enter valid positive numbers for labor and parts costs.', 'error');
                return;
            }

            // Calculate total amount here before sending
            const totalAmount = laborCost + partsCost;

            $.ajax({
                url: '<?php echo BASE_URL; ?>/api/create_invoice.php', // API endpoint for creating invoice
                method: 'POST',
                data: JSON.stringify({
                    job_card_id: jobCardId,
                    labor_cost: laborCost,
                    parts_cost: partsCost,
                    total_amount: totalAmount, // Send calculated total
                    mechanic_id: <?php echo $job['assigned_to_mechanic_id'] ?? 'null'; ?>, // Pass mechanic ID
                    service_advisor_id: <?php echo $job['service_advisor_id'] ?? 'null'; ?>, // Pass service advisor ID
                    csrf_token: formData.get('csrf_token')
                }),
                contentType: 'application/json', // Specify content type as JSON
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showNotification(response.message, 'success');
                        closeCreateInvoiceModal();
                        location.reload(); // Reload page to update job card status and invoice list
                    } else {
                        showNotification(response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error("Invoice creation failed:", status, error, xhr.responseText);
                    showNotification("An error occurred during invoice creation.", 'error');
                }
            });
        });

        // Custom Report Modal
        const customReportModal = document.getElementById('customReportModal');
        const customReportForm = document.getElementById('customReportForm');
        const reportStartDateInput = document.getElementById('reportStartDate');
        const reportEndDateInput = document.getElementById('reportEndDate');

        function openCustomReportModal() {
            customReportModal.style.display = 'flex';
        }

        function closeCustomReportModal() {
            customReportModal.style.display = 'none';
            customReportForm.reset();
        }

        customReportForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const startDate = reportStartDateInput.value;
            const endDate = reportEndDateInput.value;

            if (!startDate || !endDate) {
                showNotification('Please select both start and end dates for the custom report.', 'error');
                return;
            }

            generateReport('custom', startDate, endDate);
            closeCustomReportModal();
        });


        // Report Generation Functions
        function generateReport(type, startDate = null, endDate = null) {
            let url = `<?php echo BASE_URL; ?>/api/generate_custom_report.php`;
            let data = { report_type: type };

            if (type === 'custom' && startDate && endDate) {
                data.start_date = startDate;
                data.end_date = endDate;
            } else if (type !== 'custom') {
                // For predefined reports, the PHP script determines dates
                url += `?type=${type}`;
            }

            // Show a loading message
            $('#reportData').html('<p style="text-align: center; color: #777;"><i class="fas fa-spinner fa-spin"></i> Generating report...</p>');

            $.ajax({
                url: url,
                method: 'POST', // Use POST for custom reports to send dates in body
                contentType: 'application/json',
                data: JSON.stringify(data),
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#reportData').html(response.html);
                        showNotification('Report generated successfully!', 'success');
                    } else {
                        $('#reportData').html('<p style="text-align: center; color: #e74c3c;">' + (response.message || 'Failed to generate report.') + '</p>');
                        showNotification(response.message || 'Failed to generate report.', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error("Report generation failed:", status, error, xhr.responseText);
                    $('#reportData').html('<p style="text-align: center; color: #e74c3c;">An error occurred while generating the report.</p>');
                    showNotification("An error occurred while generating the report.", 'error');
                }
            });
        }

        function printReport() {
            const reportContent = document.getElementById('reportData').innerHTML;
            const printWindow = window.open('', '_blank');
            printWindow.document.write('<html><head><title>Financial Report</title>');
            printWindow.document.write('<style>');
            printWindow.document.write('body { font-family: \'Arial\', sans-serif; margin: 20px; }');
            printWindow.document.write('table { width: 100%; border-collapse: collapse; margin-top: 20px; }');
            printWindow.document.write('th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }');
            printWindow.document.write('th { background-color: #f2f2f2; }');
            printWindow.document.write('</style>');
            printWindow.document.write('</head><body>');
            printWindow.document.write(reportContent);
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
