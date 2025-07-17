<?php
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'service_advisor') {
    header("Location: ../user_login.php");
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

include __DIR__ . '/../db_connect.php';

if (!$conn) {
    error_log("Database connection failed: " . mysqli_connect_error());
    die("A critical database error occurred. Please try again later.");
}
$page_message = '';
$page_message_type = '';

if (isset($_GET['success'])) {
    $page_message = 'Operation completed successfully!';
    $page_message_type = 'success';
} elseif (isset($_GET['error'])) {
    $page_message = 'An error occurred. Please try again.';
    $page_message_type = 'error';
}

$advisor_id = $_SESSION['user_id'];
$query = "SELECT full_name FROM users WHERE user_id = ?";
$stmt = $conn->prepare($query);
$advisor = ['full_name' => 'Unknown Advisor']; 
if ($stmt) {
    $stmt->bind_param("i", $advisor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $advisor = $result->fetch_assoc();
    }
    $stmt->close();
} else {
    error_log("Failed to prepare advisor details query: " . $conn->error);
}

// Helper function to fetch mechanics (re-used from admin_dashboard context)
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


$today = date('Y-m-d');
$new_jobs_query = "SELECT COUNT(*) AS count FROM job_cards WHERE DATE(created_at) = ?";
$stmt_new_jobs = $conn->prepare($new_jobs_query);
$new_jobs_today = 0;
if ($stmt_new_jobs) {
    $stmt_new_jobs->bind_param("s", $today);
    $stmt_new_jobs->execute();
    $new_jobs_today = $stmt_new_jobs->get_result()->fetch_assoc()['count'];
    $stmt_new_jobs->close();
} else {
    error_log("Failed to prepare new jobs today query: " . $conn->error);
}

$pending_assignments_query = "SELECT COUNT(*) AS count FROM job_cards WHERE status = 'pending'";
$stmt_pending_assignments = $conn->prepare($pending_assignments_query);
$pending_assignments = 0;
if ($stmt_pending_assignments) {
    $stmt_pending_assignments->execute();
    $pending_assignments = $stmt_pending_assignments->get_result()->fetch_assoc()['count'];
    $stmt_pending_assignments->close();
} else {
    error_log("Failed to prepare pending assignments query: " . $conn->error);
}

$in_progress_query = "SELECT COUNT(*) AS count FROM job_cards WHERE status IN ('in_progress', 'assigned')";
$stmt_in_progress = $conn->prepare($in_progress_query);
$jobs_in_progress = 0;
if ($stmt_in_progress) {
    $stmt_in_progress->execute();
    $jobs_in_progress = $stmt_in_progress->get_result()->fetch_assoc()['count'];
    $stmt_in_progress->close();
} else {
    error_log("Failed to prepare jobs in progress query: " . $conn->error);
}

// Fetch all active jobs for the table (pending, in_progress, on_hold, assessment_requested)
// This query ensures that all relevant active job statuses are displayed in the table.
$jobs_query = "SELECT j.job_card_id, j.description, j.created_at, j.status, j.mechanic_id, j.labor_cost,
                                v.make, v.model, v.registration_number, v.vehicle_id,
                                d.full_name as driver_name
                        FROM job_cards j
                        JOIN vehicles v ON j.vehicle_id = v.vehicle_id
                        JOIN users d ON j.driver_id = d.user_id
                        WHERE j.status IN ('pending', 'in_progress', 'on_hold', 'assessment_requested')
                        ORDER BY j.created_at DESC";
$jobs_stmt = $conn->prepare($jobs_query);
$jobs = []; 
if ($jobs_stmt) {
    $jobs_stmt->execute();
    $jobs = $jobs_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $jobs_stmt->close();
} else {
    error_log("Failed to prepare job cards query: " . $conn->error);
}

$spare_parts_query = "SELECT item_id, item_name, quantity FROM inventory WHERE quantity > 0 ORDER BY item_name ASC";
$spare_parts_stmt = $conn->prepare($spare_parts_query);
$spare_parts = [];
if ($spare_parts_stmt) {
    $spare_parts_stmt->execute();
    $spare_parts = $spare_parts_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $spare_parts_stmt->close();
} else {
    error_log("Failed to prepare spare parts query: " . $conn->error);
}

// Handle POST requests for job updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    // CSRF token validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $response['message'] = 'Invalid CSRF token';
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

                $update_query = "UPDATE job_cards SET status = ?, labor_cost = ?";
                $types = "sd";

                if ($status === 'completed') {
                    $update_query .= ", completed_at = NOW()";
                } else {
                    $update_query .= ", completed_at = NULL";
                }

                if ($mechanic_id !== null) {
                    $update_query .= ", mechanic_id = ?";
                    $types .= "i";
                } else {
                    $update_query .= ", mechanic_id = NULL";
                }

                $update_query .= " WHERE job_card_id = ?";
                $types .= "i";

                $stmt = $conn->prepare($update_query);

                if (!$stmt) {
                    throw new Exception("Failed to prepare update statement: " . $conn->error);
                }

                $params = [$status, $labor_cost];
                if ($mechanic_id !== null) {
                    $params[] = $mechanic_id;
                }
                $params[] = $job_id;

                $bind_names[] = $types;
                for ($i = 0; $i < count($params); $i++) {
                    $bind_name = 'bind' . $i;
                    $$bind_name = $params[$i];
                    $bind_names[] = &$$bind_name;
                }
                call_user_func_array([$stmt, 'bind_param'], $bind_names);

                if ($stmt->execute()) {
                    $response['success'] = true;
                    $response['message'] = "Job status, mechanic, and labor cost updated successfully";
                } else {
                    throw new Exception("Failed to update job status, mechanic, or labor cost: " . $stmt->error);
                }
                $stmt->close();
                break;
            case 'assign_job_with_parts': // Keep this for the original assign functionality
                // This logic should ideally be in a separate process_job_card.php or similar
                // For now, it's kept here as per the original file structure.
                $job_card_id = $_POST['job_card_id'] ?? null;
                $mechanic_id = $_POST['mechanic_id'] ?? null;
                $parts_requested = $_POST['parts'] ?? [];

                if (!$job_card_id || !$mechanic_id) {
                    throw new Exception("Job Card ID and Mechanic are required for assignment.");
                }

                $conn->begin_transaction();

                try {
                    // Update job card status and assign mechanic
                    $stmt = $conn->prepare("UPDATE job_cards SET status = 'in_progress', mechanic_id = ? WHERE job_card_id = ?");
                    if (!$stmt) {
                        throw new Exception("Failed to prepare job update statement: " . $conn->error);
                    }
                    $stmt->bind_param("ii", $mechanic_id, $job_card_id);
                    if (!$stmt->execute()) {
                        throw new Exception("Failed to update job card: " . $stmt->error);
                    }
                    $stmt->close();

                    // Process parts requests
                    foreach ($parts_requested as $item_id => $part_data) {
                        if (isset($part_data['selected']) && $part_data['selected'] == '1') {
                            $quantity_requested = (int)($part_data['quantity'] ?? 0);

                            if ($quantity_requested <= 0) {
                                throw new Exception("Invalid quantity for part ID: " . $item_id);
                            }

                            // Check current stock
                            $stock_stmt = $conn->prepare("SELECT quantity FROM inventory WHERE item_id = ?");
                            if (!$stock_stmt) {
                                throw new Exception("Failed to prepare stock check statement: " . $conn->error);
                            }
                            $stock_stmt->bind_param("i", $item_id);
                            $stock_stmt->execute();
                            $stock_result = $stock_stmt->get_result();
                            $current_stock = $stock_result->fetch_assoc()['quantity'] ?? 0;
                            $stock_stmt->close();

                            if ($current_stock < $quantity_requested) {
                                throw new Exception("Insufficient stock for part ID: " . $item_id . ". Available: " . $current_stock);
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

                            // Record part usage in job_card_parts
                            $record_stmt = $conn->prepare("INSERT INTO job_card_parts (job_card_id, item_id, quantity_used) VALUES (?, ?, ?)");
                            if (!$record_stmt) {
                                throw new Exception("Failed to prepare part usage record statement: " . $conn->error);
                            }
                            $record_stmt->bind_param("iii", $job_card_id, $item_id, $quantity_requested);
                            if (!$record_stmt->execute()) {
                                throw new Exception("Failed to record part usage: " . $record_stmt->error);
                            }
                            $record_stmt->close();
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

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Advisor Dashboard - Lewa Workshop</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>

        :root {
            --primary: #3498db;
            --secondary: #2c3e50;
            --success: #28a745; 
            --danger: #dc3545;  
            --warning: #ffc107;
            --info: #17a2b8;
            --light: #f8f9fa;
            --dark: #343a40;
            --border-color: #ddd;
            --card-bg: #fff;
            --text-color: #333;
            --header-bg: #ecf0f1;
        }

        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: var(--light);
            color: var(--text-color);
        }

        .dashboard {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 250px;
            background-color: var(--secondary);
            color: white;
            padding: 20px;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
        }

        .sidebar .logo {
            text-align: center;
            margin-bottom: 30px;
        }

        .sidebar .logo h2 {
            margin: 0;
            color: var(--primary);
        }

        .sidebar nav ul {
            list-style: none;
            padding: 0;
        }

        .sidebar nav ul li {
            margin-bottom: 10px;
        }

        .sidebar nav ul li a {
            color: white;
            text-decoration: none;
            padding: 10px 15px;
            display: block;
            border-radius: 5px;
            transition: background-color 0.3s ease;
        }

        .sidebar nav ul li a:hover {
            background-color: #3b5168; 
        }

        .main-content {
            flex-grow: 1;
            padding: 20px;
            background-color: var(--light);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: var(--card-bg);
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }

        .header h1 {
            margin: 0;
            color: var(--secondary);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background-color: var(--primary);
            color: white;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            font-weight: bold;
        }

        .card {
            background-color: var(--card-bg);
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }

        .card-title {
            font-size: 1.2em;
            color: var(--secondary);
            margin-bottom: 15px;
            font-weight: bold;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
        }

        .stat-item {
            background-color: var(--header-bg);
            padding: 15px;
            border-radius: 5px;
            text-align: center;
        }

        .stat-item .value {
            font-size: 1.8em;
            font-weight: bold;
            color: var(--primary);
        }

        .stat-item .label {
            font-size: 0.9em;
            color: var(--text-color);
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .table th, .table td {
            border: 1px solid var(--border-color);
            padding: 10px;
            text-align: left;
        }

        .table th {
            background-color: var(--header-bg);
            color: var(--secondary);
            font-weight: bold;
        }

        .table tr:nth-child(even) {
            background-color: var(--light);
        }

        .table tr:hover {
            background-color: #f1f1f1;
        }

        .btn {
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9em;
            transition: background-color 0.3s ease;
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background-color: #288ad6; 
        }
        
        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background-color: #5a6268;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: var(--secondary);
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 5px;
            box-sizing: border-box; 
        }

        .text-center {
            text-align: center;
        }

        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 5px;
            color: white;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            z-index: 1000;
            display: flex;
            align-items: center;
            transition: all 0.3s ease;
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

        .modal {
            display: none;
            position: fixed; 
            z-index: 1001; 
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
            margin: auto;
            padding: 30px;
            border: 1px solid #888;
            border-radius: 10px;
            width: 80%; 
            max-width: 600px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            position: relative;
        }

        .close-button {
            color: #aaa;
            position: absolute;
            top: 10px;
            right: 20px;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .close-button:hover,
        .close-button:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }

        .modal-body .form-group {
            margin-bottom: 20px;
        }

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
        }

        .part-item input[type="number"] {
            width: 70px;
            padding: 5px;
            border-radius: 3px;
            border: 1px solid var(--border-color);
            box-sizing: border-box; 
        }

        /* Status colors for table */
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
        .status-assessment-requested { /* New status style */
            color: var(--info);
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <div class="sidebar">
            <div class="logo">
                <h2>Lewa Workshop</h2>
            </div>
            <nav>
                <ul>
                    <li><a href="service_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="../dashboards/job_card.php"><i class="fas fa-clipboard-list"></i> Manage Job Cards</a></li>
                    <li><a href="../dashboards/manage_vehicles.php"><i class="fas fa-car"></i> Manage Vehicles</a></li>
                    <li><a href="../dashboards/drivers.php"><i class="fas fa-users"></i> Drivers</a></li>
                    <li><a href="/dashboards/manage_mechanics.php"><i class="fas fa-wrench"></i> Mechanics</a></li>
                    <li><a href="../dashboards/manage_vehicles.php"><i class="fas fa-car-alt"></i> Vehicles</a></li>
                    <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
                    <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </nav>
        </div>

        <div class="main-content">
            <div class="header">
                <h1>Service Advisor Dashboard</h1>
                <div class="user-info">
                    <div class="user-avatar"><?php echo strtoupper(substr($advisor['full_name'], 0, 1)); ?></div>
                    <div>
                        <div><?php echo htmlspecialchars($advisor['full_name']); ?></div>
                        <small>Service Advisor</small>
                    </div>
                </div>
            </div>

            <div id="notification" class="notification" style="display: none;"></div>

            <div class="card">
                <div class="card-title">Quick Stats</div>
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="value" id="new-jobs-today-stat"><?php echo htmlspecialchars($new_jobs_today); ?></div>
                        <div class="label">New Job Cards Today</div>
                    </div>
                    <div class="stat-item">
                        <div class="value" id="pending-assignments-stat"><?php echo htmlspecialchars($pending_assignments); ?></div>
                        <div class="label">Pending Assignments</div>
                    </div>
                    <div class="stat-item">
                        <div class="value" id="jobs-in-progress-stat"><?php echo htmlspecialchars($jobs_in_progress); ?></div>
                        <div class="label">Jobs in Progress</div>
                    </div>
                    <div class="stat-item">
                        <div class="value" id="completed-this-week-stat">0</div>
                        <div class="label">Completed This Week</div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-title">Active Job Cards</div>
                <div id="pending-jobs-table-container">
                    <?php if (count($jobs) > 0): ?>
                        <table class="table" id="pending-jobs-table">
                            <thead>
                                <tr>
                                    <th>Job ID</th>
                                    <th>Vehicle</th>
                                    <th>Driver</th>
                                    <th>Description</th>
                                    <th>Status</th>
                                    <th>Assigned Mechanic</th>
                                    <th>Labor Cost</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($jobs as $job): ?>
                                    <tr id="job-<?php echo htmlspecialchars($job['job_card_id']); ?>">
                                        <td>#<?php echo htmlspecialchars($job['job_card_id']); ?></td>
                                        <td>
                                            <?php echo htmlspecialchars($job['make'] . ' ' . $job['model']); ?>
                                            <br><small><?php echo htmlspecialchars($job['registration_number']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($job['driver_name']); ?></td>
                                        <td><?php echo htmlspecialchars($job['description']); ?></td>
                                        <td><span class="status-<?php echo str_replace(' ', '-', strtolower($job['status'])); ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $job['status']))); ?></span></td>
                                        <td><?php echo htmlspecialchars($mechanics_lookup[$job['mechanic_id']] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars(number_format($job['labor_cost'] ?? 0.00, 2)); ?></td>
                                        <td><?php echo date('M d, H:i', strtotime($job['created_at'])); ?></td>
                                        <td>
                                            <button type="button" class="btn btn-primary open-job-management-modal-btn" 
                                                    data-job-id="<?php echo htmlspecialchars($job['job_card_id']); ?>"
                                                    data-vehicle-id="<?php echo htmlspecialchars($job['vehicle_id']); ?>"
                                                    data-vehicle-info="<?php echo htmlspecialchars($job['make'] . ' ' . $job['model'] . ' (' . $job['registration_number'] . ')'); ?>"
                                                    data-job-description="<?php echo htmlspecialchars($job['description']); ?>"
                                                    data-current-status="<?php echo htmlspecialchars($job['status']); ?>"
                                                    data-assigned-mechanic-id="<?php echo htmlspecialchars($job['mechanic_id'] ?? ''); ?>"
                                                    data-labor-cost="<?php echo htmlspecialchars($job['labor_cost'] ?? 0.00); ?>">
                                                <i class="fas fa-cog"></i> Manage Job
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="text-center" id="no-pending-jobs">No active job cards found.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-title">Quick Job Card Creation</div>
                <form action="process_job_card.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div class="form-group">
                        <label for="vehicle_reg">Vehicle Registration Number:</label>
                        <input type="text" id="vehicle_reg" name="registration_number" class="form-control" placeholder="e.g., KCD 123A" required>
                    </div>
                    <div class="form-group">
                        <label for="job_description">Job Description:</label>
                        <textarea id="job_description" name="description" class="form-control" rows="3" placeholder="Describe the job required..." required></textarea>
                    </div>
                    <div class="form-group">
                        <label for="driver_name_input">Driver's Name (for new drivers):</label>
                        <input type="text" id="driver_name_input" name="driver_name" class="form-control" placeholder="Optional: Enter if new driver">
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-plus-circle"></i> Create Job Card</button>
                </form>
            </div>
        </div>
    </div>

    <div id="jobManagementModal" class="modal">
        <div class="modal-content">
            <span class="close-button">&times;</span>
            <h2>Manage Job Card</h2>
            <form id="updateJobCardForm" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="update_job_status">
                <input type="hidden" id="modalJobCardId" name="job_card_id">
                <input type="hidden" id="modalVehicleId" name="vehicle_id">

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
                        <option value="in_progress">In Progress</option>
                        <option value="completed">Completed</option>
                        <option value="on_hold">On Hold</option>
                        <option value="assessment_requested">Assessment Requested</option> <!-- New Status Option -->
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
                    <label for="modalLaborCost">Labor Cost:</label>
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
                                                data-max-quantity="<?php echo htmlspecialchars($part['quantity']); ?>">
                                        <?php echo htmlspecialchars($part['item_name']); ?> (In Stock: <?php echo htmlspecialchars($part['quantity']); ?>)
                                    </label>
                                    <input type="number" 
                                            name="parts[<?php echo htmlspecialchars($part['item_id']); ?>][quantity]" 
                                            value="1" min="1" 
                                            max="<?php echo htmlspecialchars($part['quantity']); ?>" 
                                            class="form-control part-quantity-input" 
                                            style="display: none;">
                                </div>
                            <?php endforeach; ?>
                        <? else: ?>
                            <p>No spare parts available in stock.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-group text-center">
                    <button type="submit" class="btn btn-primary" id="updateJobBtn">
                        <i class="fas fa-save"></i> Update Job Card
                    </button>
                    <button type="button" class="btn btn-secondary" id="cancelJobManagementBtn">Cancel</button>
                </div>
            </form>
            <div id="jobManagementMessage" class="notification" style="display: none;"></div>
        </div>
    </div>

    <script>
        const baseUrl = '<?php echo BASE_URL; ?>';
        const csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';
        const mechanicsLookup = <?php echo json_encode($mechanics_lookup); ?>;

        function showNotification(message, type) {
            const notification = document.getElementById('notification');
            notification.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
                ${message}
            `;
            notification.className = `notification notification-${type}`;
            notification.style.display = 'flex';
            setTimeout(() => {
                notification.style.display = 'none';
            }, 5000);
        }

        async function updateCompletedJobsStat() {
            try {
                const response = await fetch('get_completed_count.php'); 
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                const result = await response.json();

                if (result.status === 'success') {
                    const completedStatElement = document.getElementById('completed-this-week-stat');
                    if (completedStatElement) {
                        completedStatElement.textContent = result.count;
                    }
                } else {
                    console.error('Error fetching completed jobs count:', result.message);
                }
            } catch (error) {
                console.error('Failed to fetch completed jobs count:', error);
            }
        }

        async function updateNewJobsTodayStat() {
            try {
                const response = await fetch('get_new_jobs_today_count.php'); 
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                const result = await response.json();
                if (result.status === 'success') {
                    document.getElementById('new-jobs-today-stat').textContent = result.count;
                } else {
                    console.error('Error fetching new jobs today count:', result.message);
                }
            } catch (error) {
                console.error('Failed to fetch new jobs today count:', error);
            }
        }

        async function updatePendingAssignmentsStat() {
            try {
                const response = await fetch('get_pending_assignments_count.php'); 
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                const result = await response.json();
                if (result.status === 'success') {
                    document.getElementById('pending-assignments-stat').textContent = result.count;
                } else {
                    console.error('Error fetching pending assignments count:', result.message);
                }
            } catch (error) {
                console.error('Failed to fetch pending assignments count:', error);
            }
        }

        async function updateJobsInProgressStat() {
            try {
                const response = await fetch('get_in_progress_count.php'); 
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                const result = await response.json();
                if (result.status === 'success') {
                    document.getElementById('jobs-in-progress-stat').textContent = result.count;
                } else {
                    console.error('Error fetching jobs in progress count:', result.message);
                }
            } catch (error) {
                console.error('Failed to fetch jobs in progress count:', error);
            }
        }

        async function updatePendingJobsTable() {
            try {
                const response = await fetch('get_pending_jobs_data.php'); // Fetch JSON data
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                const result = await response.json();
                console.log('Data received for Active Job Cards:', result); // Log the received data

                const tableBody = document.querySelector('#pending-jobs-table tbody');
                const noJobsParagraph = document.getElementById('no-pending-jobs');
                const tableContainer = document.getElementById('pending-jobs-table-container');

                if (result.success && result.data.length > 0) {
                    let tableHtml = '';
                    result.data.forEach(job => {
                        const statusClass = job.status.replace(' ', '-').toLowerCase();
                        const assignedMechanicName = job.mechanic_id ? (mechanicsLookup[job.mechanic_id] || 'N/A') : 'N/A';
                        const laborCostFormatted = parseFloat(job.labor_cost || 0.00).toFixed(2);

                        tableHtml += `
                            <tr id="job-${job.job_card_id}">
                                <td>#${job.job_card_id}</td>
                                <td>
                                    ${job.make} ${job.model}
                                    <br><small>${job.registration_number}</small>
                                </td>
                                <td>${job.driver_name}</td>
                                <td>${job.description}</td>
                                <td><span class="status-${statusClass}">${job.status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())}</span></td>
                                <td>${assignedMechanicName}</td>
                                <td>${laborCostFormatted}</td>
                                <td>${new Date(job.created_at).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}</td>
                                <td>
                                    <button type="button" class="btn btn-primary open-job-management-modal-btn"
                                            data-job-id="${job.job_card_id}"
                                            data-vehicle-id="${job.vehicle_id}"
                                            data-vehicle-info="${job.make} ${job.model} (${job.registration_number})"
                                            data-job-description="${job.description}"
                                            data-current-status="${job.status}"
                                            data-assigned-mechanic-id="${job.mechanic_id || ''}"
                                            data-labor-cost="${job.labor_cost || 0.00}">
                                        <i class="fas fa-cog"></i> Manage Job
                                    </button>
                                </td>
                            </tr>
                        `;
                    });

                    if (!tableBody) { // If table doesn't exist, create it
                        tableContainer.innerHTML = `
                            <table class="table" id="pending-jobs-table">
                                <thead>
                                    <tr>
                                        <th>Job ID</th>
                                        <th>Vehicle</th>
                                        <th>Driver</th>
                                        <th>Description</th>
                                        <th>Status</th>
                                        <th>Assigned Mechanic</th>
                                        <th>Labor Cost</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                </tbody>
                            </table>`;
                        document.querySelector('#pending-jobs-table tbody').innerHTML = tableHtml;
                    } else {
                        tableBody.innerHTML = tableHtml;
                    }

                    if (noJobsParagraph) {
                        noJobsParagraph.style.display = 'none';
                    }
                } else {
                    console.log('No active job cards found or data fetch failed.'); // Log when no jobs are found
                    if (tableBody) {
                        tableBody.innerHTML = '';
                        const table = document.getElementById('pending-jobs-table');
                        if (table) table.remove();
                    }
                    if (noJobsParagraph) {
                        noJobsParagraph.style.display = 'block';
                    } else {
                        tableContainer.innerHTML = '<p class="text-center" id="no-pending-jobs">No active job cards found.</p>';
                    }
                }

                attachJobManagementModalListeners(); // Re-attach listeners after updating table content

            } catch (error) {
                console.error('Failed to fetch pending jobs table:', error);
            }
        }

        // Renamed variables for clarity and consistency
        const jobManagementModal = document.getElementById('jobManagementModal');
        const closeButton = jobManagementModal.querySelector('.close-button');
        const cancelJobManagementBtn = document.getElementById('cancelJobManagementBtn');
        const updateJobBtn = document.getElementById('updateJobBtn');
        const updateJobCardForm = document.getElementById('updateJobCardForm');
        const modalJobCardId = document.getElementById('modalJobCardId');
        const modalVehicleId = document.getElementById('modalVehicleId'); // New
        const modalVehicleInfo = document.getElementById('modalVehicleInfo');
        const modalJobDescription = document.getElementById('modalJobDescription');
        const modalStatusSelect = document.getElementById('modalStatusSelect'); // New
        const modalMechanicSelect = document.getElementById('modalMechanicSelect');
        const modalLaborCost = document.getElementById('modalLaborCost'); // New
        const jobManagementMessage = document.getElementById('jobManagementMessage'); // Updated message container ID

        // Detailed Vehicle Info Inputs
        const detailRegNumber = document.getElementById('modal-detail-reg-number');
        const detailMake = document.getElementById('modal-detail-make');
        const detailModel = document.getElementById('modal-detail-model');
        const detailYear = document.getElementById('modal-detail-year');
        const detailColor = document.getElementById('modal-detail-color');
        const detailMileage = document.getElementById('modal-detail-mileage');
        const detailDriver = document.getElementById('modal-detail-driver');


        const partCheckboxes = document.querySelectorAll('.parts-selection input[type="checkbox"]');
        const openJobManagementModalButtons = document.querySelectorAll('.open-job-management-modal-btn');

        function attachJobManagementModalListeners() {
            // It's important to re-query the buttons as the table content might be re-rendered
            const currentJobManagementButtons = document.querySelectorAll('.open-job-management-modal-btn');
            currentJobManagementButtons.forEach(button => {
                // Remove existing listeners to prevent duplicates
                button.removeEventListener('click', openJobManagementModalHandler); 
                // Add the new listener
                button.addEventListener('click', openJobManagementModalHandler);
            });
        }

        async function openJobManagementModalHandler() {
            const jobId = this.dataset.jobId;
            const vehicleId = this.dataset.vehicleId;
            const jobDescription = this.dataset.jobDescription;
            const currentStatus = this.dataset.currentStatus;
            const assignedMechanicId = this.dataset.assignedMechanicId;
            const laborCost = this.dataset.laborCost;

            // Populate the job card modal with the current job details
            modalJobCardId.value = jobId;
            modalVehicleId.value = vehicleId; // Set vehicle ID for AJAX call
            modalVehicleInfo.value = this.dataset.vehicleInfo; // Keep this for initial display
            modalJobDescription.value = jobDescription;
            modalStatusSelect.value = currentStatus.toLowerCase().replace(' ', '_'); // Set status
            modalMechanicSelect.value = assignedMechanicId || ''; // Set assigned mechanic
            modalLaborCost.value = parseFloat(laborCost).toFixed(2); // Set labor cost

            // Fetch detailed vehicle information via AJAX
            try {
                const response = await fetch(`${baseUrl}/dashboards/get_vehicle_details.php?vehicle_id=${vehicleId}`);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                const result = await response.json();

                if (result.success && result.data) {
                    const vehicle = result.data;
                    detailRegNumber.value = vehicle.registration_number;
                    detailMake.value = vehicle.make;
                    detailModel.value = vehicle.model;
                    detailYear.value = vehicle.year;
                    detailColor.value = vehicle.color;
                    detailMileage.value = vehicle.v_mileage;
                    detailDriver.value = vehicle.driver_name || 'N/A';
                } else {
                    console.error('Failed to fetch vehicle details:', result.message);
                    // Clear fields if details not found
                    detailRegNumber.value = 'N/A';
                    detailMake.value = 'N/A';
                    detailModel.value = 'N/A';
                    detailYear.value = 'N/A';
                    detailColor.value = 'N/A';
                    detailMileage.value = 'N/A';
                    detailDriver.value = 'N/A';
                }
            } catch (error) {
                console.error('Error fetching vehicle details:', error);
                // Clear fields on error
                detailRegNumber.value = 'Error';
                detailMake.value = 'Error';
                detailModel.value = 'Error';
                detailYear.value = 'Error';
                detailColor.value = 'Error';
                detailMileage.value = 'Error';
                detailDriver.value = 'Error';
            } finally {
                // Show the modal after the data is populated (or attempted to be populated)
                jobManagementModal.style.display = 'flex';
            }

            // Reset parts selection (if any parts were previously selected)
            partCheckboxes.forEach(checkbox => {
                checkbox.checked = false;
                const quantityInput = checkbox.closest('.part-item').querySelector('.part-quantity-input');
                if (quantityInput) {
                    quantityInput.style.display = 'none';
                    quantityInput.value = 1;
                }
            });
            // TODO: Logic to pre-select parts if the job already has parts requested (requires fetching job_card_parts data)
        }


        closeButton.addEventListener('click', () => {
            jobManagementModal.style.display = 'none';
        });

        cancelJobManagementBtn.addEventListener('click', () => {
            jobManagementModal.style.display = 'none';
        });

        window.addEventListener('click', (event) => {
            if (event.target === jobManagementModal) {
                jobManagementModal.style.display = 'none';
            }
        });

        // Toggle quantity input visibility based on checkbox
        partCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const quantityInput = this.closest('.part-item').querySelector('.part-quantity-input');
                if (quantityInput) {
                    quantityInput.style.display = this.checked ? 'block' : 'none';
                    if (!this.checked) {
                        quantityInput.value = 1;
                    }
                }
            });
        });

        // Handle job card update form submission
        updateJobCardForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            const formData = new FormData(this);

            // Basic validation for mechanic selection
            if (!modalMechanicSelect.value && modalStatusSelect.value !== 'pending' && modalStatusSelect.value !== 'assessment_requested') {
                showNotification('Please select a mechanic for this job or set status to pending/assessment requested.', 'error');
                return;
            }

            // Validate parts quantities if selected
            let partsValid = true;
            const selectedParts = {}; // To track selected parts and quantities
            for (const pair of formData.entries()) {
                if (pair[0].startsWith('parts[') && pair[0].endsWith('][selected]')) {
                    const partId = pair[0].match(/\[(\d+)\]/)[1];
                    const isSelected = pair[1] === '1';
                    if (isSelected) {
                        const quantityInputName = `parts[${partId}][quantity]`;
                        const quantity = parseInt(formData.get(quantityInputName));
                        const checkbox = document.querySelector(`input[data-part-id="${partId}"][type="checkbox"]`);
                        const maxQuantity = parseInt(checkbox.dataset.maxQuantity);

                        if (isNaN(quantity) || quantity <= 0 || quantity > maxQuantity) {
                            showNotification(`Invalid quantity for ${checkbox.closest('.part-item').querySelector('label').textContent.split(' (In Stock:')[0]}. Max available: ${maxQuantity}`, 'error');
                            partsValid = false;
                            break;
                        }
                        selectedParts[partId] = quantity;
                    }
                }
            }

            if (!partsValid) {
                return;
            }

            // If parts are selected, change the action to 'assign_job_with_parts'
            // Otherwise, keep it as 'update_job_status'
            if (Object.keys(selectedParts).length > 0) {
                formData.set('action', 'assign_job_with_parts');
            } else {
                formData.set('action', 'update_job_status');
            }


            updateJobBtn.disabled = true;
            updateJobBtn.textContent = 'Updating...';

            try {
                const response = await fetch('service_dashboard.php', {
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

                    // Refresh table and stats
                    updatePendingJobsTable();
                    updatePendingAssignmentsStat();
                    updateJobsInProgressStat();
                    updateCompletedJobsStat();
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


        document.addEventListener('DOMContentLoaded', () => {
            <?php if (!empty($page_message)): ?>
                showNotification('<?php echo $page_message; ?>', '<?php echo $page_message_type; ?>');
            <?php endif; ?>

            updateCompletedJobsStat();
            updateNewJobsTodayStat(); 
            updatePendingAssignmentsStat(); 
            updateJobsInProgressStat(); 
            updatePendingJobsTable(); // Initial load of the table

            setInterval(updateCompletedJobsStat, 15000); 
            setInterval(updateNewJobsTodayStat, 15000);
            setInterval(updatePendingAssignmentsStat, 15000);
            setInterval(updateJobsInProgressStat, 15000);
            setInterval(updatePendingJobsTable, 15000); 
        });
    </script>
</body>
</html>
