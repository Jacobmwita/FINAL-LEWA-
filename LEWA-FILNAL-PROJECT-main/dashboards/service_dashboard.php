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
$jobs_query = "SELECT j.job_card_id, j.description, j.created_at, j.completed_at,
                                v.make, v.model, v.registration_number,
                                d.full_name as driver_name
                        FROM job_cards j
                        JOIN vehicles v ON j.vehicle_id = v.vehicle_id
                        JOIN users d ON j.driver_id = d.user_id
                        WHERE j.status = 'pending'
                        ORDER BY j.created_at DESC";
$jobs_stmt = $conn->prepare($jobs_query);
$jobs = []; 
if ($jobs_stmt) {
    $jobs_stmt->execute();
    $jobs = $jobs_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $jobs_stmt->close();
} else {
    error_log("Failed to prepare pending job cards query: " . $conn->error);
}

$mechanics_query = "SELECT user_id, full_name FROM users WHERE user_type = 'mechanic' AND is_active = 1";
$mechanics_stmt = $conn->prepare($mechanics_query);
$mechanics = []; 
if ($mechanics_stmt) {
    $mechanics_stmt->execute();
    $mechanics = $mechanics_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $mechanics_stmt->close();
} else {
    error_log("Failed to prepare mechanics query: " . $conn->error);
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
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Advisor Dashboard - Lewa Workshop</title>
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
                    <li><a href="/dashboards/service_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="/dashboards/manage_job_cards.php"><i class="fas fa-clipboard-list"></i> Manage Job Cards</a></li>
                    <li><a href="/dashboards/manage_vehicles.php"><i class="fas fa-car"></i> Manage Vehicles</a></li>
                    <li><a href="/dashboards/drivers.php"><i class="fas fa-users"></i> Drivers</a></li>
                    <li><a href="/dashboards/manage_mechanics.php"><i class="fas fa-wrench"></i> Mechanics</a></li>
                    <li><a href="/dashboards/manage_vehicles.php"><i class="fas fa-car-alt"></i> Vehicles</a></li>
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
                <div class="card-title">Pending Job Cards</div>
                <div id="pending-jobs-table-container">
                    <?php if (count($jobs) > 0): ?>
                        <table class="table" id="pending-jobs-table">
                            <thead>
                                <tr>
                                    <th>Job ID</th>
                                    <th>Vehicle</th>
                                    <th>Driver</th>
                                    <th>Description</th>
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
                                        <td><?php echo date('M d, H:i', strtotime($job['created_at'])); ?></td>
                                        <td>
                                            <button type="button" class="btn btn-primary open-assign-modal-btn" 
                                                    data-job-id="<?php echo htmlspecialchars($job['job_card_id']); ?>"
                                                    data-vehicle-info="<?php echo htmlspecialchars($job['make'] . ' ' . $job['model'] . ' (' . $job['registration_number'] . ')'); ?>"
                                                    data-job-description="<?php echo htmlspecialchars($job['description']); ?>">
                                                <i class="fas fa-user-check"></i> Assign Mechanic & Parts
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="text-center" id="no-pending-jobs">No pending job cards found.</p>
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

    <div id="assignmentModal" class="modal">
        <div class="modal-content">
            <span class="close-button">&times;</span>
            <h2>Assign Mechanic and Request Parts</h2>
            <form id="assignJobWithPartsForm" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" id="modalJobCardId" name="job_card_id">
                
                <div class="form-group">
                    <label for="modalVehicleInfo">Vehicle:</label>
                    <input type="text" id="modalVehicleInfo" class="form-control" readonly>
                </div>

                <div class="form-group">
                    <label for="modalJobDescription">Job Description:</label>
                    <textarea id="modalJobDescription" class="form-control" rows="3" readonly></textarea>
                </div>

                <div class="form-group">
                    <label for="modalMechanicSelect">Assign Mechanic:</label>
                    <select name="mechanic_id" id="modalMechanicSelect" class="form-control" required>
                        <option value="">Select Mechanic</option>
                        <?php foreach ($mechanics as $mechanic): ?>
                            <option value="<?php echo htmlspecialchars($mechanic['user_id']); ?>">
                                <?php echo htmlspecialchars($mechanic['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
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
                                                data-part-id="<?php echo htmlspecialchars($part['item_id']); ?>">
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
                        <?php else: ?>
                            <p>No spare parts available in stock.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-group text-center">
                    <button type="submit" class="btn btn-primary" id="assignAndPartsBtn">
                        <i class="fas fa-save"></i> Assign & Request Parts
                    </button>
                    <button type="button" class="btn btn-secondary" id="cancelAssignmentBtn">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://kit.fontawesome.com/your-font-awesome-kit-id.js" crossorigin="anonymous"></script> <script>
        
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
                const response = await fetch('get_pending_jobs_table.php'); 
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                const html = await response.text(); 

                const tableBody = document.querySelector('#pending-jobs-table tbody');
                const noJobsParagraph = document.getElementById('no-pending-jobs');

                if (html.trim() !== '') {
                    if (tableBody) {
                        tableBody.innerHTML = html;
                    } else {
                        const tableContainer = document.getElementById('pending-jobs-table-container');
                        tableContainer.innerHTML = `
                            <table class="table" id="pending-jobs-table">
                                <thead>
                                    <tr>
                                        <th>Job ID</th>
                                        <th>Vehicle</th>
                                        <th>Driver</th>
                                        <th>Description</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${html}
                                </tbody>
                            </table>`;
                    }
                    if (noJobsParagraph) {
                        noJobsParagraph.style.display = 'none'; 
                    }
                } else {
                    if (tableBody) {
                        tableBody.innerHTML = ''; 
                        const table = document.getElementById('pending-jobs-table');
                        if (table) table.remove();
                    }
                    if (noJobsParagraph) {
                        noJobsParagraph.style.display = 'block'; 
                    } else {
                        const tableContainer = document.getElementById('pending-jobs-table-container');
                        tableContainer.innerHTML = '<p class="text-center" id="no-pending-jobs">No pending job cards found.</p>';
                    }
                }

                attachAssignModalListeners();

            } catch (error) {
                console.error('Failed to fetch pending jobs table:', error);
            }
        }

        function attachAssignModalListeners() {
            document.querySelectorAll('.open-assign-modal-btn').forEach(button => {
                button.removeEventListener('click', openAssignModalHandler);
                button.addEventListener('click', openAssignModalHandler);
            });
        }

        function openAssignModalHandler() {
            const jobId = this.dataset.jobId;
            const vehicleInfo = this.dataset.vehicleInfo;
            const jobDescription = this.dataset.jobDescription;

            modalJobCardId.value = jobId;
            modalVehicleInfo.value = vehicleInfo;
            modalJobDescription.value = jobDescription;

            modalMechanicSelect.value = '';
            partCheckboxes.forEach(checkbox => {
                checkbox.checked = false;
                const quantityInput = checkbox.closest('.part-item').querySelector('.part-quantity-input');
                if (quantityInput) {
                    quantityInput.style.display = 'none';
                    quantityInput.value = 1; 
                }
            });

            assignmentModal.style.display = 'flex'; 
        }

        const assignmentModal = document.getElementById('assignmentModal');
        const closeButton = document.querySelector('.close-button');
        const cancelAssignmentBtn = document.getElementById('cancelAssignmentBtn');
        const assignAndPartsBtn = document.getElementById('assignAndPartsBtn');
        const assignJobWithPartsForm = document.getElementById('assignJobWithPartsForm');
        const modalJobCardId = document.getElementById('modalJobCardId');
        const modalVehicleInfo = document.getElementById('modalVehicleInfo');
        const modalJobDescription = document.getElementById('modalJobDescription');
        const modalMechanicSelect = document.getElementById('modalMechanicSelect');
        const partCheckboxes = document.querySelectorAll('.parts-selection input[type="checkbox"]');
        const pendingJobsTableContainer = document.getElementById('pending-jobs-table-container');

        attachAssignModalListeners();

        closeButton.addEventListener('click', () => {
            assignmentModal.style.display = 'none';
        });

        cancelAssignmentBtn.addEventListener('click', () => {
            assignmentModal.style.display = 'none';
        });

        window.addEventListener('click', (event) => {
            if (event.target === assignmentModal) {
                assignmentModal.style.display = 'none';
            }
        });

        partCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const quantityInput = this.closest('.part-item').querySelector('.part-quantity-input');
                if (quantityInput) {
                    quantityInput.max = this.dataset.maxQuantity || quantityInput.max; 
                    quantityInput.style.display = this.checked ? 'block' : 'none';
                    if (!this.checked) {
                        quantityInput.value = 1; 
                    }
                }
            });
        });

        assignJobWithPartsForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const jobCardId = modalJobCardId.value;
        

            if (!modalMechanicSelect.value) {
                showNotification('Please select a mechanic to assign this job', 'error');
                return;
            }

            let partsValid = true;
            for (const pair of formData.entries()) {
                if (pair[0].startsWith('parts[') && pair[0].endsWith('][quantity]')) {
                    const quantity = parseInt(pair[1]);

                    const partId = pair[0].match(/\[(\d+)\]/)[1]; 
                    const checkbox = document.querySelector(`input[data-part-id="${partId}"][type="checkbox"]`);
                    
                    if (checkbox && checkbox.checked) {
                        const quantityInput = checkbox.closest('.part-item').querySelector('.part-quantity-input');
                        const maxQuantity = parseInt(quantityInput.max);  
                        if (isNaN(quantity) || quantity <= 0 || quantity > maxQuantity) {
                            showNotification(`Invalid quantity for a selected part. Max available: ${maxQuantity}`, 'error');
                            partsValid = false;
                            break;
                        }
                    }
                }
            }

            if (!partsValid) {
                return; 
            }

            assignAndPartsBtn.disabled = true; 
            assignAndPartsBtn.textContent = 'Assigning...';

            try {
                const response = await fetch('assign_job_with_parts.php', { 
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const result = await response.json();

                if (result.status === 'success') {
                    showNotification('Job assigned and parts requested successfully!', 'success');
                    assignmentModal.style.display = 'none';


                    updatePendingJobsTable(); 
                    updatePendingAssignmentsStat(); 
                    updateJobsInProgressStat();
                    updateCompletedJobsStat(); 
                } else {
                    showNotification(result.message || 'Error assigning job and requesting parts.', 'error');
                }
            } catch (error) {
                console.error('Assignment failed:', error);
                showNotification('An unexpected error occurred during assignment.', 'error');
            } finally {
                assignAndPartsBtn.disabled = false;
                assignAndPartsBtn.innerHTML = '<i class="fas fa-save"></i> Assign & Request Parts';
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

            setInterval(updateCompletedJobsStat, 15000); 
            setInterval(updateNewJobsTodayStat, 15000);
            setInterval(updatePendingAssignmentsStat, 15000);
            setInterval(updateJobsInProgressStat, 15000);
            setInterval(updatePendingJobsTable, 15000); 
        });
    </script>
</body>
</html>