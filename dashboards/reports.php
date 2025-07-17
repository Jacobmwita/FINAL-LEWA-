<?php

if (session_status() == PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../db_connect.php'; // Corrected path to db_connect.php

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
define('BASE_URL', "{$protocol}://{$host}/lewa");

if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['user_type']), ['workshop_manager', 'admin', 'administrator', 'supervisor','manager'])) {
    header('Location: ' . BASE_URL . '/user_login.php?error=no_access');
    exit();
}

$report_data = [];
$report_type = $_GET['report_type'] ?? '';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['report_type'])) {
    switch ($report_type) {
        case 'job_status_summary':
            //DATA  FETCHING  FROM  THE  JOB CARD
            // CORRECTED: Changed 'assigned_mechanic_id' to 'assigned_to_mechanic_id'
            $stmt = $conn->prepare("SELECT job_card_id, driver_id, vehicle_id, assigned_to_mechanic_id, description, status, created_at, completed_at, created_by_user_id, urgency FROM job_cards WHERE created_at >= ? AND created_at <= ? ORDER BY completed_at DESC");
            $stmt->bind_param("ss", $start_date, $end_date);
            $stmt->execute();
            $result = $stmt->get_result();
            while($row = $result->fetch_assoc()) {
                $report_data[] = $row;
            }
            $stmt->close();
            break;

        case 'mechanic_performance':
            // CORRECTED: Changed 'jc.mechanic_id' to 'jc.assigned_to_mechanic_id'
            $stmt = $conn->prepare("SELECT u.full_name, COUNT(jc.job_card_id) as total_jobs,
                                            SUM(CASE WHEN jc.status = 'completed' THEN 1 ELSE 0 END) as completed_jobs,
                                            AVG(DATEDIFF(jc.completed_at, jc.created_at)) as avg_days_to_complete
                                            FROM users u
                                            LEFT JOIN job_cards jc ON u.user_id = jc.assigned_to_mechanic_id
                                            WHERE jc.created_at BETWEEN ? AND ?
                                            GROUP BY u.user_id, u.full_name
                                            ORDER BY completed_jobs DESC");
            $stmt->bind_param("ss", $start_date, $end_date);
            $stmt->execute();
            $result = $stmt->get_result();
            while($row = $result->fetch_assoc()) {
                $report_data[] = $row;
            }
            $stmt->close();
            break;

        case 'inventory_usage_summary':
            $result = $conn->query("SELECT item_name, quantity, unit, last_updated FROM inventory ORDER BY item_name");
            if ($result) {
                while($row = $result->fetch_assoc()) {
                    $report_data[] = $row;
                }
            }
            break;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lewa Workshop - Reports</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>

        :root { --primary: #3498db; --secondary: #2980b9; --success: #2ecc71; --danger: #e74c3c; --warning: #f39c12; --light: #ecf0f1; --dark: #2c3e50; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; padding: 0; background-color: #f5f5f5; }
        .dashboard-container { display: grid; grid-template-columns: 250px 1fr; min-height: 100vh; }
        .sidebar { background-color: var(--dark); color: white; padding: 20px; }
        .sidebar h2 { text-align: center; color: var(--primary); margin-bottom: 30px; }
        .sidebar nav ul { list-style: none; padding: 0; margin: 0; }
        .sidebar nav ul li { margin-bottom: 10px; }
        .sidebar nav ul li a { color: white; text-decoration: none; display: block; padding: 10px 15px; border-radius: 5px; transition: background-color 0.3s ease; }
        .sidebar nav ul li a:hover, .sidebar nav ul li a.active { background-color: var(--secondary); }
        .main-content { padding: 20px; }
        h1 { color: var(--dark); margin-bottom: 30px; }
        .report-controls {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }
        .report-controls .form-group { flex: 1; min-width: 180px; }
        .report-controls button { padding: 10px 20px; }
        .report-output {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: var(--light); color: var(--dark); font-weight: 600; }
        .no-data-message { text-align: center; color: #555; margin-top: 20px; }
        .print-button-container {
            text-align: right;
            margin-top: 20px;
        }
        .print-button {
            background-color: var(--primary);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            transition: background-color 0.3s ease;
        }
        .print-button:hover {
            background-color: var(--secondary);
        }


        @media print {
            body * {
                visibility: hidden;
            }
            .report-output, .report-output * {
                visibility: visible;
            }
            .report-output {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                padding: 0;
                box-shadow: none;
            }
            .sidebar, .report-controls, .print-button-container {
                display: none !important;
            }
            h2 {
                text-align: center;
                margin-bottom: 20px;
            }
            table {
                border: 1px solid #ddd;
            }
            th, td {
                border: 1px solid #ddd;
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
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/admin_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/create_job_card.php"><i class="fas fa-clipboard-list"></i> View/Edit Job Cards</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/reports.php" class="active"><i class="fas fa-chart-line"></i> Generate Reports</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/manage_users.php"><i class="fas fa-users-cog"></i> Manage User Roles</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/parts_manager.php"><i class="fas fa-boxes"></i> Monitor Inventory</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/vehicle_details.php"><i class="fas fa-car-alt"></i> Vehicles</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </nav>
        </div>

        <div class="main-content">
            <h1>Generate Reports</h1>

            <div class="report-controls">
                <form action="" method="GET" style="display: contents;">
                    <div class="form-group">
                        <label for="report_type">Select Report:</label>
                        <select id="report_type" name="report_type" onchange="this.form.submit()">
                            <option value="">-- Select --</option>
                            <option value="job_status_summary" <?php echo ($report_type == 'job_status_summary') ? 'selected' : ''; ?>>Job Status Summary</option>
                            <option value="mechanic_performance" <?php echo ($report_type == 'mechanic_performance') ? 'selected' : ''; ?>>Mechanic Performance</option>
                            <option value="inventory_usage_summary" <?php echo ($report_type == 'inventory_usage_summary') ? 'selected' : ''; ?>>Current Inventory Levels</option>
                        </select>
                    </div>

                    <?php if ($report_type != 'inventory_usage_summary'): ?>
                        <div class="form-group">
                            <label for="start_date">Start Date:</label>
                            <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                        </div>
                        <div class="form-group">
                            <label for="end_date">End Date:</label>
                            <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                        </div>
                    <?php endif; ?>

                    <button type="submit"><i class="fas fa-file-alt"></i> Generate Report</button>
                </form>
            </div>

            <div class="report-output">
                <?php if (!empty($report_type)): ?>
                    <h2>Report: <?php echo ucwords(str_replace('_', ' ', $report_type)); ?></h2>
                    <?php if (!empty($report_data)): ?>
                        <?php if ($report_type == 'job_status_summary'): ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Job Card ID</th>
                                        <th>Driver ID</th>
                                        <th>Vehicle ID</th>
                                        <th>Assigned Mechanic ID</th> <!-- CORRECTED TABLE HEADER -->
                                        <th>Description</th>
                                        <th>Status</th>
                                        <th>Created At</th>
                                        <th>Completed At</th>
                                        <th>Created By</th>
                                        <th>Urgency</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($report_data as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['job_card_id']); ?></td>
                                        <td><?php echo htmlspecialchars($row['driver_id']); ?></td>
                                        <td><?php echo htmlspecialchars($row['vehicle_id']); ?></td>
                                        <td><?php echo htmlspecialchars($row['assigned_to_mechanic_id']); ?></td> <!-- CORRECTED COLUMN NAME -->
                                        <td><?php echo htmlspecialchars($row['description']); ?></td>
                                        <td><?php echo ucwords(str_replace('_', ' ', $row['status'])); ?></td>
                                        <td><?php echo date('M d, Y H:i A', strtotime($row['created_at'])); ?></td>
                                        <td><?php echo $row['completed_at'] ? date('M d, Y H:i A', strtotime($row['completed_at'])) : 'N/A'; ?></td>
                                        <td><?php echo htmlspecialchars($row['created_by_user_id']); ?></td>
                                        <td><?php echo ucwords(str_replace('_', ' ', $row['urgency'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php elseif ($report_type == 'mechanic_performance'): ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Mechanic Name</th>
                                        <th>Total Jobs</th>
                                        <th>Completed Jobs</th>
                                        <th>Avg. Days to Complete</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($report_data as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['full_name'] ?? 'Unassigned'); ?></td>
                                        <td><?php echo htmlspecialchars($row['total_jobs']); ?></td>
                                        <td><?php echo htmlspecialchars($row['completed_jobs']); ?></td>
                                        <td><?php echo round($row['avg_days_to_complete'] ?? 0, 2); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php elseif ($report_type == 'inventory_usage_summary'): ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Item Name</th>
                                        <th>Quantity</th>
                                        <th>Unit</th>
                                        <th>Last Updated</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($report_data as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['item_name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['quantity']); ?></td>
                                        <td><?php echo htmlspecialchars($row['unit']); ?></td>
                                        <td><?php echo date('M d, Y H:i A', strtotime($row['last_updated'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                        <div class="print-button-container">
                            <button class="print-button" onclick="window.print()"><i class="fas fa-print"></i> Print Report</button>
                        </div>
                    <?php else: ?>
                        <p class="no-data-message">No data available for this report type and selected period.</p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="no-data-message">Please select a report type and click "Generate Report".</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>