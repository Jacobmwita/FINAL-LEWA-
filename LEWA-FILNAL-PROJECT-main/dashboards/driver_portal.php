<?php
session_start();

//SECURITY CHECKUP
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'driver') {
    header("Location: user_login.php");
    exit();
}

include '../db_connect.php'; 

//DATABASE  CONNECTION   VALIDATION
if (!isset($conn) || $conn->connect_error) {
    error_log("FATAL ERROR: Database connection failed in " . __FILE__ . ": " . ($conn->connect_error ?? 'Connection object not set.'));
    $_SESSION['error_message'] = "A critical system error occurred. Please try again later.";
    header("Location: user_login.php");
    exit();
}

//  DRIVER  DETAILS
$driver_id = $_SESSION['user_id'];
$driver_full_name = 'Driver';
$driver_initial = '?';

$driver_query = "SELECT full_name, email FROM users WHERE user_id = ?";
$stmt = $conn->prepare($driver_query);

if ($stmt === false) {
    error_log("Failed to prepare statement for driver details: " . $conn->error);
    $_SESSION['error_message'] = "Error fetching driver details. Please try again.";
    header("Location: driver_portal.php");
    exit();
}

if (!$stmt->bind_param("i", $driver_id) || !$stmt->execute()) {
    error_log("Failed to execute statement for driver details: " . $stmt->error);
    $stmt->close();
    $_SESSION['error_message'] = "Error fetching driver details. Please try again.";
    header("Location: driver_portal.php");
    exit();
}

$result = $stmt->get_result();
if ($result && $driver_data = $result->fetch_assoc()) {
    $driver_full_name = htmlspecialchars($driver_data['full_name']);
    $driver_initial = strtoupper(substr($driver_data['full_name'], 0, 1));
} else {
    error_log("SECURITY ALERT: Driver with user_id {$driver_id} not found in DB");
    $_SESSION['error_message'] = "Your user account could not be found. Please log in again.";
    header("Location: user_login.php");
    exit();
}
$stmt->close();

// VEHICLE QUERY
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
    $_SESSION['error_message'] = "Error fetching vehicle list. Please try again.";
} else {
    if (!$stmt->bind_param("i", $driver_id) || !$stmt->execute()) {
        error_log("Failed to execute statement for vehicles: " . $stmt->error);
        $_SESSION['error_message'] = "Error fetching vehicle list. Please try again.";
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
        v.registration_number
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
    $_SESSION['error_message'] = "Error fetching pending requests. Please try again.";
} else {
    if (!$stmt->bind_param("i", $driver_id) || !$stmt->execute()) {
        error_log("Failed to execute statement for pending requests: " . $stmt->error);
        $_SESSION['error_message'] = "Error fetching pending requests. Please try again.";
    } else {
        $result = $stmt->get_result();
        if ($result) {
            $pending_requests = $result->fetch_all(MYSQLI_ASSOC);
        }
    }
    $stmt->close();
}

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
        
        .btn-success {
            background-color: var(--success);
            color: white;
        }
        
        .btn-success:hover {
            background-color: #27ae60;
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
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
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
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        
        .text-center {
            text-align: center;
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
                <a href="driver_portal.php" class="menu-item active">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="dashboards/my_vehicles.php" class="menu-item">
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
                <h1>Driver Dashboard</h1>
                <div class="user-info">
                    <div class="user-avatar"><?php echo htmlspecialchars($driver_initial); ?></div>
                    <div>
                        <div><?php echo htmlspecialchars($driver_full_name); ?></div>
                        <small>Driver</small>
                    </div>
                </div>
            </div>
            
            <?php
            if (isset($_SESSION['success_message'])) {
                echo '<div style="background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; padding: 10px; border-radius: 5px; margin-bottom: 20px;">' . htmlspecialchars($_SESSION['success_message']) . '</div>';
                unset($_SESSION['success_message']);
            }
            if (isset($_SESSION['error_message'])) {
                echo '<div style="background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; padding: 10px; border-radius: 5px; margin-bottom: 20px;">' . htmlspecialchars($_SESSION['error_message']) . '</div>';
                unset($_SESSION['error_message']);
            }
            ?>

            <div class="card">
                <div class="card-title">Quick Actions</div>
                <div style="display: flex; gap: 10px;">
                    <a href="request_service.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Request New Service
                    </a>
                    <a href="my_vehicles.php" class="btn btn-success">
                        <i class="fas fa-car"></i> View My Vehicles
                    </a>
                </div>
            </div>
            
            <div class="card">
                <div class="card-title">My Vehicles</div>
                <?php if (!empty($vehicles)): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Make & Model</th>
                                <th>License Plate</th>
                                <th>Last Service</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vehicles as $vehicle): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model']); ?></td>
                                    <td><?php echo htmlspecialchars($vehicle['registration_number']); ?></td>
                                    <td><?php echo $vehicle['last_service'] ? date('M d, Y', strtotime($vehicle['last_service'])) : 'Never'; ?></td>
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
                    <p class="text-center">No vehicles assigned to you.</p>
                <?php endif; ?>
            </div>
            
            <div class="card">
                <div class="card-title">Pending Service Requests</div>
                <?php if (!empty($pending_requests)): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Vehicle</th>
                                <th>Description</th>
                                <th>Request Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_requests as $request): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(($request['make'] ?? 'N/A') . ' ' . ($request['model'] ?? '') . ' (' . ($request['registration_number'] ?? 'N/A') . ')'); ?></td>
                                    <td><?php echo htmlspecialchars($request['description']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($request['created_at'])); ?></td>
                                    <td><span class="status-badge status-pending">Pending</span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="text-center">No pending service requests.</p>
                <?php endif; ?>
            </div>
            
            <div class="card">
                <div class="card-title">Quick Service Request</div>
                <form action="process_request.php" method="post">
                    <div class="form-group">
                        <label for="vehicle">Vehicle</label>
                        <select id="vehicle" name="vehicle_id" class="form-control" required>
                            <option value="">Select Vehicle</option>
                            <?php foreach ($vehicles as $vehicle): ?>
                                <option value="<?php echo htmlspecialchars($vehicle['vehicle_id']); ?>">
                                    <?php echo htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model'] . ' (' . $vehicle['registration_number'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="description">Service Description</label>
                        <textarea id="description" name="description" class="form-control" rows="3" required
                                  placeholder="Describe the issue or service needed"></textarea>
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

    <script>
        document.querySelector('form[action="process_request.php"]').addEventListener('submit', function(e) {
            const description = document.getElementById('description').value.trim();
            const vehicleId = document.getElementById('vehicle').value;

            if (vehicleId === "") {
                alert('Please select a vehicle.');
                e.preventDefault();
                return;
            }

            if (description.length < 10) {
                alert('Please provide a more detailed description of the service needed (at least 10 characters).');
                e.preventDefault();
            }
        });
    </script>
</body>
</html>