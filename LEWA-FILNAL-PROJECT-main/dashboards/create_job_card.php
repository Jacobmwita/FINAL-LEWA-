<?php


if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db_connect.php';

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
define('BASE_URL', "{$protocol}://{$host}/lewa");


if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['user_type']), ['workshop_manager', 'admin', 'administrator', 'manager'])) {
    header('Location: ' . BASE_URL . '/user_login.php?error=no_access');
    exit();
}

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = 'Invalid CSRF token. Please try again.';
        $message_type = 'error';
    } else {
        try {
            if (isset($_POST['action']) && $_POST['action'] === 'update_job_card') {

                $job_card_id = filter_input(INPUT_POST, 'job_card_id', FILTER_VALIDATE_INT);
                $vehicle_reg = filter_input(INPUT_POST, 'vehicle_registration', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                $issue_desc = filter_input(INPUT_POST, 'issue_description', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                $assigned_to_raw = filter_input(INPUT_POST, 'assigned_to', FILTER_SANITIZE_FULL_SPECIAL_CHARS); // Get raw string for NULL check

                $assigned_to = empty($assigned_to_raw) ? NULL : filter_var($assigned_to_raw, FILTER_VALIDATE_INT);

                if ($job_card_id === false || empty($vehicle_reg) || empty($issue_desc) || empty($status)) {
                    $message = 'Invalid input for updating job card. Please fill all required fields.';
                    $message_type = 'error';
                } else {
                    $stmt = $conn->prepare("UPDATE job_cards SET vehicle_registration = ?, issue_description = ?, status = ?, assigned_to = ? WHERE job_card_id = ?");

              
                    $stmt->bind_param("ssssi", $vehicle_reg, $issue_desc, $status, $assigned_to, $job_card_id);

                    if ($stmt->execute()) {
                        $message = "Job card ID {$job_card_id} updated successfully.";
                        $message_type = 'success';
                    } else {
                        $message = "Error updating job card: " . $stmt->error;
                        $message_type = 'error';
                    }
                    $stmt->close();
                }
            } elseif (isset($_POST['action']) && $_POST['action'] === 'delete_job_card') {
                $job_card_id = filter_input(INPUT_POST, 'job_card_id', FILTER_VALIDATE_INT);

                if ($job_card_id === false) {
                    $message = 'Invalid job card ID for deletion.';
                    $message_type = 'error';
                } else {
                    $stmt = $conn->prepare("DELETE FROM job_cards WHERE job_card_id = ?");
                    $stmt->bind_param("i", $job_card_id);
                    if ($stmt->execute()) {
                        $message = "Job card ID {$job_card_id} deleted successfully.";
                        $message_type = 'success';
                    } else {
                        $message = "Error deleting job card: " . $stmt->error;
                        $message_type = 'error';
                    }
                    $stmt->close();
                }
            }
        } catch (Exception $e) {
            $message = 'An unexpected error occurred: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
}


$job_cards = [];
$sql = "SELECT jc.*, u.full_name AS assigned_mechanic_name
        FROM job_cards jc
        LEFT JOIN users u ON jc.assigned_to = u.user_id
        ORDER BY jc.created_at DESC";
$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $job_cards[] = $row;
    }
} else {
    $message = "Error fetching job cards: " . $conn->error;
    $message_type = 'error';
}


$mechanics = [];
$result = $conn->query("SELECT user_id, full_name FROM users WHERE LOWER(user_type) = 'mechanic' ORDER BY full_name");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $mechanics[] = $row;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lewa Workshop - Job Cards</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <style>
        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f7f6;
            color: #333;
        }

        .dashboard-container {
            display: grid;
            grid-template-columns: 250px 1fr;
            min-height: 100vh;
        }

        .sidebar {
            background-color: #2c3e50;
            color: white;
            padding: 20px;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
        }
        .sidebar h2 {
            text-align: center;
            color: #3498db;
            margin-bottom: 30px;
            font-size: 1.8em;
            border-bottom: 2px solid #3498db;
            padding-bottom: 15px;
        }
        .sidebar p {
            text-align: center;
            margin-bottom: 20px;
            font-size: 1.1em;
            color: #ecf0f1;
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
            background-color: #34495e;
            color: #3498db;
        }
        .sidebar nav ul li a.active {
            border-left: 5px solid #3498db;
            padding-left: 10px;
        }

        .main-content {
            padding: 30px;
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 0 15px rgba(0,0,0,0.05);
            margin: 20px;
        }
        h1 {
            color: #2c3e50;
            margin-bottom: 30px;
            font-size: 2.2em;
            border-bottom: 2px solid #ecf0f1;
            padding-bottom: 10px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border-radius: 8px;
            overflow: hidden; 
        }
        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }
        th {
            background-color: #ecf0f1;
            color: #2c3e50;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.95em;
        }
        td {
            background-color: #fdfdfd;
            color: #34495e;
            font-size: 0.9em;
        }
        tr:hover td {
            background-color: #f0f0f0;
        }

        .status-pending { color: #e67e22; font-weight: bold; } 
        .status-in-progress { color: #3498db; font-weight: bold; } 
        .status-completed { color: #27ae60; font-weight: bold; } 
        .status-on-hold { color: #9b59b6; font-weight: bold; } 
        .status-cancelled { color: #e74c3c; font-weight: bold; } 


        
        .action-buttons a, .action-buttons button {
            margin-right: 10px;
            color: #3498db;
            text-decoration: none;
            cursor: pointer;
            background: none;
            border: none;
            font-size: 1.1rem;
            padding: 5px;
            transition: color 0.2s ease, transform 0.2s ease;
        }
        .action-buttons a:hover, .action-buttons button:hover {
            color: #2980b9;
            transform: translateY(-2px);
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
            z-index: 1000; 
            left: 0;
            top: 0;
            width: 100%;
            height: 100%; 
            overflow: auto; 
            background-color: rgba(0,0,0,0.6); 
            padding-top: 50px;
            animation: fadeIn 0.3s ease-out;
        }
        .modal-content {
            background-color: #fefefe;
            margin: 5% auto; 
            padding: 30px;
            border: 1px solid #888;
            width: 90%; 
            max-width: 650px;
            border-radius: 10px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.25);
            position: relative;
            animation: slideInTop 0.4s ease-out;
        }
        .close-button {
            color: #aaa;
            float: right;
            font-size: 32px;
            font-weight: bold;
            position: absolute;
            top: 15px;
            right: 25px;
        }
        .close-button:hover,
        .close-button:focus {
            color: #333;
            text-decoration: none;
            cursor: pointer;
        }

        
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #34495e;
            font-size: 0.95em;
        }
        .form-group input,
        .form-group textarea,
        .form-group select {
            width: calc(100% - 24px);
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 6px;
            box-sizing: border-box;
            font-size: 1em;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
            outline: none;
        }
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        
        .modal-buttons {
            text-align: right;
            margin-top: 30px;
            border-top: 1px solid #eee;
            padding-top: 20px;
        }
        .modal-buttons button {
            padding: 10px 20px;
            margin-left: 10px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1em;
            transition: background-color 0.2s ease, transform 0.2s ease;
        }
        .modal-buttons button[type="submit"] {
            background-color: #2ecc71;
            color: white;
        }
        .modal-buttons button[type="submit"]:hover {
            background-color: #27ae60;
            transform: translateY(-1px);
        }
        .modal-buttons button[type="button"] { 
            background-color: #95a5a6;
            color: white;
        }
        .modal-buttons button[type="button"]:hover {
            background-color: #7f8c8d;
            transform: translateY(-1px);
        }

        
        .alert {
            padding: 12px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: bold;
            display: flex;
            align-items: center;
            font-size: 0.95em;
        }
        .alert i {
            margin-right: 10px;
            font-size: 1.3em;
        }
        .alert-success {
            background-color: #d4edda; 
            color: #155724; 
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            background-color: #f8d7da; 
            color: #721c24; 
            border: 1px solid #f5c6cb;
        }

        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes slideInTop {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
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
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/job_cards.php" class="active"><i class="fas fa-clipboard-list"></i> View/Edit Job Cards</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dasboards/reports.php"><i class="fas fa-chart-line"></i> Generate Reports</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/users.php"><i class="fas fa-users-cog"></i> Manage User Roles</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/inventory.php"><i class="fas fa-warehouse"></i> Monitor Inventory</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/vehicles.php"><i class="fas fa-car-alt"></i> Vehicles</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </nav>
        </div>

        <div class="main-content">
            <h1>All Job Cards</h1>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <i class="fas <?php echo ($message_type === 'success') ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Vehicle Reg</th>
                        <th>Issue Description</th>
                        <th>Status</th>
                        <th>Assigned To</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($job_cards)): ?>
                        <?php foreach ($job_cards as $job_card): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($job_card['job_card_id']); ?></td>
                            <td><?php echo htmlspecialchars($job_card['vehicle_registration']); ?></td>
                            <td><?php echo htmlspecialchars(substr($job_card['issue_description'], 0, 70)) . (strlen($job_card['issue_description']) > 70 ? '...' : ''); ?></td>
                            <td class="status-<?php echo str_replace('_', '-', $job_card['status']); ?>">
                                <?php echo ucwords(str_replace('_', ' ', $job_card['status'])); ?>
                            </td>
                            <td><?php echo htmlspecialchars($job_card['assigned_mechanic_name'] ?? 'N/A'); ?></td>
                            <td><?php echo date('M d, Y H:i A', strtotime($job_card['created_at'])); ?></td>
                            <td class="action-buttons">
                                <a href="#" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($job_card), ENT_QUOTES, 'UTF-8'); ?>)" title="Edit Job Card"><i class="fas fa-edit"></i></a>
                                <button type="button" class="delete" onclick="confirmDelete(<?php echo $job_card['job_card_id']; ?>)" title="Delete Job Card"><i class="fas fa-trash-alt"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7">No job cards found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="editJobCardModal" class="modal">
        <div class="modal-content">
            <span class="close-button" onclick="closeEditModal()">&times;</span>
            <h2>Edit Job Card</h2>
            <form id="editJobCardForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="update_job_card">
                <input type="hidden" id="edit-job-card-id" name="job_card_id">

                <div class="form-group">
                    <label for="edit-vehicle-registration">Vehicle Registration</label>
                    <input type="text" id="edit-vehicle-registration" name="vehicle_registration" required>
                </div>
                <div class="form-group">
                    <label for="edit-issue-description">Issue Description</label>
                    <textarea id="edit-issue-description" name="issue_description" rows="5" required></textarea>
                </div>
                <div class="form-group">
                    <label for="edit-status">Status</label>
                    <select id="edit-status" name="status" required>
                        <option value="pending">Pending</option>
                        <option value="in_progress">In Progress</option>
                        <option value="completed">Completed</option>
                        <option value="on_hold">On Hold</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="edit-assigned-to">Assigned To (Mechanic)</label>
                    <select id="edit-assigned-to" name="assigned_to">
                        <option value="">-- Select Mechanic --</option>
                        <?php foreach ($mechanics as $mechanic): ?>
                            <option value="<?php echo htmlspecialchars($mechanic['user_id']); ?>">
                                <?php echo htmlspecialchars($mechanic['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modal-buttons">
                    <button type="button" onclick="closeEditModal()">Cancel</button>
                    <button type="submit">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const editModal = document.getElementById('editJobCardModal');
        const editForm = document.getElementById('editJobCardForm');

        function openEditModal(jobCardData) {
            document.getElementById('edit-job-card-id').value = jobCardData.job_card_id;
            document.getElementById('edit-vehicle-registration').value = jobCardData.vehicle_registration;
            document.getElementById('edit-issue-description').value = jobCardData.issue_description;
            document.getElementById('edit-status').value = jobCardData.status;

            const assignedToSelect = document.getElementById('edit-assigned-to');

            if (jobCardData.assigned_to === null || jobCardData.assigned_to === 'N/A' || jobCardData.assigned_to === undefined) {
                assignedToSelect.value = ''; 
            } else {
                assignedToSelect.value = jobCardData.assigned_to;
            }

            editModal.style.display = 'block'; 
        }

    
        function closeEditModal() {
            editModal.style.display = 'none'; 
            editForm.reset(); 
        }

       
        function confirmDelete(jobCardId) {
            if (confirm(`Are you sure you want to delete Job Card ID ${jobCardId}? This action cannot be undone.`)) {

                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '<?php echo BASE_URL; ?>/job_cards.php'; 

              
                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'job_card_id';
                idInput.value = jobCardId;
                form.appendChild(idInput);

               
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete_job_card';
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

        window.onclick = function(event) {
            if (event.target == editModal) {
                closeEditModal();
            }
        }
    </script>
</body>
</html>