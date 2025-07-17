<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
//include '/../db_connect.php';
include __DIR__ . '/../db_connect.php';
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
define('BASE_URL', "{$protocol}://{$host}/lewa");

if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['user_type']), ['workshop_manager', 'admin', 'administrator', 'manager'])) {
    header('Location: ' . BASE_URL . '/user_login.php?error=no_access');
    exit();
}

$message = '';
$message_type = '';

// POST REQUESTES
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = 'Invalid CSRF token.';
        $message_type = 'error';
    } else {
        try {
            if (isset($_POST['action'])) {
                if ($_POST['action'] === 'add_vehicle') {
                    $registration_number = trim($_POST['registration_number']);
                    $make = trim($_POST['make']);
                    $model = trim($_POST['model']);
                    $year = (int)$_POST['year'];
                    $owner_id = empty($_POST['owner_id']) ? NULL : (int)$_POST['owner_id'];

                    if (empty($registration_number) || empty($make) || empty($model) || $year <= 1900) {
                        throw new Exception("Registration number, make, model, and valid year are required.");
                    }

                    $stmt = $conn->prepare("INSERT INTO vehicles (registration_number, make, model, year, owner_id) VALUES (?, ?, ?, ?, ?)");
                    $stmt->bind_param("sssii", $registration_number, $make, $model, $year, $owner_id);
                    if ($stmt->execute()) {
                        $message = "Vehicle '{$registration_number}' added successfully.";
                        $message_type = 'success';
                    } else {
                        throw new Exception("Error adding vehicle: " . $stmt->error);
                    }
                    $stmt->close();
                } elseif ($_POST['action'] === 'update_vehicle') {
                    $vehicle_id = (int)$_POST['vehicle_id'];
                    $registration_number = trim($_POST['registration_number']);
                    $make = trim($_POST['make']);
                    $model = trim($_POST['model']);
                    $year = (int)$_POST['year'];
                    $owner_id = empty($_POST['owner_id']) ? NULL : (int)$_POST['owner_id'];

                    if (empty($registration_number) || empty($make) || empty($model) || $year <= 1900) {
                        throw new Exception("Registration number, make, model, and valid year are required.");
                    }

                    $stmt = $conn->prepare("UPDATE vehicles SET registration_number = ?, make = ?, model = ?, year = ?, owner_id = ? WHERE vehicle_id = ?");
                    $stmt->bind_param("sssiii", $registration_number, $make, $model, $year, $owner_id, $vehicle_id);
                    if ($stmt->execute()) {
                        $message = "Vehicle ID {$vehicle_id} updated successfully.";
                        $message_type = 'success';
                    } else {
                        throw new Exception("Error updating vehicle: " . $stmt->error);
                    }
                    $stmt->close();
                } elseif ($_POST['action'] === 'delete_vehicle') {
                    $vehicle_id = (int)$_POST['vehicle_id'];
                    $stmt = $conn->prepare("DELETE FROM vehicles WHERE vehicle_id = ?");
                    $stmt->bind_param("i", $vehicle_id);
                    if ($stmt->execute()) {
                        $message = "Vehicle ID {$vehicle_id} deleted successfully.";
                        $message_type = 'success';
                    } else {
                        throw new Exception("Error deleting vehicle: " . $stmt->error);
                    }
                    $stmt->close();
                }
            }
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
}

// FETCH VEHICLES
$vehicles = [];
$sql = "SELECT v.*, u.full_name AS owner_name
        FROM vehicles v
        LEFT JOIN users u ON v.driver_id = u.user_id
        ORDER BY v.registration_number ASC";
$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $vehicles[] = $row;
    }
} else {
    $message = "Error fetching vehicles: " . $conn->error;
    $message_type = 'error';
}

// FETCH DRIVERS
$owners = [];
$result = $conn->query("SELECT user_id, full_name FROM users WHERE LOWER(user_type) = 'driver' ORDER BY full_name");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $owners[] = $row;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lewa Workshop - Manage Vehicles</title>
    <style>
        :root {
            --primary: #3498db;
            --secondary: #2980b9;
            --success: #2ecc71;
            --danger: #e74c3c;
            --warning: #f39c12;
            --light: #ecf0f1;
            --dark: #2c3e50;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f5f5f5;
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
        }

        .sidebar h2 {
            text-align: center;
            color: var(--primary);
            margin-bottom: 30px;
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
            display: block;
            padding: 10px 15px;
            border-radius: 5px;
            transition: background-color 0.3s ease;
        }

        .sidebar nav ul li a:hover,
        .sidebar nav ul li a.active {
            background-color: var(--secondary);
        }

        .main-content {
            padding: 20px;
        }

        h1 {
            color: var(--dark);
            margin-bottom: 30px;
        }

        .action-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th,
        td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        th {
            background-color: var(--light);
            color: var(--dark);
            font-weight: 600;
        }

        .action-buttons a,
        .action-buttons button {
            margin-right: 8px;
            color: #3498db;
            text-decoration: none;
            cursor: pointer;
            background: none;
            border: none;
            font-size: 1rem;
            padding: 0;
            transition: color 0.2s ease;
        }

        .action-buttons a:hover,
        .action-buttons button:hover {
            color: #2980b9;
        }

        .action-buttons button.delete {
            color: #e74c3c;
        }

        .action-buttons button.delete:hover {
            color: #c0392b;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.4);
            padding-top: 60px;
        }

        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 600px;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .close-button {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
        }

        .close-button:hover,
        .close-button:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        .form-group input,
        .form-group select {
            width: calc(100% - 22px);
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }

        .modal-buttons {
            text-align: right;
            margin-top: 20px;
        }

        .modal-buttons button {
            margin-left: 10px;
        }

        .alert {
            padding: 10px 15px;
            border-radius: 4px;
            margin-bottom: 15px;
            font-weight: bold;
        }

        .alert-success {
            background-color: rgba(46, 204, 113, 0.2);
            color: #2ecc71;
        }

        .alert-error {
            background-color: rgba(231, 76, 60, 0.2);
            color: #e74c3c;
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
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/job_cards.php"><i class="fas fa-clipboard-list"></i> View/Edit Job Cards</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/reports.php"><i class="fas fa-chart-line"></i> Generate Reports</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/users.php"><i class="fas fa-users-cog"></i> Manage User Roles</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/inventory.php"><i class="fas fa-warehouse"></i> Monitor Inventory</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/vehicles.php" class="active"><i class="fas fa-car-alt"></i> Vehicles</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </nav>
        </div>

        <div class="main-content">
            <h1>Manage Vehicles</h1>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div class="action-card">
                <h3>Add New Vehicle</h3>
                <form id="addVehicleForm" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="add_vehicle">

                    <div class="form-group">
                        <label for="registration_number">Registration Number</label>
                        <input type="text" id="registration_number" name="registration_number" required>
                    </div>
                    <div class="form-group">
                        <label for="make">Make</label>
                        <input type="text" id="make" name="make" required>
                    </div>
                    <div class="form-group">
                        <label for="model">Model</label>
                        <input type="text" id="model" name="model" required>
                    </div>
                    <div class="form-group">
                        <label for="year">Year</label>
                        <input type="number" id="year" name="year" min="1900" max="<?php echo date('Y') + 1; ?>" value="<?php echo date('Y'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="owner_id">Owner (Driver)</label>
                        <select id="owner_id" name="owner_id">
                            <option value="">-- No Owner --</option>
                            <?php foreach ($owners as $owner): ?>
                                <option value="<?php echo htmlspecialchars($owner['user_id']); ?>">
                                    <?php echo htmlspecialchars($owner['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit"><i class="fas fa-car"></i> Add Vehicle</button>
                </form>
            </div>

            <div class="action-card">
                <h3>Existing Vehicles</h3>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Reg. No.</th>
                            <th>Make</th>
                            <th>Model</th>
                            <th>Year</th>
                            <th>Owner</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($vehicles)): ?>
                            <?php foreach ($vehicles as $vehicle): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($vehicle['vehicle_id']); ?></td>
                                    <td><?php echo htmlspecialchars($vehicle['registration_number']); ?></td>
                                    <td><?php echo htmlspecialchars($vehicle['make']); ?></td>
                                    <td><?php echo htmlspecialchars($vehicle['model']); ?></td>
                                    <td><?php echo htmlspecialchars($vehicle['year']); ?></td>
                                    <td><?php echo htmlspecialchars($vehicle['owner_name'] ?? 'N/A'); ?></td>
                                    <td class="action-buttons">
                                        <a href="#" onclick="openEditVehicleModal(<?php echo htmlspecialchars(json_encode($vehicle)); ?>)" title="Edit Vehicle"><i class="fas fa-edit"></i></a>
                                        <button type="button" class="delete" onclick="confirmDeleteVehicle(<?php echo $vehicle['vehicle_id']; ?>, '<?php echo htmlspecialchars($vehicle['registration_number']); ?>')" title="Delete Vehicle"><i class="fas fa-trash-alt"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7">No vehicles found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="editVehicleModal" class="modal">
        <div class="modal-content">
            <span class="close-button" onclick="closeEditVehicleModal()">&times;</span>
            <h2>Edit Vehicle Details</h2>
            <form id="editVehicleForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="update_vehicle">
                <input type="hidden" id="edit-vehicle-id" name="vehicle_id">

                <div class="form-group">
                    <label for="edit-registration-number">Registration Number</label>
                    <input type="text" id="edit-registration-number" name="registration_number" required>
                </div>
                <div class="form-group">
                    <label for="edit-make">Make</label>
                    <input type="text" id="edit-make" name="make" required>
                </div>
                <div class="form-group">
                    <label for="edit-model">Model</label>
                    <input type="text" id="edit-model" name="model" required>
                </div>
                <div class="form-group">
                    <label for="edit-year">Year</label>
                    <input type="number" id="edit-year" name="year" min="1900" max="<?php echo date('Y') + 1; ?>" required>
                </div>
                <div class="form-group">
                    <label for="edit-owner-id">Owner (Driver)</label>
                    <select id="edit-owner-id" name="owner_id">
                        <option value="">-- No Owner --</option>
                        <?php foreach ($owners as $owner): ?>
                            <option value="<?php echo htmlspecialchars($owner['user_id']); ?>">
                                <?php echo htmlspecialchars($owner['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modal-buttons">
                    <button type="button" onclick="closeEditVehicleModal()">Cancel</button>
                    <button type="submit">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const editVehicleModal = document.getElementById('editVehicleModal');
        const addVehicleForm = document.getElementById('addVehicleForm');

        function openEditVehicleModal(vehicleData) {
            document.getElementById('edit-vehicle-id').value = vehicleData.vehicle_id;
            document.getElementById('edit-registration-number').value = vehicleData.registration_number;
            document.getElementById('edit-make').value = vehicleData.make;
            document.getElementById('edit-model').value = vehicleData.model;
            document.getElementById('edit-year').value = vehicleData.year;
            document.getElementById('edit-owner-id').value = vehicleData.owner_id || ''; 
            editVehicleModal.style.display = 'block';
        }

        function closeEditVehicleModal() {
            editVehicleModal.style.display = 'none';
        }

        function confirmDeleteVehicle(vehicleId, registrationNumber) {
            if (confirm(`Are you sure you want to delete vehicle "${registrationNumber}" (ID: ${vehicleId})? This action cannot be undone.`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '<?php echo BASE_URL; ?>/vehicles.php';

                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'vehicle_id';
                idInput.value = vehicleId;
                form.appendChild(idInput);

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete_vehicle';
                form.appendChild(actionInput);

                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = '<?php echo $_SESSION['csrf_token']; ?>';
                form.appendChild(csrfInput);

                document.body.appendChild(form);
                form.submit();
            }
        }

        addVehicleForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(addVehicleForm);
            try {
                const response = await fetch('<?php echo BASE_URL; ?>/vehicles.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.success) {
                    alert(result.message);
                    addVehicleForm.reset();
                    window.location.reload();
                } else {
                    alert('Error: ' + (result.message || 'An unknown error occurred.'));
                }
            } catch (error) {
                console.error('Error adding vehicle:', error);
                alert('An error occurred. Please try again.');
            }
        });

        
        document.getElementById('editVehicleForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            try {
                const response = await fetch('<?php echo BASE_URL; ?>/vehicles.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.success) {
                    alert(result.message);
                    closeEditVehicleModal();
                    window.location.reload();
                } else {
                    alert('Error: ' + (result.message || 'An unknown error occurred.'));
                }
            } catch (error) {
                console.error('Error updating vehicle:', error);
                alert('An error occurred. Please try again.');
            }
        });

        window.onclick = function(event) {
            if (event.target == editVehicleModal) {
                closeEditVehicleModal();
            }
        }
    </script>
</body>

</html>