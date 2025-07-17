<?php
session_start();

header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


define('BASE_URL', 'http://localhost/lewa');

require_once __DIR__ . '/db_connect.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password';
    } else {
        
        $query = "SELECT user_id, username, password, full_name, user_type, dashboard_access 
                  FROM users 
                  WHERE username = ? AND is_active = 1";
        $stmt = $conn->prepare($query);
        
        if (!$stmt) {
            $error = "Database error: " . $conn->error;
        } else {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                
                if (password_verify($password, $user['password'])) {
                    
                    session_regenerate_id(true);
                    
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['user_type'] = $user['user_type'];
                    $_SESSION['dashboard_access'] = $user['dashboard_access'];
                    $_SESSION['last_activity'] = time();
                    
                    
                    $dashboard_file = __DIR__ . '/dashboards/' . $user['dashboard_access'] . '.php';
                    if (file_exists($dashboard_file)) {
                        header("Location: " . BASE_URL . "/dashboards/" . $user['dashboard_access'] . ".php");
                        exit();
                    } else {
                        $error = "Dashboard not found - contact administrator";
                    }
                } else {
                    
                    $error = 'Invalid username or password';
                
                    error_log("Failed login attempt for username: $username");
                }
            } else {
                $error = 'Invalid username or password';
            }
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lewa Workshop - Login</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f6fa;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }
        
        .login-container {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
            padding: 30px;
        }
        
        .logo {
            text-align: center;
            margin-bottom: 20px;
        }
        
        .logo img {
            max-width: 150px;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 25px;
        }
        
        .login-header h1 {
            color: #2c3e50;
            margin-bottom: 5px;
            font-size: 24px;
        }
        
        .login-header p {
            color: #7f8c8d;
            margin: 0;
            font-size: 14px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #2c3e50;
            font-weight: 600;
            font-size: 14px;
        }
        
        .input-group {
            position: relative;
            display: flex;
            align-items: center;
        }
        
        .input-group .fas {
            position: absolute;
            left: 12px;
            color: #95a5a6;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 12px 12px 40px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .form-control:focus {
            border-color: #3498db;
            outline: none;
        }
        
        .btn-login {
            width: 100%;
            padding: 12px;
            background-color: #3498db;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.3s;
            display: flex; /* Added for spinner alignment */
            justify-content: center; /* Added for spinner alignment */
            align-items: center; /* Added for spinner alignment */
        }
        
        .btn-login:hover {
            background-color: #2980b9;
        }
        
        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            padding: 10px 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
        }
        
        .error-message .fas {
            margin-right: 10px;
        }
        
        .login-footer {
            text-align: center;
            margin-top: 20px;
            font-size: 13px;
            color: #7f8c8d;
        }
        
        .login-footer a {
            color: #3498db;
            text-decoration: none;
        }
        
        .login-footer a:hover {
            text-decoration: underline;
        }

        /* Loading spinner styles (reused for overlay) */
        .spinner {
            border: 4px solid rgba(255, 255, 255, 0.3);
            /* Enhanced shiny effect with gradient */
            border-top: 4px solid #3498db; /* Base color for the active part */
            border-right: 4px solid #2ecc71; /* Another color for gradient effect */
            border-bottom: 4px solid #f1c40f; /* Third color */
            border-left: 4px solid #e74c3c; /* Fourth color */
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
            margin-left: 10px; /* Space between text and spinner */
            /* Removed display: none; from here */
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Full-screen loading overlay styles */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            /* Conservancy theme gradient from index.php */
            background: linear-gradient(135deg, #5d8548 0%, #41622e 100%); 
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 9999; /* Ensure it's on top of everything */
            color: white;
            text-align: center;
            display: none; /* Hidden by default */
        }

        .loading-overlay .spinner {
            width: 50px; /* Larger spinner for the overlay */
            height: 50px;
            border-width: 5px;
            margin-left: 0; /* No margin needed when centered alone */
            margin-bottom: 20px; /* Space between spinner and text */
            /* Ensure the shiny effect applies to the larger spinner too */
            border-top: 5px solid #3498db;
            border-right: 5px solid #2ecc71;
            border-bottom: 5px solid #f1c40f;
            border-left: 5px solid #e74c3c;
            display: block; /* Explicitly make it visible when inside the overlay */
        }

        .loading-overlay p {
            font-size: 1.5em;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">
            <img src="<?php echo BASE_URL; ?>/images/logo6.jpg" alt="Lewa Workshop Logo">
        </div>
        
        <div class="login-header">
            <h1>Welcome Back</h1>
            <p>Sign in to access your dashboard</p>
        </div>
        
        <?php if (!empty($error)): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="post" id="loginForm">
            <div class="form-group">
                <label for="username">Username</label>
                <div class="input-group">
                    <i class="fas fa-user"></i>
                    <input type="text" id="username" name="username" class="form-control" 
                           placeholder="Enter your username" required
                           value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                </div>
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-group">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="password" name="password" class="form-control" 
                           placeholder="Enter your password" required>
                </div>
            </div>
            
            <div class="form-group">
                <button type="submit" class="btn-login" id="loginButton">
                    <span id="buttonText">Login</span>
                    <!-- Spinner inside button is now optional, as overlay handles primary loading feedback -->
                    <div class="spinner" id="buttonSpinner" style="display: none;"></div> 
                </button>
            </div>
        </form>
        
        <div class="login-footer">
            
        </div>
    </div>

    <!-- Full-screen Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="spinner"></div>
        <p>Wait a while, you will be directed to your dashboard</p>
    </div>

    <script>
        document.getElementById('loginForm').addEventListener('submit', function(event) {
            event.preventDefault(); // Prevent default form submission initially

            const loginForm = event.target;
            const loadingOverlay = document.getElementById('loadingOverlay');
            const loginContainer = document.querySelector('.login-container');
            const loginButton = document.getElementById('loginButton'); // Get button reference

            // Show loading overlay and hide login form
            loadingOverlay.style.display = 'flex';
            loginContainer.style.display = 'none'; // Hide the login form container
            
            // Disable the button immediately for a cleaner transition
            loginButton.disabled = true;
            loginButton.style.cursor = 'not-allowed';

            // Set the loading time to 60 seconds (60000 milliseconds)
            setTimeout(() => {
                loginForm.submit(); // Submit the form after the delay
            }, 2000); // 60 seconds delay
        });

        // If there's an error message on page load (from PHP), ensure the login form is visible
        document.addEventListener('DOMContentLoaded', function() {
            const errorMessages = document.querySelectorAll('.error-message');
            const loginContainer = document.querySelector('.login-container');
            const loadingOverlay = document.getElementById('loadingOverlay');

            if (errorMessages.length > 0) {
                // If an error exists, ensure the login form is visible and overlay is hidden
                loginContainer.style.display = 'block'; // Or 'flex' depending on its original display
                loadingOverlay.style.display = 'none';
                // The button will naturally be re-enabled because the page reloads on error.
            }
        });
    </script>
</body>
</html>
