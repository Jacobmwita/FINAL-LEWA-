<?php
if (session_status() == PHP_SESSION_NONE) { session_start(); }
include 'db_connect.php';

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
define('BASE_URL', "{$protocol}://{$host}/lewa");

if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['user_type']), ['workshop_manager', 'admin', 'administrator', 'manager'])) {
    header('Location: ' . BASE_URL . '/user_login.php?error=no_access');
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$message_type = '';


$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $response = ['success' => false, 'message' => 'Invalid CSRF token.'];
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode($response);
            exit();
        } else {
            $message = $response['message'];
            $message_type = 'error';
        }
    } else {
        try {
            if (isset($_POST['action'])) {
                if ($_POST['action'] === 'add_item') {
                    $item_name = trim($_POST['item_name']);
                    $quantity = (int)$_POST['quantity'];
                    $unit = trim($_POST['unit']);
                    $min_stock_level = (int)$_POST['min_stock_level'];
                    $price = floatval($_POST['price']); // New: Get price

                    if (empty($item_name) || $quantity < 0 || empty($unit) || $min_stock_level < 0 || $price < 0) {
                        throw new Exception("All fields are required and quantities/price must be non-negative.");
                    }

                    // Check for duplicate item name before inserting
                    $check_stmt = $conn->prepare("SELECT COUNT(*) FROM inventory WHERE item_name = ?");
                    $check_stmt->bind_param("s", $item_name);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result()->fetch_row();
                    if ($check_result[0] > 0) {
                        throw new Exception("An item with the name '{$item_name}' already exists. Please use a unique name or edit the existing item.");
                    }
                    $check_stmt->close();

                    // New: Include price in the INSERT statement
                    $stmt = $conn->prepare("INSERT INTO inventory (item_name, quantity, unit, min_stock_level, price) VALUES (?, ?, ?, ?, ?)");
                    $stmt->bind_param("sisid", $item_name, $quantity, $unit, $min_stock_level, $price); // 'd' for double (float)
                    if ($stmt->execute()) {
                        $response = ['success' => true, 'message' => "Inventory item '{$item_name}' added successfully."];
                    } else {
                        throw new Exception("Error adding item: " . $stmt->error);
                    }
                    $stmt->close();
                } elseif ($_POST['action'] === 'update_item') {
                    $item_id = (int)$_POST['item_id'];
                    $item_name = trim($_POST['item_name']);
                    $quantity = (int)$_POST['quantity'];
                    $unit = trim($_POST['unit']);
                    $min_stock_level = (int)$_POST['min_stock_level'];
                    $price = floatval($_POST['price']); // New: Get price

                    if (empty($item_name) || $quantity < 0 || empty($unit) || $min_stock_level < 0 || $price < 0) {
                        throw new Exception("All fields are required and quantities/price must be non-negative.");
                    }

                    // Check for duplicate item name during update, excluding the current item being updated
                    $check_stmt = $conn->prepare("SELECT COUNT(*) FROM inventory WHERE item_name = ? AND item_id != ?");
                    $check_stmt->bind_param("si", $item_name, $item_id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result()->fetch_row();
                    if ($check_result[0] > 0) {
                        throw new Exception("An item with the name '{$item_name}' already exists. Please use a unique name.");
                    }
                    $check_stmt->close();

                    // New: Include price in the UPDATE statement
                    $stmt = $conn->prepare("UPDATE inventory SET item_name = ?, quantity = ?, unit = ?, min_stock_level = ?, price = ?, last_updated = NOW() WHERE item_id = ?");
                    $stmt->bind_param("sisidi", $item_name, $quantity, $unit, $min_stock_level, $price, $item_id); // 'd' for double (float)
                    if ($stmt->execute()) {
                        $response = ['success' => true, 'message' => "Inventory item ID {$item_id} updated successfully."];
                    } else {
                        throw new Exception("Error updating item: " . $stmt->error);
                    }
                    $stmt->close();
                } elseif ($_POST['action'] === 'delete_item') {
                    $item_id = (int)$_POST['item_id'];
                    $stmt = $conn->prepare("DELETE FROM inventory WHERE item_id = ?");
                    $stmt->bind_param("i", $item_id);
                    if ($stmt->execute()) {
                        $response = ['success' => true, 'message' => "Inventory item ID {$item_id} deleted successfully."];
                    } else {
                        throw new Exception("Error deleting item: " . $stmt->error);
                    }
                    $stmt->close();
                }
            }
        } catch (Exception $e) {
            $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }

        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode($response);
            exit();
        } else {
            $message = $response['message'];
            $message_type = $response['success'] ? 'success' : 'error';
        }
    }
}


$inventory_items = [];
// New: Select the 'price' column
$sql = "SELECT item_id, item_name, quantity, unit, min_stock_level, price, last_updated FROM inventory ORDER BY item_name ASC";
$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $inventory_items[] = $row;
    }
} else {
    if (!$is_ajax) {
        $message = "Error fetching inventory: " . $conn->error;
        $message_type = 'error';
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lewa Workshop - Monitor Inventory</title>
    <style>
        :root {
             --primary: #3498db; --secondary: #2980b9; --success: #2ecc71; --danger: #e74c3c; --warning: #f39c12; --light: #ecf0f1; --dark: #2c3e50;
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
        .sidebar nav ul li { margin-bottom: 10px;
        }
        .sidebar nav ul li a {
            color: white; text-decoration: none; display: block; padding: 10px 15px; border-radius: 5px; transition: background-color 0.3s ease;
         }
        .sidebar nav ul li a:hover, .sidebar nav ul li a.active { background-color: var(--secondary);
        }
        .main-content {
            padding: 20px; }
        h1 { color: var(--dark); margin-bottom: 30px;
        }
        .action-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        table {
            width: 100%; border-collapse: collapse; margin-top: 20px;
        }
        th, td {
             padding: 12px 15px; text-align: left; border-bottom: 1px solid #ddd;
             }
        th {
             background-color: var(--light); color: var(--dark); font-weight: 600;
        }
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

        .modal {
            display: none; position: fixed; z-index: 1; left: 0; top: 0; width: 100%; height: 100%; overflow: auto;
            background-color: rgba(0,0,0,0.4); padding-top: 60px;
        }
        .modal-content {
            background-color: #fefefe; margin: 5% auto; padding: 20px; border: 1px solid #888; width: 80%;
            max-width: 600px; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        .close-button {
             color: #aaa; float: right; font-size: 28px; font-weight: bold;
             }
        .close-button:hover, .close-button:focus {
             color: black; text-decoration: none; cursor: pointer;
        }
        .form-group {
             margin-bottom: 15px;
            }
        .form-group label {
            display: block; margin-bottom: 5px; font-weight: bold;
        }
        .form-group input, .form-group select {
            width: calc(100% - 22px); padding: 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box;
        }
        .modal-buttons { text-align: right; margin-top: 20px; }
        .modal-buttons button { margin-left: 10px; }
        .alert {
            padding: 10px 15px; border-radius: 4px; margin-bottom: 15px; font-weight: bold;
        }
        .alert-success {
            background-color: rgba(46, 204, 113, 0.2); color: #2ecc71;
         }
        .alert-error {
             background-color: rgba(231, 76, 60, 0.2); color: #e74c3c;
             }
        .low-stock {
             color: var(--danger); font-weight: bold;
         }
        .below-min-stock {
            background-color: rgba(231, 76, 60, 0.1);
         }
    </style>
     <script src="https://kit.fontawesome.com/a076d05399.js" crossorigin="anonymous"></script>
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
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/manage_users.php"><i class="fas fa-users-cog"></i> Manage User Roles</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/inventory.php" class="active"><i class="fas fa-warehouse"></i> Monitor Inventory</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/add_vehicle.php"><i class="fas fa-car-alt"></i> Vehicles</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </nav>
        </div>

        <div class="main-content">
            <h1>Monitor Inventory</h1>

            <?php if ($message && !$is_ajax):?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div class="action-card">
                <h3>Add New Inventory Item</h3>
                <form id="addItemForm" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="add_item">

                    <div class="form-group">
                        <label for="item_name">Item Name</label>
                        <input type="text" id="item_name" name="item_name" required>
                    </div>
                    <div class="form-group">
                        <label for="quantity">Quantity</label>
                        <input type="number" id="quantity" name="quantity" min="0" value="0" required>
                    </div>
                    <div class="form-group">
                        <label for="unit">Unit (e.g., pcs, liters, kg)</label>
                        <input type="text" id="unit" name="unit" required>
                    </div>
                    <div class="form-group">
                        <label for="min_stock_level">Minimum Stock Level</label>
                        <input type="number" id="min_stock_level" name="min_stock_level" min="0" value="0" required>
                    </div>
                    <div class="form-group">
                        <label for="price">Price (KES)</label>
                        <input type="number" id="price" name="price" min="0" step="0.01" value="0.00" required>
                    </div>
                    <button type="submit"><i class="fas fa-plus-circle"></i> Add Item</button>
                </form>
            </div>

            <div class="action-card">
                <h3>Current Inventory</h3>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Item Name</th>
                            <th>Quantity</th>
                            <th>Unit</th>
                            <th>Min Stock Level</th>
                            <th>Price</th>
                            <th>Last Updated</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($inventory_items)): ?>
                            <?php foreach ($inventory_items as $item): ?>
                            <tr class="<?php echo ($item['quantity'] < $item['min_stock_level']) ? 'below-min-stock' : ''; ?>">
                                <td><?php echo htmlspecialchars($item['item_id']); ?></td>
                                <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                <td><?php echo htmlspecialchars($item['quantity']); ?></td>
                                <td><?php echo htmlspecialchars($item['unit']); ?></td>
                                <td><?php echo htmlspecialchars($item['min_stock_level']); ?></td>
                                <td><?php echo htmlspecialchars(number_format($item['price'], 2)); ?></td> <!-- New: Display price -->
                                <td><?php echo date('M d, Y H:i A', strtotime($item['last_updated'])); ?></td>
                                <td class="<?php echo ($item['quantity'] < $item['min_stock_level']) ? 'low-stock' : ''; ?>">
                                    <?php echo ($item['quantity'] < $item['min_stock_level']) ? 'Low Stock' : 'In Stock'; ?>
                                </td>
                                <td class="action-buttons">
                                    <!-- Changed "Edit" to "Manage" -->
                                    <a href="#" onclick="openEditItemModal(<?php echo htmlspecialchars(json_encode($item)); ?>)" title="Manage Item"><i class="fas fa-cog"></i> Manage</a>
                                    <button type="button" class="delete" onclick="confirmDeleteItem(<?php echo $item['item_id']; ?>, '<?php echo htmlspecialchars($item['item_name']); ?>')" title="Delete Item"><i class="fas fa-trash-alt"></i></button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="9">No inventory items found.</td></tr> <!-- Updated colspan -->
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="editItemModal" class="modal">
        <div class="modal-content">
            <span class="close-button" onclick="closeEditItemModal()">&times;</span>
            <h2>Edit Inventory Item</h2>
            <form id="editItemForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="update_item">
                <input type="hidden" id="edit-item-id" name="item_id">

                <div class="form-group">
                    <label for="edit-item-name">Item Name</label>
                    <input type="text" id="edit-item-name" name="item_name" required>
                </div>
                <div class="form-group">
                    <label for="edit-quantity">Quantity</label>
                    <input type="number" id="edit-quantity" name="quantity" min="0" required>
                </div>
                <div class="form-group">
                    <label for="edit-unit">Unit</label>
                    <input type="text" id="edit-unit" name="unit" required>
                </div>
                <div class="form-group">
                    <label for="edit-min-stock-level">Minimum Stock Level</label>
                    <input type="number" id="edit-min-stock-level" name="min_stock_level" min="0" required>
                </div>
                <div class="form-group">
                    <label for="edit-price">Price (KES)</label>
                    <input type="number" id="edit-price" name="price" min="0" step="0.01" required>
                </div>
                <div class="modal-buttons">
                    <button type="button" onclick="closeEditItemModal()">Cancel</button>
                    <button type="submit">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const editItemModal = document.getElementById('editItemModal');
        const addItemForm = document.getElementById('addItemForm');

        function openEditItemModal(itemData) {
            document.getElementById('edit-item-id').value = itemData.item_id;
            document.getElementById('edit-item-name').value = itemData.item_name;
            document.getElementById('edit-quantity').value = itemData.quantity;
            document.getElementById('edit-unit').value = itemData.unit;
            document.getElementById('edit-min-stock-level').value = itemData.min_stock_level;
            document.getElementById('edit-price').value = itemData.price; // New: Set price
            editItemModal.style.display = 'block';
        }

        function closeEditItemModal() {
            editItemModal.style.display = 'none';
        }

        function confirmDeleteItem(itemId, itemName) {
            if (confirm(`Are you sure you want to delete "${itemName}" (ID: ${itemId}) from inventory? This action cannot be undone.`)) {
                const form = document.createElement('form');
                form.method = 'POST';

                form.action = '<?php echo BASE_URL; ?>/dashboards/inventory.php';

                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'item_id';
                idInput.value = itemId;
                form.appendChild(idInput);

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete_item';
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

        addItemForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(addItemForm);


            const headers = new Headers();
            headers.append('X-Requested-With', 'XMLHttpRequest');

            try {
                const response = await fetch('<?php echo BASE_URL; ?>/dashboards/inventory.php', {
                    method: 'POST',
                    body: formData,
                    headers: headers
                });
                const result = await response.json();
                if (result.success) {
                    alert(result.message);
                    addItemForm.reset();
                    window.location.reload();
                } else {
                    alert('Error: ' + (result.message || 'An unknown error occurred.'));
                }
            } catch (error) {
                console.error('Error adding item:', error);
                alert('An error occurred. Please try again. Details: ' + error.message);
            }
        });

        document.getElementById('editItemForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);

            const headers = new Headers();
            headers.append('X-Requested-With', 'XMLHttpRequest');

            try {
                const response = await fetch('<?php echo BASE_URL; ?>/dashboards/inventory.php', {
                    method: 'POST',
                    body: formData,
                    headers: headers
                });
                const result = await response.json();
                if (result.success) {
                    alert(result.message);
                    closeEditItemModal();
                    window.location.reload();
                } else {
                    alert('Error: ' + (result.message || 'An unknown error occurred.'));
                }
            } catch (error) {
                console.error('Error updating item:', error);
                alert('An error occurred. Please try again. Details: ' + error.message);
            }
        });

        function confirmDeleteItem(itemId, itemName) {
            if (confirm(`Are you sure you want to delete "${itemName}" (ID: ${itemId}) from inventory? This action cannot be undone.`)) {
                const formData = new FormData();
                formData.append('item_id', itemId);
                formData.append('action', 'delete_item');
                formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

                const headers = new Headers();
                headers.append('X-Requested-With', 'XMLHttpRequest');

                fetch('<?php echo BASE_URL; ?>/dashboards/inventory.php', {
                    method: 'POST',
                    body: formData,
                    headers: headers
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        alert(result.message);
                        window.location.reload();
                    } else {
                        alert('Error: ' + (result.message || 'An unknown error occurred.'));
                    }
                })
                .catch(error => {
                    console.error('Error deleting item:', error);
                    alert('An error occurred during deletion. Please try again. Details: ' + error.message);
                });
            }
        }

        window.onclick = function(event) {
            if (event.target == editItemModal) {
                closeEditItemModal();
            }
        }
    </script>
</body>
</html>
