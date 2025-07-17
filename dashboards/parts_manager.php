<?php
// Ensure session is started before any output
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Define BASE_URL early for consistent pathing
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
// Adjust this BASE_URL to match your actual application's root directory
// For example, if your app is at http://localhost/my_app/, define('BASE_URL', "{$protocol}://{$host}/my_app");
define('BASE_URL', "{$protocol}://{$host}/lewa");

require_once '../auth_check.php';
require_once '../config.php';
require_once '../functions.php'; // Assuming this file contains hasPermission() and formatDate()

// Include database connection. Using require_once for critical dependencies.
require_once '../db_connect.php'; 

// Check if the user has 'inventory' permission.
// The hasPermission function should be defined in functions.php
if (!hasPermission('inventory')) { // Assuming 'inventory' is the correct permission
    header("Location: " . BASE_URL . "/dashboard.php");
    exit();
}

// Ensure $pdo is an active PDO connection object from db_connect.php
if (!isset($pdo) || !$pdo instanceof PDO) {
    // It's better to log this error and redirect or show a generic message
    error_log("Database connection (PDO) not available in inventory.php.");
    die("A critical error occurred. Please try again later.");
}

// Placeholder for formatDate if not already in functions.php
// You should ensure this function exists in your functions.php file.
if (!function_exists('formatDate')) {
    function formatDate($dateString) {
        // Fallback for basic date formatting if function.php doesn't have it
        return date('Y-m-d H:i:s', strtotime($dateString));
    }
}

// Generate a new CSRF token if one doesn't exist
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$message_type = '';

// Handle AJAX requests
// Check for X-Requested-With header to confirm it's an AJAX request
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    // Validate CSRF token for all POST AJAX requests
    // For GET requests, CSRF token is typically not strictly necessary but can be added for extra security
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit();
    }

    try {
        // Handle POST actions (add, update, restock, update_request_status)
        if (isset($_POST['action'])) {
            if ($_POST['action'] === 'add_part') {
                $name = trim($_POST['name']);
                $description = trim($_POST['description']);
                $quantity = (int)$_POST['quantity'];
                $price = (float)$_POST['price'];

                if (empty($name) || $quantity < 0 || $price < 0) {
                    throw new Exception("All fields are required and quantities/prices must be non-negative.");
                }

                $stmt = $pdo->prepare("INSERT INTO inventory (name, description, quantity, price) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $description, $quantity, $price]);
                echo json_encode(['success' => true, 'message' => 'Part added successfully.']);
                exit();
            } elseif ($_POST['action'] === 'update_part') {
                $id = (int)$_POST['id'];
                $name = trim($_POST['name']);
                $description = trim($_POST['description']);
                $price = (float)$_POST['price'];

                if (empty($name) || $price < 0) {
                    throw new Exception("Part name and price are required and price must be non-negative.");
                }

                $stmt = $pdo->prepare("UPDATE inventory SET name = ?, description = ?, price = ? WHERE id = ?");
                $stmt->execute([$name, $description, $price, $id]);
                echo json_encode(['success' => true, 'message' => 'Part updated successfully.']);
                exit();
            } elseif ($_POST['action'] === 'restock_part') {
                $id = (int)$_POST['id'];
                $quantity_to_add = (int)$_POST['quantity'];

                if ($quantity_to_add <= 0) {
                    throw new Exception("Quantity to add must be positive.");
                }

                $stmt = $pdo->prepare("UPDATE inventory SET quantity = quantity + ? WHERE id = ?");
                $stmt->execute([$quantity_to_add, $id]);
                echo json_encode(['success' => true, 'message' => 'Part restocked successfully.']);
                exit();
            } elseif ($_POST['action'] === 'update_request_status') {
                // For update_request_status, assuming JSON payload from client-side fetch
                $data = json_decode(file_get_contents('php://input'), true);
                $requestId = (int)$data['requestId'];
                $status = trim($data['status']); // 'approved' or 'rejected'

                // Re-validate CSRF token since it's a JSON payload, not form data
                if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
                    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
                    exit();
                }

                if (!in_array($status, ['approved', 'rejected'])) {
                    throw new Exception("Invalid status provided.");
                }

                // First, fetch request details to get part_id and quantity
                $stmt = $pdo->prepare("SELECT part_id, quantity FROM part_requests WHERE id = ?");
                $stmt->execute([$requestId]);
                $request = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$request) {
                    throw new Exception("Part request not found.");
                }

                $pdo->beginTransaction(); // Start transaction for atomic operation

                try {
                    // Update request status
                    $stmt = $pdo->prepare("UPDATE part_requests SET status = ?, processed_by = ?, processed_at = NOW() WHERE id = ?");
                    $stmt->execute([$status, $_SESSION['user_id'], $requestId]);

                    // If approved, decrement inventory quantity
                    if ($status === 'approved') {
                        $part_id = $request['part_id'];
                        $quantity_requested = $request['quantity'];

                        // Check current stock before decrementing
                        $stmt = $pdo->prepare("SELECT quantity FROM inventory WHERE id = ? FOR UPDATE"); // Add FOR UPDATE to lock row
                        $stmt->execute([$part_id]);
                        $current_stock = $stmt->fetchColumn();

                        if ($current_stock < $quantity_requested) {
                            $pdo->rollBack(); // Rollback if insufficient stock
                            echo json_encode(['success' => false, 'message' => 'Insufficient stock to approve this request.']);
                            exit();
                        }

                        $stmt = $pdo->prepare("UPDATE inventory SET quantity = quantity - ? WHERE id = ?");
                        $stmt->execute([$quantity_requested, $part_id]);
                    }

                    $pdo->commit(); // Commit transaction
                    echo json_encode(['success' => true, 'message' => 'Request status updated successfully.']);
                    exit();
                } catch (Exception $e) {
                    $pdo->rollBack(); // Rollback on any exception during transaction
                    throw $e; // Re-throw to be caught by the outer catch block
                }
            }
        } elseif (isset($_GET['action'])) {
            // Handle GET actions (get_part_details)
            if ($_GET['action'] === 'get_part_details' && isset($_GET['id'])) {
                $partId = (int)$_GET['id'];
                $stmt = $pdo->prepare("SELECT id, name, description, quantity, price FROM inventory WHERE id = ?");
                $stmt->execute([$partId]);
                $part = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode($part);
                exit();
            }
        }
    } catch (Exception $e) {
        // General error handling for AJAX requests
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit();
    }
}


// Fetch all inventory items for initial display
try {
    $parts = $pdo->query("SELECT * FROM inventory ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

    // Get pending parts requests
    $requests = $pdo->query("SELECT pr.*, j.customer_name, j.vehicle_model, p.name as part_name, u.full_name as requested_by_name
                             FROM part_requests pr
                             JOIN job_cards j ON pr.job_card_id = j.id
                             JOIN inventory p ON pr.part_id = p.id
                             JOIN users u ON pr.requested_by = u.id
                             WHERE pr.status = 'pending'
                             ORDER BY pr.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Log the error and display a user-friendly message
    error_log("Database error in inventory.php: " . $e->getMessage());
    $message = "An error occurred while fetching data. Please try again later.";
    $message_type = 'error';
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Dashboard</title>
    <link rel="stylesheet" href="../../css/style.css">
    <script src="https://kit.fontawesome.com/a076d05399.js" crossorigin="anonymous"></script>
    <style>
        /* General Styles - You should ideally move these to your style.css */
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; padding: 0; background-color: #f5f5f5; }
        .container { max-width: 1200px; margin: 20px auto; padding: 20px; background-color: #fff; border-radius: 8px; box-shadow: 0 0 10px rgba(0, 0, 0, 0.1); }
        h1, h2 { color: #333; }
        .tabs { display: flex; margin-bottom: 20px; }
        .tab-btn { background-color: #f0f0f0; border: 1px solid #ddd; padding: 10px 15px; cursor: pointer; transition: background-color 0.3s ease; }
        .tab-btn:hover { background-color: #e0e0e0; }
        .tab-btn.active { background-color: #007bff; color: white; border-color: #007bff; }
        .tab-content { display: none; padding: 20px 0; border-top: 1px solid #eee; }
        .tab-content:first-of-type { border-top: none; } /* No top border for the first tab content */
        .actions { margin-bottom: 20px; }
        .btn { background-color: #28a745; color: white; padding: 10px 15px; border: none; border-radius: 5px; cursor: pointer; transition: background-color 0.3s ease; text-decoration: none; display: inline-block; }
        .btn:hover { background-color: #218838; }
        .btn.small { padding: 5px 10px; font-size: 0.85em; }
        .btn.danger { background-color: #dc3545; }
        .btn.danger:hover { background-color: #c82333; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #f8f9fa; }
        tr:nth-child(even) { background-color: #f2f2f2; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4); }
        .modal-content { background-color: #fefefe; margin: 10% auto; padding: 20px; border: 1px solid #888; width: 80%; max-width: 500px; border-radius: 8px; position: relative; }
        .close { color: #aaa; float: right; font-size: 28px; font-weight: bold; }
        .close:hover, .close:focus { color: black; text-decoration: none; cursor: pointer; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group textarea { width: calc(100% - 22px); padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        .alert { padding: 15px; margin-bottom: 20px; border-radius: 5px; }
        .alert-success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    
    <div class="container">
        <h1>Inventory Dashboard</h1>
        <?php if (isset($_SESSION['full_name'])): ?>
            <p>Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?> (Parts Manager)</p>
        <?php else: ?>
            <p>Welcome, Parts Manager</p>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <div class="tabs">
            <button class="tab-btn active" onclick="openTab(event, 'partsInventory')">Parts Inventory</button>
            <button class="tab-btn" onclick="openTab(event, 'partsRequests')">Parts Requests</button>
        </div>
        
        <div id="partsInventory" class="tab-content" style="display: block;">
            <div class="actions">
                <button onclick="openModal('addPart')" class="btn">Add New Part</button>
            </div>
            
            <h2>Parts Inventory</h2>
            <?php if (!empty($parts)): ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Quantity</th>
                        <th>Price</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($parts as $part): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($part['id']); ?></td>
                        <td><?php echo htmlspecialchars($part['name']); ?></td>
                        <td><?php echo htmlspecialchars($part['description']); ?></td>
                        <td><?php echo htmlspecialchars($part['quantity']); ?></td>
                        <td>$<?php echo htmlspecialchars(number_format($part['price'], 2)); ?></td>
                        <td>
                            <button onclick="openEditPartModal(<?php echo htmlspecialchars($part['id']); ?>)" class="btn small">Edit</button>
                            <button onclick="restockPart(<?php echo htmlspecialchars($part['id']); ?>)" class="btn small">Restock</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p>No parts found in inventory.</p>
            <?php endif; ?>
        </div>
        
        <div id="partsRequests" class="tab-content">
            <h2>Pending Parts Requests</h2>
            <?php if (!empty($requests)): ?>
            <table>
                <thead>
                    <tr>
                        <th>Request ID</th>
                        <th>Job Card</th>
                        <th>Customer</th>
                        <th>Vehicle</th>
                        <th>Part</th>
                        <th>Quantity</th>
                        <th>Requested By</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $request): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($request['id']); ?></td>
                        <td>JC-<?php echo htmlspecialchars($request['job_card_id']); ?></td>
                        <td><?php echo htmlspecialchars($request['customer_name']); ?></td>
                        <td><?php echo htmlspecialchars($request['vehicle_model']); ?></td>
                        <td><?php echo htmlspecialchars($request['part_name']); ?></td>
                        <td><?php echo htmlspecialchars($request['quantity']); ?></td>
                        <td><?php echo htmlspecialchars($request['requested_by_name']); ?></td>
                        <td><?php echo htmlspecialchars(formatDate($request['created_at'])); ?></td>
                        <td>
                            <button onclick="approveRequest(<?php echo htmlspecialchars($request['id']); ?>)" class="btn small">Approve</button>
                            <button onclick="rejectRequest(<?php echo htmlspecialchars($request['id']); ?>)" class="btn small danger">Reject</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p>No pending parts requests.</p>
            <?php endif; ?>
        </div>
    </div>
    
    <div id="addPart" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('addPart')">&times;</span>
            <h2>Add New Part</h2>
            <form id="addPartForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="add_part">
                <div class="form-group">
                    <label for="part_name">Part Name</label>
                    <input type="text" id="part_name" name="name" required>
                </div>
                <div class="form-group">
                    <label for="part_description">Description</label>
                    <textarea id="part_description" name="description"></textarea>
                </div>
                <div class="form-group">
                    <label for="part_quantity">Initial Quantity</label>
                    <input type="number" id="part_quantity" name="quantity" min="0" required>
                </div>
                <div class="form-group">
                    <label for="part_price">Price</label>
                    <input type="number" id="part_price" name="price" min="0" step="0.01" required>
                </div>
                <button type="submit" class="btn">Add Part</button>
            </form>
        </div>
    </div>
    
    <div id="editPart" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editPart')">&times;</span>
            <h2>Edit Part</h2>
            <form id="editPartForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="update_part">
                <input type="hidden" id="edit_part_id" name="id">
                <div class="form-group">
                    <label for="edit_part_name">Part Name</label>
                    <input type="text" id="edit_part_name" name="name" required>
                </div>
                <div class="form-group">
                    <label for="edit_part_description">Description</label>
                    <textarea id="edit_part_description" name="description"></textarea>
                </div>
                <div class="form-group">
                    <label for="edit_part_price">Price</label>
                    <input type="number" id="edit_part_price" name="price" min="0" step="0.01" required>
                </div>
                <button type="submit" class="btn">Save Changes</button>
            </form>
        </div>
    </div>
    
    <div id="restockPart" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('restockPart')">&times;</span>
            <h2>Restock Part</h2>
            <form id="restockPartForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="restock_part">
                <input type="hidden" id="restock_part_id" name="id">
                <div class="form-group">
                    <label for="restock_quantity">Quantity to Add</label>
                    <input type="number" id="restock_quantity" name="quantity" min="1" required>
                </div>
                <button type="submit" class="btn">Restock</button>
            </form>
        </div>
    </div>
    
    <?php include '../../includes/footer.php'; ?>
    <script>
    // Global modal functions
    function openModal(modalId) {
        document.getElementById(modalId).style.display = 'block';
    }

    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
    }

    // Tab functionality
    function openTab(evt, tabName) {
        const tabContents = document.getElementsByClassName('tab-content');
        for (let i = 0; i < tabContents.length; i++) {
            tabContents[i].style.display = 'none';
        }
        
        const tabButtons = document.getElementsByClassName('tab-btn');
        for (let i = 0; i < tabButtons.length; i++) {
            tabButtons[i].classList.remove('active');
        }
        
        document.getElementById(tabName).style.display = 'block';
        evt.currentTarget.classList.add('active');
    }
    
    // Function to display messages (similar to the Lewa Workshop's alert system)
    function displayMessage(message, type) {
        let alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type}`;
        alertDiv.textContent = message;

        // Find a suitable place to insert the alert, e.g., after the H1
        const container = document.querySelector('.container');
        const h1 = container.querySelector('h1');
        if (h1) {
            container.insertBefore(alertDiv, h1.nextSibling);
        } else {
            container.prepend(alertDiv);
        }

        // Automatically remove the alert after some time
        setTimeout(() => {
            alertDiv.remove();
        }, 5000); // Remove after 5 seconds
    }

    // Add Part Form Submission
    document.getElementById('addPartForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        
        fetch('inventory.php', { // Submitting to the same page for AJAX handling
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                // If the server response is not OK (e.g., 500 Internal Server Error)
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json(); // Parse JSON response
        })
        .then(data => {
            if (data.success) {
                displayMessage(data.message, 'success');
                closeModal('addPart');
                location.reload(); // Reload to reflect changes
            } else {
                displayMessage(data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error adding part:', error);
            displayMessage('An error occurred while adding the part.', 'error');
        });
    });

    // Open Edit Part Modal and Populate Data
    function openEditPartModal(partId) {
        fetch(`inventory.php?action=get_part_details&id=${partId}`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest' // Indicate AJAX request
            }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(part => {
            if (part) {
                document.getElementById('edit_part_id').value = part.id;
                document.getElementById('edit_part_name').value = part.name;
                document.getElementById('edit_part_description').value = part.description;
                document.getElementById('edit_part_price').value = part.price;
                openModal('editPart');
            } else {
                displayMessage('Part not found.', 'error');
            }
        })
        .catch(error => {
            console.error('Error fetching part details:', error);
            displayMessage('An error occurred while fetching part details.', 'error');
        });
    }
    
    // Edit Part Form Submission
    document.getElementById('editPartForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        
        fetch('inventory.php', { // Submitting to the same page for AJAX handling
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                displayMessage(data.message, 'success');
                closeModal('editPart');
                location.reload(); // Reload to reflect changes
            } else {
                displayMessage(data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error updating part:', error);
            displayMessage('An error occurred while updating the part.', 'error');
        });
    });
    
    // Restock Part Modal Display
    function restockPart(partId) {
        document.getElementById('restock_part_id').value = partId;
        openModal('restockPart');
    }
    
    // Restock Part Form Submission
    document.getElementById('restockPartForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        
        fetch('inventory.php', { // Submitting to the same page for AJAX handling
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                displayMessage(data.message, 'success');
                closeModal('restockPart');
                location.reload(); // Reload to reflect changes
            } else {
                displayMessage(data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error restock part:', error);
            displayMessage('An error occurred during restock.', 'error');
        });
    });
    
    // Parts request approval/rejection
    function approveRequest(requestId) {
        if (confirm('Are you sure you want to approve this request? This will deduct the quantity from inventory.')) {
            updateRequestStatus(requestId, 'approved');
        }
    }
    
    function rejectRequest(requestId) {
        if (confirm('Are you sure you want to reject this request?')) {
            updateRequestStatus(requestId, 'rejected');
        }
    }
    
    function updateRequestStatus(requestId, status) {
        fetch('inventory.php', { // Submitting to the same page for AJAX handling
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest' // Indicate AJAX request
            },
            body: JSON.stringify({ 
                action: 'update_request_status', 
                requestId: requestId, 
                status: status,
                csrf_token: '<?php echo $_SESSION['csrf_token']; ?>' // Include CSRF token
            })
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                displayMessage(data.message, 'success');
                location.reload(); // Reload to reflect changes
            } else {
                displayMessage(data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error updating request status:', error);
            displayMessage('An error occurred while updating the request status.', 'error');
        });
    }
    </script>
</body>
</html>