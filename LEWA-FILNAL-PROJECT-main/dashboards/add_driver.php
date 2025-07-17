<?php
// C:\xampp\htdocs\lewa\dashboards\add_driver.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
define('BASE_URL', "{$protocol}://{$host}/lewa");

// Authorize only workshop managers to access this page
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'workshop_manager') {
    header('Location: ' . BASE_URL . '/user_login.php');
    exit();
}

include '../db_connect.php';

$success_message = '';
$error_message = '';

// Define the available user types for assignment to a new driver.
// These should correspond to the keys in the redirectToDashboard map
// that are appropriate for a "driver" or related operational roles.
// You can customize this array to include other roles a driver might legitimately access.
$available_user_types = [
    'driver' => 'Driver Portal',
    'service' => 'Service Dashboard',
    'technician' => 'Technician Dashboard'
    // Add more specific driver-related roles or other relevant dashboards if needed,
    // ensuring they are also defined in the redirectToDashboard function in user_login.php
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $conn->real_escape_string($_POST['username']);
    $email = $conn->real_escape_string($_POST['email']);
    $password = $_POST['password']; // Raw password
    $confirm_password = $_POST['confirm_password'];
    $assigned_user_type = $conn->real_escape_string($_POST['assigned_user_type']); // New input field for dashboard access

    // Basic server-side validation
    if (empty($username) || empty($email) || empty($password) || empty($confirm_password) || empty($assigned_user_type)) {
        $error_message = "All fields are required.";
    } elseif ($password !== $confirm_password) {
        $error_message = "Passwords do not match.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Invalid email format.";
    } elseif (!array_key_exists($assigned_user_type, $available_user_types)) {
        // Validate the assigned_user_type against the allowed list
        $error_message = "Invalid dashboard access type selected.";
    } else {
        // Hash the password before storing
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // Check if username or email already exists
        $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
        $check_stmt->bind_param("ss", $username, $email);
        $check_stmt->execute();
        $check_stmt->store_result();

        if ($check_stmt->num_rows > 0) {
            $error_message = "Username or Email already registered.";
        } else {
            // Use the assigned_user_type from the form for the new user's user_type
            $stmt = $conn->prepare("INSERT INTO users (username, email, password, user_type) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $username, $email, $hashed_password, $assigned_user_type);

            if ($stmt->execute()) {
                $success_message = "Driver account created successfully with dashboard access: " . htmlspecialchars($available_user_types[$assigned_user_type]) . "!";
                // Clear form fields after successful submission
                $_POST = []; // This clears the POST array to reset the form
            } else {
                $error_message = "Error creating driver: " . $stmt->error;
            }
            $stmt->close();
        }
        $check_stmt->close();
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Driver - Lewa Workshop</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #eef2f6; margin: 0; padding: 20px; color: #333; }
        .container { max-width: 600px; margin: 20px auto; background-color: #ffffff; padding: 30px; border-radius: 10px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        h1 { color: #2c3e50; margin-bottom: 30px; text-align: center; }
        .alert { padding: 15px; margin-bottom: 20px; border-radius: 5px; }
        .alert-success { background-color: #d4edda; color: #155724; }
        .alert-danger { background-color: #f8d7da; color: #721c24; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; }
        input[type="text"], input[type="email"], input[type="password"], select { /* Added select to styling */
            width: calc(100% - 22px); /* Account for padding and border */
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
        }
        .btn { padding: 10px 20px; background-color: #28a745; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; width: 100%; }
        .btn:hover { background-color: #218838; }
        .back-link { display: inline-block; margin-bottom: 20px; color: #007bff; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <a href="<?php echo BASE_URL; ?>/dashboards/admin_dashboard.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
        <h1>Add New Driver</h1>
        
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success"><?php echo $success_message; ?></div>
        <?php endif; ?>
        
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger"><?php echo $error_message; ?></div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
            </div>
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>
            <div class="form-group">
                <label for="assigned_user_type">Dashboard Access</label>
                <select id="assigned_user_type" name="assigned_user_type" required>
                    <?php foreach ($available_user_types as $value => $label): ?>
                        <option value="<?php echo htmlspecialchars($value); ?>" 
                            <?php echo (($_POST['assigned_user_type'] ?? 'driver') === $value) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn">
                <i class="fas fa-user-plus"></i> Add Driver
            </button>
        </form>
    </div>
</body>
</html>