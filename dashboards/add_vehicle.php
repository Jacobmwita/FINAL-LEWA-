<?php
// C:\xampp\htdocs\lewa\dashboards\add_vehicle.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
define('BASE_URL', "{$protocol}://{$host}/lewa");

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'workshop_manager') {
    header('Location: ' . BASE_URL . '/user_login.php');
    exit();
}

include '../db_connect.php';

$success_message = '';
$error_message = '';

// Fetch drivers for the dropdown
$drivers = [];
$result = $conn->query("SELECT user_id, username FROM users WHERE user_type = 'driver' ORDER BY username");
if ($result) {
    $drivers = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();
} else {
    $error_message = "Error fetching drivers: " . $conn->error;
}

// Handle form submission for adding vehicles
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $driver_id = intval($_POST['driver_id']);
    $make = $conn->real_escape_string($_POST['make']);
    $model = $conn->real_escape_string($_POST['model']);
    $registration_number = $conn->real_escape_string($_POST['registration_number']);
    $year = intval($_POST['year']);
    $color = $conn->real_escape_string($_POST['color']);
    // Existing new fields
    $v_notes = $conn->real_escape_string($_POST['v_notes'] ?? '');
    $v_milage = intval($_POST['v_milage'] ?? 0);

    // New fields to be added
    $engine_number = $conn->real_escape_string($_POST['engine_number'] ?? '');
    $chassis_number = $conn->real_escape_string($_POST['chassis_number'] ?? '');
    $fuel_type = $conn->real_escape_string($_POST['fuel_type'] ?? '');

    if (empty($driver_id) || empty($make) || empty($model) || empty($registration_number)) {
        $error_message = "Driver, Make, Model, and Registration Number are required.";
    } elseif (!empty($year) && (!is_numeric($year) || $year < 1900 || $year > date('Y') + 1)) {
        $error_message = "Invalid year entered.";
    } elseif (!empty($v_milage) && (!is_numeric($v_milage) || $v_milage < 0)) {
        $error_message = "Mileage must be a non-negative number.";
    } else {
        // Check if registration number already exists
        $check_stmt = $conn->prepare("SELECT vehicle_id FROM vehicles WHERE registration_number = ?");
        $check_stmt->bind_param("s", $registration_number);
        $check_stmt->execute();
        $check_stmt->store_result();

        if ($check_stmt->num_rows > 0) {
            $error_message = "Vehicle with this registration number already exists.";
        } else {
            // Prepare the SQL statement to insert all vehicle details, including notes, mileage, engine no, chassis no, and fuel type
            $stmt = $conn->prepare("INSERT INTO vehicles (driver_id, make, model, registration_number, year, color, v_notes, v_milage, engine_number, chassis_number, fuel_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            // 'issssisssss' -> integer, string, string, string, integer, string, string, integer, string, string, string
            $stmt->bind_param("issssisssss", $driver_id, $make, $model, $registration_number, $year, $color, $v_notes, $v_milage, $engine_number, $chassis_number, $fuel_type);

            if ($stmt->execute()) {
                $success_message = "Vehicle added successfully!";
                $_POST = []; // Clear form fields after successful submission
            } else {
                $error_message = "Error adding vehicle: " . $stmt->error;
            }
            $stmt->close();
        }
        $check_stmt->close();
    }
}

// Fetch all vehicles for display
$vehicles = [];
$sql_vehicles = "SELECT v.*, u.username AS driver_name FROM vehicles v LEFT JOIN users u ON v.driver_id = u.user_id ORDER BY v.make, v.model";
$result_vehicles = $conn->query($sql_vehicles);
if ($result_vehicles) {
    $vehicles = $result_vehicles->fetch_all(MYSQLI_ASSOC);
    $result_vehicles->free();
} else {
    $error_message .= " Error fetching vehicles for display: " . $conn->error;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Vehicle - Lewa Workshop</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #eef2f6; margin: 0; padding: 20px; color: #333; }
        .container { max-width: 90%; margin: 20px auto; background-color: #ffffff; padding: 30px; border-radius: 10px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        h1, h2 { color: #2c3e50; margin-bottom: 30px; text-align: center; }
        .alert { padding: 15px; margin-bottom: 20px; border-radius: 5px; }
        .alert-success { background-color: #d4edda; color: #155724; }
        .alert-danger { background-color: #f8d7da; color: #721c24; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; }
        input[type="text"], input[type="number"], select, textarea {
            width: calc(100% - 22px);
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
        }
        textarea {
            resize: vertical;
            min-height: 80px;
        }
        .btn { padding: 10px 20px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; width: 100%; }
        .btn:hover { background-color: #0069d9; }
        .back-link { display: inline-block; margin-bottom: 20px; color: #007bff; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }

        /* Vehicle List Styling */
        .vehicle-list-section { margin-top: 50px; }
        .vehicle-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .vehicle-table th, .vehicle-table td {
            border: 1px solid #e0e0e0;
            padding: 12px 15px;
            text-align: left;
        }
        .vehicle-table th {
            background-color: #f2f2f2;
            font-weight: 700;
            color: #555;
            text-transform: uppercase;
            font-size: 14px;
        }
        .vehicle-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .vehicle-table tr:hover {
            background-color: #f1f1f1;
        }
        .no-vehicles {
            text-align: center;
            padding: 20px;
            color: #777;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="<?php echo BASE_URL; ?>/dashboards/admin_dashboard.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
        <h1>Add New Vehicle</h1>
        
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success"><?php echo $success_message; ?></div>
        <?php endif; ?>
        
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger"><?php echo $error_message; ?></div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="driver_id">Assigned Driver</label>
                <select id="driver_id" name="driver_id" required>
                    <option value="">Select Driver</option>
                    <?php foreach ($drivers as $driver): ?>
                        <option value="<?php echo $driver['user_id']; ?>" <?php echo (isset($_POST['driver_id']) && $_POST['driver_id'] == $driver['user_id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($driver['username']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="make">Make</label>
                <input type="text" id="make" name="make" value="<?php echo htmlspecialchars($_POST['make'] ?? ''); ?>" required>
            </div>
            <div class="form-group">
                <label for="model">Model</label>
                <input type="text" id="model" name="model" value="<?php echo htmlspecialchars($_POST['model'] ?? ''); ?>" required>
            </div>
            <div class="form-group">
                <label for="registration_number">Registration Number</label>
                <input type="text" id="registration_number" name="registration_number" value="<?php echo htmlspecialchars($_POST['registration_number'] ?? ''); ?>" required>
            </div>
            <div class="form-group">
                <label for="engine_number">Engine Number (Optional)</label>
                <input type="text" id="engine_number" name="engine_number" value="<?php echo htmlspecialchars($_POST['engine_number'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="chassis_number">Chassis Number (Optional)</label>
                <input type="text" id="chassis_number" name="chassis_number" value="<?php echo htmlspecialchars($_POST['chassis_number'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="fuel_type">Fuel Type (Optional)</label>
                <input type="text" id="fuel_type" name="fuel_type" value="<?php echo htmlspecialchars($_POST['fuel_type'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="year">Year (Optional)</label>
                <input type="number" id="year" name="year" value="<?php echo htmlspecialchars($_POST['year'] ?? ''); ?>" min="1900" max="<?php echo date('Y') + 1; ?>">
            </div>
            <div class="form-group">
                <label for="color">Color (Optional)</label>
                <input type="text" id="color" name="color" value="<?php echo htmlspecialchars($_POST['color'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
                <label for="v_notes">Vehicle Notes (Optional)</label>
                <textarea id="v_notes" name="v_notes"><?php echo htmlspecialchars($_POST['v_notes'] ?? ''); ?></textarea>
            </div>
            <div class="form-group">
                <label for="v_milage">Initial Mileage (km/miles) (Optional)</label>
                <input type="number" id="v_milage" name="v_milage" value="<?php echo htmlspecialchars($_POST['v_milage'] ?? ''); ?>" min="0">
            </div>
            
            <button type="submit" class="btn">
                <i class="fas fa-car"></i> Add Vehicle
            </button>
        </form>

    

        <div class="vehicle-list-section">
            <h2>All Vehicles in Database</h2>
            <?php if (!empty($vehicles)): ?>
                <table class="vehicle-table">
                    <thead>
                        <tr>
                            <th>Reg. No.</th>
                            <th>Make</th>
                            <th>Model</th>
                            <th>Year</th>
                            <th>Color</th>
                            <th>Assigned Driver</th>
                            <th>Mileage</th>
                            <th>Engine No.</th>
                            <th>Chassis No.</th>
                            <th>Fuel Type</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
    <?php foreach ($vehicles as $vehicle): ?>
        <tr>
            <td><?php echo htmlspecialchars((string)$vehicle['registration_number']); ?></td>
            <td><?php echo htmlspecialchars((string)$vehicle['make']); ?></td>
            <td><?php echo htmlspecialchars((string)$vehicle['model']); ?></td>
            <td><?php echo htmlspecialchars((string)$vehicle['year']); ?></td>
            <td><?php echo htmlspecialchars((string)$vehicle['color']); ?></td>
            <td><?php echo htmlspecialchars($vehicle['driver_name'] ?? 'N/A'); ?></td>
            <td><?php echo htmlspecialchars((string)$vehicle['v_milage']); ?></td>
            <td><?php echo htmlspecialchars((string)$vehicle['engine_number']); ?></td>
            <td><?php echo htmlspecialchars((string)$vehicle['chassis_number']); ?></td>
            <td><?php echo htmlspecialchars((string)$vehicle['fuel_type']); ?></td>
            <td><?php echo htmlspecialchars((string)$vehicle['v_notes']); ?></td>
        </tr>
    <?php endforeach; ?>
</tbody>
                        
                    
                </table>
            <?php else: ?>
                <p class="no-vehicles">No vehicles found in the database.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>