<?php


if (session_status() == PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/db_connect.php';

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
define('BASE_URL', "{$protocol}://{$host}/lewa");

if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['user_type']), ['admin', 'administrator'])) {

    header('Location: ' . BASE_URL . '/user_login.php?error=no_access');
    exit();
}

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = 'Invalid CSRF token.';
        $message_type = 'error';
    } else {
        try {
            if (isset($_POST['action']) && $_POST['action'] === 'update_user') {
                $user_id = $_POST['user_id'];
                $full_name = $_POST['full_name'];
                $email = $_POST['email'];
                $user_type = $_POST['user_type'];
                $phone = $_POST['phone'] ?? '';

                $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, user_type = ?, phone = ? WHERE user_id = ?");
                $stmt->bind_param("ssssi", $full_name, $email, $user_type, $phone, $user_id);

                if ($stmt->execute()) {
                    $message = "User ID {$user_id} updated successfully.";
                    $message_type = 'success';
                } else {
                    $message = "Error updating user: " . $stmt->error;
                    $message_type = 'error';
                }
                $stmt->close();
            } elseif (isset($_POST['action']) && $_POST['action'] === 'delete_user') {
                $user_id = $_POST['user_id'];

                if ($user_id == $_SESSION['user_id']) {
                    throw new Exception("You cannot delete your own account.");
                }
                $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
                $stmt->bind_param("i", $user_id);
                if ($stmt->execute()) {
                    $message = "User ID {$user_id} deleted successfully.";
                    $message_type = 'success';
                } else {
                    $message = "Error deleting user: " . $stmt->error;
                    $message_type = 'error';
                }
                $stmt->close();
            } elseif (isset($_POST['action']) && $_POST['action'] === 'add_user') {
                 $required = ['username', 'email', 'full_name', 'user_type'];
                foreach ($required as $field) {
                    if (empty($_POST[$field])) {
                        throw new Exception("$field is required");
                    }
                }

                $username = $_POST['username'];
                $email = $_POST['email'];
                $full_name = $_POST['full_name'];
                $phone = $_POST['phone'] ?? '';
                $user_type = $_POST['user_type'];
                $password = password_hash('Temp123!', PASSWORD_DEFAULT); 

                $stmt = $conn->prepare("INSERT INTO users (username, email, password, full_name, phone, user_type) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssss", $username, $email, $password, $full_name, $phone, $user_type);

                if ($stmt->execute()) {
                    $message = "User '{$username}' added successfully. Default password is 'Temp123!'.";
                    $message_type = 'success';
                } else {
                    $message = "Error adding user: " . $stmt->error;
                    $message_type = 'error';
                }
                $stmt->close();
            }
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
}

//PICK  ALL  USERS
$users = [];
$sql = "SELECT user_id, username, email, full_name, phone, user_type FROM users ORDER BY user_id DESC";
$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
} else {
    $message = "Error fetching users: " . $conn->error;
    $message_type = 'error';
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lewa Workshop - Manage Users</title>
    <style>

:root { --primary: #3498db; --secondary: #2980b9; --success: #2ecc71; --danger: #e74c3c; --warning: #f39c12; --light: #ecf0f1; --dark: #2c3e50; 
}
        body {
             font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; padding: 0; background-color: #f5f5f5; 
            }
        .dashboard-container {
             display: grid; grid-template-columns: 250px 1fr; min-height: 100vh;
             }
        .sidebar {
             background-color: var(--dark); color: white; padding: 20px;
             }
        .sidebar h2 {
             text-align: center; color: var(--primary); margin-bottom: 30px; 
        }
        .sidebar nav ul {
             list-style: none; padding: 0; margin: 0;
             }
        .sidebar nav ul li { margin-bottom: 10px; }
        .sidebar nav ul li a { color: white; text-decoration: none; display: block; padding: 10px 15px; border-radius: 5px; transition: background-color 0.3s ease; }
        .sidebar nav ul li a:hover, .sidebar nav ul li a.active { background-color: var(--secondary); }
        .main-content { padding: 20px; }
        h1 { color: var(--dark); margin-bottom: 30px; }
        .action-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: var(--light); color: var(--dark); font-weight: 600; }
        .action-buttons a, .action-buttons button {
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
        .action-buttons a:hover, .action-buttons button:hover { color: #2980b9; }
        .action-buttons button.delete { color: #e74c3c; }
        .action-buttons button.delete:hover { color: #c0392b; }

        /* Modal styles from job_cards.php */
        .modal {
            display: none; position: fixed; z-index: 1; left: 0; top: 0; width: 100%; height: 100%; overflow: auto;
            background-color: rgba(0,0,0,0.4); padding-top: 60px;
        }
        .modal-content {
            background-color: #fefefe; margin: 5% auto; padding: 20px; border: 1px solid #888; width: 80%;
            max-width: 600px; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        .close-button { color: #aaa; float: right; font-size: 28px; font-weight: bold; }
        .close-button:hover, .close-button:focus { color: black; text-decoration: none; cursor: pointer; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input, .form-group select {
            width: calc(100% - 22px); padding: 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box;
        }
        .modal-buttons { text-align: right; margin-top: 20px; }
        .modal-buttons button { margin-left: 10px; }
        .alert {
            padding: 10px 15px; border-radius: 4px; margin-bottom: 15px; font-weight: bold;
        }
        .alert-success { background-color: rgba(46, 204, 113, 0.2); color: #2ecc71; }
        .alert-error { background-color: rgba(231, 76, 60, 0.2); color: #e74c3c; }
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
                    <li><a href="<?php echo BASE_URL; ?>/job_cards.php"><i class="fas fa-clipboard-list"></i> View/Edit Job Cards</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/reports.php"><i class="fas fa-chart-line"></i> Generate Reports</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/users.php" class="active"><i class="fas fa-users-cog"></i> Manage User Roles</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/inventory.php"><i class="fas fa-warehouse"></i> Monitor Inventory</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/vehicles.php"><i class="fas fa-car-alt"></i> Vehicles</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </nav>
        </div>

        <div class="main-content">
            <h1>Manage Users & Roles</h1>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div class="action-card">
                <h3>Add New User</h3>
                <form id="addUserForm" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="add_user">

                    <div class="form-group">
                        <label for="new_username">Username</label>
                        <input type="text" id="new_username" name="username" required>
                    </div>
                     <div class="form-group">
                        <label for="new_full_name">Full Name</label>
                        <input type="text" id="new_full_name" name="full_name" required>
                    </div>
                    <div class="form-group">
                        <label for="new_email">Email</label>
                        <input type="email" id="new_email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label for="new_phone">Phone</label>
                        <input type="text" id="new_phone" name="phone">
                    </div>
                    <div class="form-group">
                        <label for="new_user_type">User Role</label>
                        <select id="new_user_type" name="user_type" required>
                            <option value="">-- Select Role --</option>
                            <option value="driver">Driver</option>
                            <option value="mechanic">Mechanic</option>
                            <option value="workshop_manager">Workshop Manager</option>
                            <option value="admin">Admin</option>
                            <option value="administrator">Administrator</option>
                        </select>
                    </div>
                    <button type="submit"><i class="fas fa-user-plus"></i> Add User</button>
                </form>
            </div>

            <div class="action-card">
                <h3>Existing Users</h3>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Role</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($users)): ?>
                            <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($user['user_id']); ?></td>
                                <td><?php echo htmlspecialchars($user['username']); ?></td>
                                <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td><?php echo htmlspecialchars($user['phone']); ?></td>
                                <td><?php echo ucwords(str_replace('_', ' ', $user['user_type'])); ?></td>
                                <td class="action-buttons">
                                    <a href="#" onclick="openEditUserModal(<?php echo htmlspecialchars(json_encode($user)); ?>)" title="Edit User"><i class="fas fa-user-edit"></i></a>
                                    <?php if ($user['user_id'] != $_SESSION['user_id']): // Prevent admin from deleting themselves ?>
                                        <button type="button" class="delete" onclick="confirmDeleteUser(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')" title="Delete User"><i class="fas fa-user-times"></i></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7">No users found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="editUserModal" class="modal">
        <div class="modal-content">
            <span class="close-button" onclick="closeEditUserModal()">&times;</span>
            <h2>Edit User Details</h2>
            <form id="editUserForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" id="edit-user-id" name="user_id">

                <div class="form-group">
                    <label for="edit-username">Username</label>
                    <input type="text" id="edit-username" name="username" readonly disabled> </div>
                <div class="form-group">
                    <label for="edit-full-name">Full Name</label>
                    <input type="text" id="edit-full-name" name="full_name" required>
                </div>
                <div class="form-group">
                    <label for="edit-email">Email</label>
                    <input type="email" id="edit-email" name="email" required>
                </div>
                <div class="form-group">
                    <label for="edit-phone">Phone</label>
                    <input type="text" id="edit-phone" name="phone">
                </div>
                <div class="form-group">
                    <label for="edit-user-type">User Role</label>
                    <select id="edit-user-type" name="user_type" required>
                        <option value="driver">Driver</option>
                        <option value="mechanic">Mechanic</option>
                        <option value="workshop_manager">Workshop Manager</option>
                        <option value="admin">Admin</option>
                        <option value="administrator">Administrator</option>
                    </select>
                </div>
                <div class="modal-buttons">
                    <button type="button" onclick="closeEditUserModal()">Cancel</button>
                    <button type="submit">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const editUserModal = document.getElementById('editUserModal');
        const addUserForm = document.getElementById('addUserForm');

        function openEditUserModal(userData) {
            document.getElementById('edit-user-id').value = userData.user_id;
            document.getElementById('edit-username').value = userData.username;
            document.getElementById('edit-full-name').value = userData.full_name;
            document.getElementById('edit-email').value = userData.email;
            document.getElementById('edit-phone').value = userData.phone;
            document.getElementById('edit-user-type').value = userData.user_type;
            editUserModal.style.display = 'block';
        }

        function closeEditUserModal() {
            editUserModal.style.display = 'none';
        }

        function confirmDeleteUser(userId, username) {
            if (confirm(`Are you sure you want to delete user "${username}" (ID: ${userId})? This action cannot be undone.`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '<?php echo BASE_URL; ?>/users.php';

                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'user_id';
                idInput.value = userId;
                form.appendChild(idInput);

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete_user';
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

        // Add User Form submission (client-side handling for messages)
        addUserForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(addUserForm);
            try {
                const response = await fetch('<?php echo BASE_URL; ?>/users.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.text(); // Get raw text to debug if JSON fails
                console.log(result); // Log raw response

                const jsonResult = JSON.parse(result); // Try parsing as JSON

                if (jsonResult.success) {
                    alert(jsonResult.message);
                    addUserForm.reset();
                    // Reload the page to see the new user in the table
                    window.location.reload();
                } else {
                    alert('Error: ' + (jsonResult.message || 'An unknown error occurred.'));
                }
            } catch (error) {
                console.error('Error adding user:', error);
                alert('An error occurred. Please try again. Check console for details.');
            }
        });


        // Edit User Form submission (client-side handling for messages)
        document.getElementById('editUserForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            try {
                const response = await fetch('<?php echo BASE_URL; ?>/users.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.success) {
                    alert(result.message);
                    closeEditUserModal();
                    window.location.reload(); // Reload the page to see updated details
                } else {
                    alert('Error: ' + (result.message || 'An unknown error occurred.'));
                }
            } catch (error) {
                console.error('Error updating user:', error);
                alert('An error occurred. Please try again.');
            }
        });

        window.onclick = function(event) {
            if (event.target == editUserModal) {
                closeEditUserModal();
            }
        }
    </script>
</body>
</html>