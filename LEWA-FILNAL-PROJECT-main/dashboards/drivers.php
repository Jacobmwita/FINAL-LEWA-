<?php
// C:\xampp\htdocs\lewa\drivers_view.php

// Ensure session is started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Enable error reporting for development
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include database connection
require_once __DIR__ . '/db_connect.php';

// Define BASE_URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
define('BASE_URL', "{$protocol}://{$host}/lewa");

// Authorization check: Ensure 'admin', 'administrator', or 'service_advisor' can access this page
if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['user_type'] ?? ''), ['admin', 'administrator', 'service_advisor'])) {
    // Added ?? '' to user_type in case it's not set
    header('Location: ' . BASE_URL . '/user_login.php?error=no_access');
    exit();
}

$message = '';
$message_type = '';

// Fetch only drivers
$drivers = [];
$sql = "SELECT user_id, username, email, full_name, phone, user_type FROM users WHERE user_type = 'driver' ORDER BY user_id DESC";
$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $drivers[] = $row;
    }
    $result->free(); // Free the result set
} else {
    $message = "Error fetching drivers: " . $conn->error;
    $message_type = 'error';
}

$conn->close();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lewa Workshop - View Drivers</title>
    <style>
        /* Re-use or link to your main CSS for consistency */
        :root { --primary: #3498db; --secondary: #2980b9; --success: #2ecc71; --danger: #e74c3c; --warning: #f39c12; --light: #ecf0f1; --dark: #2c3e50; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; padding: 0; background-color: #f5f5f5; }
        .dashboard-container { display: grid; grid-template-columns: 250px 1fr; min-height: 100vh; }
        .sidebar { background-color: var(--dark); color: white; padding: 20px; }
        .sidebar h2 { text-align: center; color: var(--primary); margin-bottom: 30px; }
        .sidebar nav ul { list-style: none; padding: 0; margin: 0; }
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
        .alert {
            padding: 10px 15px; border-radius: 4px; margin-bottom: 15px; font-weight: bold;
        }
        .alert-success { background-color: rgba(46, 204, 113, 0.2); color: #2ecc71; }
        .alert-error { background-color: rgba(231, 76, 60, 0.2); color: #e74c3c; }
        /* Hide actions for view-only page */
        .action-buttons { display: none; }
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
                    <li><a href="<?php echo BASE_URL; ?>/drivers_view.php" class="active"><i class="fas fa-users"></i> View Drivers</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/inventory.php"><i class="fas fa-warehouse"></i> Monitor Inventory</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/vehicles.php"><i class="fas fa-car-alt"></i> Vehicles</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </nav>
        </div>

        <div class="main-content">
            <h1>View Drivers</h1>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo htmlspecialchars($message_type); ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div class="action-card">
                <h3>Driver List</h3>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Role</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($drivers)): ?>
                            <?php foreach ($drivers as $driver): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$driver['user_id']); ?></td>
                                <td><?php echo htmlspecialchars((string)$driver['username']); ?></td>
                                <td><?php echo htmlspecialchars($driver['full_name'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($driver['email'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($driver['phone'] ?? ''); ?></td>
                                <td><?php echo ucwords(str_replace('_', ' ', (string)$driver['user_type'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6">No drivers found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</body>
</html>