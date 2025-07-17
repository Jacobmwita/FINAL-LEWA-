<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'driver') {
    header("Location: user_login.php");
    exit();
}

include '../db_connect.php';

$driver_id = $_SESSION['user_id'];
$service_history = [];

$history_query = "
    SELECT
        jc.job_card_id,
        jc.description,
        jc.status,
        jc.created_at,
        jc.completed_at,

        v.make,
        v.model,
        v.registration_number,
        u.full_name AS mechanic_name
    FROM
        job_cards jc
    JOIN
        vehicles v ON jc.vehicle_id = v.vehicle_id
    LEFT JOIN
        users u ON jc.assigned_to_mechanic_id = u.user_id
    WHERE
        v.driver_id = ?
    ORDER BY
        jc.created_at DESC";

$stmt = $conn->prepare($history_query);
if ($stmt === false) {
    error_log("Failed to prepare statement for service_history: " . $conn->error);
    $_SESSION['error_message'] = "Error fetching service history. Please try again.";
} else {
    if (!$stmt->bind_param("i", $driver_id) || !$stmt->execute()) {
        error_log("Failed to execute statement for service_history: " . $stmt->error);
        $_SESSION['error_message'] = "Error fetching service history. Please try again.";
    } else {
        $result = $stmt->get_result();
        if ($result) {
            $service_history = $result->fetch_all(MYSQLI_ASSOC);
        }
    }
    $stmt->close();
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service History - Lewa Workshop</title>
    <style>
 
        :root {
            --primary: #3498db;
            --secondary: #2c3e50;
            --success: #2ecc71;
            --warning: #f39c12;
            --light: #ecf0f1;
            --dark: #34495e;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f5f6fa;
        }
        .dashboard {
            display: grid;
            grid-template-columns: 250px 1fr;
            min-height: 100vh;
        }
        .sidebar {
            background-color: var(--secondary);
            color: white;
            padding: 20px 0;
        }
        .sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .sidebar-menu {
            padding: 20px 0;
        }
        .menu-item {
            padding: 12px 20px;
            color: var(--light);
            text-decoration: none;
            display: block;
            transition: all 0.3s;
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
        .main-content {
            padding: 20px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #ddd;
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
        }
        .card {
            background-color: white;
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
        }
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        .table th, .table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        .table th {
            background-color: #f8f9fa;
            color: var(--secondary);
        }
        .text-center {
            text-align: center;
        }
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            text-transform: capitalize; 
        }
        .status-pending {
            background-color: #fff3cd;
            color: #856404;
        }
        .status-in-progress {
            background-color: #cce5ff;
            color: #004085;
        }
        .status-completed {
            background-color: #d4edda;
            color: #155724;
        }
        .status-cancelled {
            background-color: #f8d7da;
            color: #721c24;
        }
        .logout-btn {
            background: none;
            border: none;
            color: var(--light);
            cursor: pointer;
            padding: 12px 20px;
            text-align: left;
            width: 100%;
            font-size: 14px;
        }
        .logout-btn:hover {
            color: white;
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
                <a href="driver_portal.php" class="menu-item">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="my_vehicles.php" class="menu-item">
                    <i class="fas fa-car"></i> My Vehicles
                </a>
                <a href="service_history.php" class="menu-item active">
                    <i class="fas fa-history"></i> Service History
                </a>
                <a href="request_service.php" class="menu-item">
                    <i class="fas fa-tools"></i> Request Service
                </a>
                <form action="logout.php" method="post" class="menu-item" style="padding: 0;">
                    <button type="submit" class="logout-btn">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </button>
                </form>
            </div>
        </div>
        <div class="main-content">
            <div class="header">
                <h1>Service History</h1>
                <div class="user-info">
                    <div class="user-avatar"><?php echo htmlspecialchars(strtoupper(substr($_SESSION['full_name'] ?? '?', 0, 1))); ?></div>
                    <div>
                        <div><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Driver'); ?></div>
                        <small>Driver</small>
                    </div>
                </div>
            </div>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div style="background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; padding: 10px; border-radius: 5px; margin-bottom: 20px;">
                    <?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-title">All Service Requests</div>
                <?php if (!empty($service_history)): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Vehicle</th>
                                <th>Description</th>
                                <th>Requested On</th>
                                <th>Mechanic</th>
                                <th>Completion Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($service_history as $request): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($request['make'] . ' ' . $request['model'] . ' (' . $request['registration_number'] . ')'); ?></td>
                                    <td><?php echo htmlspecialchars($request['description']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($request['created_at'])); ?></td>
<td><?php echo htmlspecialchars($request['mechanic_name'] ?? 'N/A'); ?></td>
<td><?php echo $request['completed_at'] ? date('M d, Y', strtotime($request['completed_at'])) : 'N/A'; ?></td>                                  
  <td><span class="status-badge status-<?php echo htmlspecialchars($request['status']); ?>"><?php echo htmlspecialchars($request['status']); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="text-center">No service history found.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>