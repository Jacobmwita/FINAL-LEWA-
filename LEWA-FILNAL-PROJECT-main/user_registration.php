<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start([
    'cookie_lifetime' => 86400,
    'cookie_secure' => isset($_SERVER['HTTPS']),
    'cookie_httponly' => true,
    'use_strict_mode' => true
]);

//CHEECK CSRF  REGISTRATION  TOKENS TOKENS
if (empty($_SESSION['csrf_token_register'])) {
    $_SESSION['csrf_token_register'] = bin2hex(random_bytes(32));
}

//SET   SECURITY
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

//BASE URL DIFINATION...THAT  WILL BE  USED IN LOCALHOST SEARCHING  IN  THE  SEARCH ENGINE
$base_url = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']);
define('BASE_URL', rtrim($base_url, '/'));


include 'db_connect.php';

// INITIALIZE  VARIBLES
$error = '';
$success = '';
$full_name = $email = $username = $user_type = '';
$phone_number = $id_number = $department = ''; 


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 //CSRF PROTECTION IN  TO THE   SYSTEM
    if (!isset($_POST['csrf_token_register']) || !hash_equals($_SESSION['csrf_token_register'], $_POST['csrf_token_register'])) {
        $error = 'Invalid request. Please try again.';
        $_SESSION['csrf_token_register'] = bin2hex(random_bytes(32));
    } else {
    //VALIDATION PROCESS
        $full_name = trim(filter_input(INPUT_POST, 'full_name', FILTER_SANITIZE_STRING));
        $email = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL));
        $username = trim(filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING));
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        $user_type = filter_input(INPUT_POST, 'user_type', FILTER_SANITIZE_STRING);
        $phone_number = trim(filter_input(INPUT_POST, 'phone_number', FILTER_SANITIZE_NUMBER_INT));
        $id_number = trim(filter_input(INPUT_POST, 'id_number', FILTER_SANITIZE_STRING));
        $department = filter_input(INPUT_POST, 'department', FILTER_SANITIZE_STRING);


        if (empty($full_name) || empty($email) || empty($username) || empty($password) || empty($user_type) || empty($phone_number) || empty($id_number) || empty($department)) {
            $error = 'All fields are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email format.';
        } elseif ($password !== $confirm_password) {
            $error = 'Passwords do not match.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters long.';
        } elseif (!in_array($user_type, ['workshop_manager', 'service_advisor', 'driver', 'mechanic', 'parts_manager', 'supervisor'])) {
            $error = 'Invalid user type selected.';
        } else {
            try {
                // SEE IF  THE USER  IS EXISTING  IN  THE  DATABASE
                $check_query = "SELECT user_id FROM users WHERE username = ? OR email = ? OR id_number = ?"; 
                $stmt_check = $conn->prepare($check_query);
                
                if ($stmt_check === false) {
                    throw new Exception("Database prepare error: " . $conn->error);
                }
                
                $stmt_check->bind_param("sss", $username, $email, $id_number); 
                $stmt_check->execute();
                $stmt_check->store_result();

                if ($stmt_check->num_rows > 0) {
                    $error = 'Username, email, or ID number already exists.'; 
                } else {
                    
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    
                
                    $permissions = [
                        'can_view_all_job_cards' => 0,
                        'can_create_job_cards' => 0,
                        'can_edit_job_cards' => 0,
                        'can_request_services' => 0,
                        'can_update_job_status' => 0,
                        'can_request_parts' => 0,
                        'can_manage_inventory' => 0,
                        'can_generate_invoices' => 0,
                        'can_view_reports' => 0,
                        'can_manage_users' => 0,
                        'reset_token' => NULL,
                        'reset_token_expiry' => NULL
                    ];

                    // PERMISSIONS
                    $dashboard_access = '';
                    
                    switch ($user_type) {
                        case 'workshop_manager':
                            $permissions['can_view_all_job_cards'] = 1;
                            $permissions['can_edit_job_cards'] = 1;
                            $permissions['can_view_reports'] = 1;
                            $permissions['can_manage_users'] = 1;
                            $permissions['can_manage_drivers'] = 1;
                            $dashboard_access = 'admin_dashboard';
                            break;
                        case 'service_advisor':
                            $permissions['can_create_job_cards'] = 1;
                            $permissions['can_edit_job_cards'] = 1;
                            $permissions['can_view_all_job_cards'] = 1;
                            $dashboard_access = 'service_dashboard';
                            break;
                        case 'driver':
                            $permissions['can_request_services'] = 1;
                            $dashboard_access = 'driver_portal';
                            break;
                        case 'mechanic':
                            $permissions['can_update_job_status'] = 1;
                            $permissions['can_request_parts'] = 1;
                            $dashboard_access = 'technician_dashboard';
                            break;
                        case 'parts_manager':
                            $permissions['can_manage_inventory'] = 1;
                            $permissions['can_request_parts'] = 1;
                            $dashboard_access = 'inventory_dashboard';
                            break;
                        case 'supervisor':
                            $permissions['can_generate_invoices'] = 1;
                            $permissions['can_view_all_job_cards'] = 1;
                            $dashboard_access = 'finance_dashboard';
                            break;
                    }

                    //INSERTING   THE  USER IN TO  THE  DATABASE
                    $insert_query = "INSERT INTO users (
                        username, password, full_name, email, user_type, phone_number, id_number, department,
                        can_view_all_job_cards, can_create_job_cards, can_edit_job_cards,
                        can_request_services, can_update_job_status, can_request_parts,
                        can_manage_inventory, can_generate_invoices, can_view_reports,
                        can_manage_users, dashboard_access, reset_token, reset_token_expiry
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"; 
                    
                    $stmt_insert = $conn->prepare($insert_query);
                    
                    if ($stmt_insert === false) {
                        throw new Exception("Insert prepare error: " . $conn->error);
                    }
                    
                    $stmt_insert->bind_param(
                        "ssssssssiiiiiiiiiisss", 
                        $username,
                        $hashed_password,
                        $full_name,
                        $email,
                        $user_type,
                        $phone_number, 
                        $id_number,    
                        $department,   
                        $permissions['can_view_all_job_cards'],
                        $permissions['can_create_job_cards'],
                        $permissions['can_edit_job_cards'],
                        $permissions['can_request_services'],
                        $permissions['can_update_job_status'],
                        $permissions['can_request_parts'],
                        $permissions['can_manage_inventory'],
                        $permissions['can_generate_invoices'],
                        $permissions['can_view_reports'],
                        $permissions['can_manage_users'],
                        $dashboard_access,
                        $permissions['reset_token'],
                        $permissions['reset_token_expiry']
                    );

                    if ($stmt_insert->execute()) {
                        header("Location: user_login.php?registration=success");
                        exit();
                    } else {
                        throw new Exception("Insert execution error: " . $stmt_insert->error);
                    }
                }
            } catch (Exception $e) {
                error_log("Registration error: " . $e->getMessage());
                $error = "An error occurred during registration. Please try again.";
            } finally {
                if (isset($stmt_check)) $stmt_check->close();
                if (isset($stmt_insert)) $stmt_insert->close();
            }
        }
    }
}

// IF  THE  USER IS  ALREADY REGISTERED ....YOU  CLOSE  THE  CONNECTION AND  EXIT
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lewa Workshop - User Registration</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            flex-direction: column;
        }
        .container {
            background-color: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
        }
        h2 {
            text-align: center;
            color: #333;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            color: #555;
        }
        input[type="text"],
        input[type="email"],
        input[type="password"],
        select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .error {
            background-color: #ffe0e0;
            color: #cc0000;
            border: 1px solid #cc0000;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
            text-align: center;
        }
        .success {
            background-color: #e0ffe0;
            color: #008000;
            border: 1px solid #008000;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
            text-align: center;
        }
        button {
            width: 100%;
            padding: 10px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        button:hover {
            background-color: #0056b3;
        }
        .form-footer {
            text-align: center;
            margin-top: 20px;
        }
        .form-footer a {
            color: #007bff;
            text-decoration: none;
        }
        .form-footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>User Registration</h2>

        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form action="user_registration.php" method="post" id="registrationForm">
            <input type="hidden" name="csrf_token_register" value="<?php echo htmlspecialchars($_SESSION['csrf_token_register']); ?>">

            <div class="form-group">
                <label for="full_name">Full Name:</label>
                <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($full_name); ?>" required>
            </div>

            <div class="form-group">
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>
            </div>

            <div class="form-group">
                <label for="phone_number">Phone Number:</label>
                <input type="text" id="phone_number" name="phone_number" value="<?php echo htmlspecialchars($phone_number); ?>" required>
            </div>

            <div class="form-group">
                <label for="id_number">ID Number:</label>
                <input type="text" id="id_number" name="id_number" value="<?php echo htmlspecialchars($id_number); ?>" required>
            </div>

            <div class="form-group">
                <label for="department">Department:</label>
                <select id="department" name="department" required>
                    <option value="">Select Department</option>
                    <option value="Education" <?php echo ($department == 'Education') ? 'selected' : ''; ?>>Education</option>
                    <option value="Opertaton" <?php echo ($department == 'Operation') ? 'selected' : ''; ?>>Operation</option>
                    <option value="Community development" <?php echo ($department == 'Community development') ? 'selected' : ''; ?>>Community Development</option>
                    <option value="Security" <?php echo ($department == 'Security') ? 'selected' : ''; ?>>Security</option>
                    <option value="Anti poaching" <?php echo ($department == 'Anti poaching') ? 'selected' : ''; ?>>Anti-poaching</option>
                    <option value="Finance" <?php echo ($department == 'Finance') ? 'selected' : ''; ?>>Finance</option>
                    <option value="Logistics" <?php echo ($department == 'Logistics') ? 'selected' : ''; ?>>Logistics & Supply chain</option>
                    <option value="Communication" <?php echo ($department == 'Communication') ? 'selected' : ''; ?>>Communiccation & Development</option>
                    </select>
            </div>

            <div class="form-group">
                <label for="username">Username:</label>
                <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($username); ?>" required>
            </div>

            <div class="form-group">
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" required>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password:</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>

            <div class="form-group">
                <label for="user_type">User Type:</label>
                <select id="user_type" name="user_type" required>
                    <option value="">Select User Type</option>
                    <option value="workshop_manager" <?php echo ($user_type == 'workshop_manager') ? 'selected' : ''; ?>>Workshop Manager</option>
                    <option value="service_advisor" <?php echo ($user_type == 'service_advisor') ? 'selected' : ''; ?>>Service Advisor</option>
                    <option value="driver" <?php echo ($user_type == 'driver') ? 'selected' : ''; ?>>Driver</option>
                    <option value="mechanic" <?php echo ($user_type == 'mechanic') ? 'selected' : ''; ?>>Mechanic</option>
                    <option value="parts_manager" <?php echo ($user_type == 'parts_manager') ? 'selected' : ''; ?>>Parts Manager</option>
                    <option value="supervisor" <?php echo ($user_type == 'supervisor') ? 'selected' : ''; ?>>Supervisor</option>
                </select>
            </div>

            <button type="submit">Register</button>
        </form>

        <div class="form-footer">
            <p>Already have an account? <a href="user_login.php">Login here</a></p>
        </div>
    </div>

    <script>
        document.getElementById('registrationForm').addEventListener('submit', function(event) {
            const password = document.getElementById('password').value;
            const confirm_password = document.getElementById('confirm_password').value;

            if (password !== confirm_password) {
                alert('Passwords do not match!');
                event.preventDefault();
                return false;
            }
            if (password.length < 8) {
                alert('Password must be at least 8 characters long!');
                event.preventDefault();
                return false;
            }
            return true;
        });
    </script>
</body>
</html>