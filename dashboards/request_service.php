<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'driver') {
    header("Location: user_login.php");
    exit();
}

include '../db_connect.php';

$driver_id = $_SESSION['user_id'];
$vehicles = [];

// FETCH  VEHICLES
$vehicles_query = "SELECT vehicle_id, make, model, registration_number FROM vehicles WHERE driver_id = ? ORDER BY make, model";
$stmt = $conn->prepare($vehicles_query);
if ($stmt === false) {
    error_log("Failed to prepare statement for request_service vehicles: " . $conn->error);
    $_SESSION['error_message'] = "Error loading vehicles for request form.";
} else {
    if (!$stmt->bind_param("i", $driver_id) || !$stmt->execute()) {
        error_log("Failed to execute statement for request_service vehicles: " . $stmt->error);
        $_SESSION['error_message'] = "Error loading vehicles for request form.";
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
    <title>Request Service - Lewa Workshop</title>
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
                <a href="service_history.php" class="menu-item">
                    <i class="fas fa-history"></i> Service History
                </a>
                <a href="request_service.php" class="menu-item active">
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
                <h1>Request New Service</h1>
                <div class="user-info">
                    <div class="user-avatar"><?php echo htmlspecialchars(strtoupper(substr($_SESSION['full_name'] ?? '?', 0, 1))); ?></div>
                    <div>
                        <div><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Driver'); ?></div>
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
                <div class="card-title">Submit Service Request</div>
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
                        <?php if (empty($vehicles)): ?>
                            <p style="color: #e44d26; margin-top: 10px;">No vehicles assigned to you. Please contact administration.</p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="description">Service Description</label>
                        <textarea id="description" name="description" class="form-control" rows="5" required
                                  placeholder="Describe the issue or service needed in detail (e.g., 'Brake pads worn out, car pulling to the left', 'Engine oil change and general check-up')."></textarea>
                    </div>

                    <div class="form-group">
                        <label for="urgency">Urgency</label>
                        <select id="urgency" name="urgency" class="form-control">
                            <option value="normal">Normal</option>
                            <option value="high">High Priority</option>
                            <option value="emergency">Emergency</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary" <?php echo empty($vehicles) ? 'disabled' : ''; ?>>
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