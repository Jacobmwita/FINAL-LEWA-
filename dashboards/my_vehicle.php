<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'driver') {
    header("Location: user_login.php");
    exit();
}

include '../db_connect.php';

$driver_id = $_SESSION['user_id'];
$vehicles = [];

$vehicles_query = "
    SELECT
        v.vehicle_id,
        v.make,
        v.model,
        v.registration_number,
        v.year,
        v.milage,
        v.notes,
        MAX(j.created_at) AS last_service
    FROM
        vehicles v
    LEFT JOIN
        job_cards j ON v.vehicle_id = j.vehicle_id
    WHERE
        v.driver_id = ?
    GROUP BY
        v.vehicle_id, v.make, v.model, v.registration_number, v.year, v_mileage, v.notes
    ORDER BY
        v.make, v.model";

$stmt = $conn->prepare($vehicles_query);
if ($stmt === false) {
    error_log("Failed to prepare statement for my_vehicles: " . $conn->error);
    $_SESSION['error_message'] = "Error fetching vehicle list. Please try again.";
} else {
    if (!$stmt->bind_param("i", $driver_id) || !$stmt->execute()) {
        error_log("Failed to execute statement for my_vehicles: " . $stmt->error);
        $_SESSION['error_message'] = "Error fetching vehicle list. Please try again.";
    } else {
        $result = $stmt->get_result();
        if ($result) {
            $vehicles = $result->fetch_all(MYSQLI_ASSOC);
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
    <title>My Vehicles - Lewa Workshop</title>
    <link rel="stylesheet" href="style.css"> <style>
    
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
        .btn {
            padding: 8px 15px;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .btn i {
            margin-right: 5px;
        }
        .btn-primary {
            background-color: var(--primary);
            color: white;
        }
        .btn-primary:hover {
            background-color: #2980b9;
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
                <a href="my_vehicles.php" class="menu-item active">
                    <i class="fas fa-car"></i> My Vehicles
                </a>
                <a href="service_history.php" class="menu-item">
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
                <h1>My Vehicles</h1>
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
                <div class="card-title">Vehicles Assigned to Me</div>
                <?php if (!empty($vehicles)): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Make</th>
                                <th>Model</th>
                                <th>Reg. Number</th>
                                <th>Year</th>
                                <th>Mileage</th>
                                <th>Last Service</th>
                                <th>Notes</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vehicles as $vehicle): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($vehicle['make']); ?></td>
                                    <td><?php echo htmlspecialchars($vehicle['model']); ?></td>
                                    <td><?php echo htmlspecialchars($vehicle['registration_number']); ?></td>
                                    <td><?php echo htmlspecialchars($vehicle['year']); ?></td>
                                    <td><?php echo number_format(htmlspecialchars($vehicle['v_mileage'])); ?> km</td>
                                    <td><?php echo $vehicle['last_service'] ? date('M d, Y', strtotime($vehicle['last_service'])) : 'Never'; ?></td>
                                    <td><?php echo htmlspecialchars($vehicle['notes'] ?? 'N/A'); ?></td>
                                    <td>
                                        <a href="vehicle_details.php?id=<?php echo htmlspecialchars($vehicle['vehicle_id']); ?>" class="btn btn-primary">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="text-center">No vehicles are currently assigned to you.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>