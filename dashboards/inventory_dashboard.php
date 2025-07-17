<?php
// lewa/dashboards/inventory_dashboard.php (as per your user_registration.php dashboard_access)

// Include shared authentication check
require_once __DIR__ . '/../auth_check.php';
auth_check(); // This function handles session start, security headers, and redirects if not authenticated or unauthorized

// Ensure the user has parts_manager dashboard access
// The auth_check() should have already redirected if dashboard_access is wrong,
// but an explicit check here can add a layer of safety or for debugging.
if (!isset($_SESSION['dashboard_access']) || $_SESSION['dashboard_access'] !== 'inventory_dashboard') {
    error_log("Unauthorized dashboard access attempt by user: " . ($_SESSION['username'] ?? 'unknown') . ". Expected inventory_dashboard.");
    header('Location: ' . BASE_URL . '/user_login.php?error=no_access');
    exit();
}

// Additional check for user_type for parts manager specific functionality
if (!isset($_SESSION['user_type']) || strtolower($_SESSION['user_type']) !== 'parts_manager') {
    // This is redundant if auth_check handles dashboard_access based on user_type mapping,
    // but useful if user_type is used for more granular checks *within* the dashboard.
    error_log("User " . ($_SESSION['username'] ?? 'unknown') . " is not a parts_manager, but accessed inventory_dashboard directly.");
    // You might redirect to a generic success page or back to login if no specific dashboard is allowed
    header('Location: ' . BASE_URL . '/user_login.php?error=no_access');
    exit();
}


// Include necessary files
require_once __DIR__ . '/../config.php'; // For database connection ($pdo)
require_once __DIR__ . '/../functions.php'; // For helper functions like sanitizeInput, formatDate

// Set a CSRF token for forms
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Fetch initial data for display (or let JS handle via AJAX after page load)
// It's generally better to let JavaScript fetch data dynamically for single-page dashboard experience
// However, if you want some initial data pre-loaded for faster first render, you can do it here.

// Example: Fetch initial stats for the dashboard overview
try {
    // Get the total number of parts
    $stmt_parts_count = $pdo->query("SELECT COUNT(*) AS total_parts, SUM(stock) AS total_stock_quantity FROM parts");
    $parts_stats = $stmt_parts_count->fetch(PDO::FETCH_ASSOC);

    // Get number of low stock items
    $stmt_low_stock = $pdo->query("SELECT COUNT(*) AS low_stock_count FROM parts WHERE stock < min_stock");
    $low_stock_stats = $stmt_low_stock->fetch(PDO::FETCH_ASSOC);

    // Get number of pending purchase orders
    $stmt_pending_pos = $pdo->query("SELECT COUNT(*) AS pending_po_count, SUM(total_cost) AS pending_po_value FROM purchase_orders WHERE status = 'Pending'");
    $pending_pos_stats = $stmt_pending_pos->fetch(PDO::FETCH_ASSOC);

    $dashboard_stats = [
        'total_parts_items' => $parts_stats['total_parts'] ?? 0,
        'total_parts_quantity' => $parts_stats['total_stock_quantity'] ?? 0,
        'low_stock_alerts' => $low_stock_stats['low_stock_count'] ?? 0,
        'pending_purchase_orders' => $pending_pos_stats['pending_po_count'] ?? 0,
        'pending_po_value' => $pending_pos_stats['pending_po_value'] ?? 0.00,
    ];

} catch (PDOException $e) {
    error_log("Database error fetching dashboard stats: " . $e->getMessage());
    $dashboard_stats = [
        'total_parts_items' => 0,
        'total_parts_quantity' => 0,
        'low_stock_alerts' => 0,
        'pending_purchase_orders' => 0,
        'pending_po_value' => 0.00,
    ];
}

// --- Pass Permissions to JavaScript ---
// Ensure these keys exist in $_SESSION, as they should be set during login/registration based on user_type
$can_manage_inventory = $_SESSION['can_manage_inventory'] ?? 0;
$can_request_parts = $_SESSION['can_request_parts'] ?? 0;

// Pass BASE_URL, CSRF token, and permissions to JavaScript
$BASE_URL_JS = BASE_URL;
$CSRF_TOKEN_JS = $_SESSION['csrf_token'];
$CURRENT_USER_ID = $_SESSION['user_id']; // This would be the user_id of the logged-in parts manager
$CAN_MANAGE_INVENTORY_JS = $can_manage_inventory;
$CAN_REQUEST_PARTS_JS = $can_request_parts;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lewa Automotive - Parts Manager Dashboard</title>
    <!-- Tailwind CSS CDN for styling -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <!-- Inter Font -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Custom CSS from admin_dashboard.php, adapted for this dashboard */
        *, *::before, *::after {
            box-sizing: border-box;
        }

        :root {
            --primary: #3498db; /* Blue */
            --secondary: #2980b9; /* Darker Blue */
            --success: #2ecc71; /* Green */
            --danger: #e74c3c; /* Red */
            --warning: #f39c12; /* Orange */
            --light: #ecf0f1; /* Light Gray */
            --dark: #2c3e50; /* Dark Blue/Gray */
            --text-color: #333;
            --border-color: #ddd;
            --input-bg: #f9f9f9;
        }

        body {
            font-family: 'Inter', sans-serif; /* Changed to Inter */
            margin: 0;
            padding: 0;
            background-color: #f5f6fa; /* Lighter background */
            color: var(--text-color);
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
            flex-shrink: 0;
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
            display: flex;
            align-items: center;
            gap: 10px;
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
            overflow-x: hidden;
            background-color: #ffffff; /* White background for main content area */
            border-radius: 8px; /* Rounded corners for the main content block */
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); /* Subtle shadow */
            margin: 20px; /* Margin around the main content */
        }

        h1 {
            color: var(--dark);
            margin-bottom: 30px;
            text-align: center; /* Center align dashboard title */
            font-size: 2.5rem; /* Larger title */
            font-weight: 700;
        }

        .stat-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); /* Adjusted min-width for more cards */
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--light); /* Light gray background */
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            text-align: center;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            border-left: 5px solid var(--primary); /* Accent border */
            transition: transform 0.2s ease;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card h3 {
            margin-top: 0;
            color: var(--dark);
            font-size: 1.1rem; /* Slightly larger heading */
            margin-bottom: 10px;
        }

        .stat-card .value {
            font-size: 2.2rem; /* Larger value */
            font-weight: bold;
            color: var(--primary);
        }

        .tab-content {
            padding-top: 20px;
        }

        /* Form styles for modals and actions */
        .form-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.95rem;
        }

        input[type="text"],
        input[type="email"],
        input[type="number"],
        input[type="tel"],
        select,
        textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background-color: var(--input-bg);
            font-size: 1rem;
            color: var(--text-color);
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        input[type="text"]:focus,
        input[type="email"]:focus,
        input[type="number"]:focus,
        input[type="tel"]:focus,
        select:focus,
        textarea:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
        }

        button {
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 6px;
            cursor: pointer;
            transition: background-color 0.3s ease, transform 0.2s ease;
            font-size: 1rem;
            font-weight: bold;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        button.disabled {
            background-color: #ccc;
            cursor: not-allowed;
            transform: none;
        }
        button:hover:not(.disabled) {
            background-color: var(--secondary);
            transform: translateY(-2px);
        }
        button:active:not(.disabled) {
            transform: translateY(0);
            background-color: #2471a3;
        }


        /* Table styles */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            border-radius: 8px; /* Consistent rounded corners */
            overflow: hidden; /* Ensures rounded corners apply to content */
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        th, td {
            padding: 15px; /* More padding for readability */
            text-align: left;
            border-bottom: 1px solid #eee; /* Lighter border */
        }

        th {
            background-color: var(--light);
            color: var(--dark);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.9em;
        }

        td {
            background-color: #fff;
            color: #555;
            font-size: 0.95em;
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        tbody tr:hover {
            background-color: #f9f9f9;
        }

        /* Status indicators */
        .status-pending, .status-ordered, .status-cancelled {
            color: var(--warning); /* Orange */
            font-weight: bold;
        }
        .status-received {
            color: var(--success); /* Green */
            font-weight: bold;
        }
        .low-stock {
            background-color: #ffe0e0; /* Light red */
            color: var(--danger);
            font-weight: bold;
        }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.6); /* Darker overlay */
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease-out; /* Fade in animation */
        }

        .modal-content {
            background-color: #fefefe;
            padding: 30px; /* More padding */
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.2);
            width: 90%;
            max-width: 600px; /* Increased max-width */
            position: relative;
            animation: slideInTop 0.3s ease-out; /* Slide in animation */
        }

        .close-button {
            color: #aaa;
            position: absolute;
            top: 15px;
            right: 20px;
            font-size: 32px; /* Larger close button */
            font-weight: bold;
            cursor: pointer;
            transition: color 0.3s ease;
        }
        .close-button:hover,
        .close-button:focus {
            color: #333;
        }

        /* Message containers */
        .message-container {
            margin-top: 20px;
            padding: 10px;
            border-radius: 5px;
            font-weight: bold;
            display: none;
            border: 1px solid transparent;
            text-align: center;
        }

        .message-container.success {
            background-color: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }

        .message-container.error {
            background-color: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideInTop {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .dashboard-container {
                grid-template-columns: 1fr;
            }

            .sidebar {
                width: 100%;
                padding-bottom: 10px;
                display: flex;
                flex-direction: column;
                align-items: center;
            }

            .sidebar h2 {
                margin-bottom: 15px;
            }

            .sidebar nav ul {
                display: flex;
                flex-wrap: wrap;
                justify-content: center;
                gap: 5px;
            }
            .sidebar nav ul li {
                margin-bottom: 0;
            }
            .sidebar nav ul li a {
                padding: 8px 10px;
                font-size: 0.85em;
                text-align: center;
                flex-direction: column;
                gap: 3px;
            }
            .sidebar nav ul li a i {
                margin-right: 0;
            }

            .main-content {
                padding: 15px;
                margin: 10px;
            }

            h1 {
                font-size: 1.8rem;
                text-align: center;
            }

            .stat-cards {
                grid-template-columns: 1fr;
            }

            .stat-card {
                padding: 15px;
            }

            .stat-card .value {
                font-size: 1.8rem;
            }

            table {
                min-width: 100%;
            }

            th, td {
                padding: 10px;
                font-size: 0.9em;
            }

            button {
                padding: 10px 15px;
                font-size: 0.9em;
            }
            .modal-content {
                padding: 20px;
            }
            .close-button {
                font-size: 28px;
            }
        }

        @media (max-width: 480px) {
            .sidebar nav ul li a {
                font-size: 0.75em;
                padding: 5px 8px;
            }
            .sidebar h2 {
                font-size: 1.5rem;
            }
            .main-content {
                padding: 10px;
            }
            h1 {
                font-size: 1.5rem;
            }
            .stat-card .value {
                font-size: 1.5rem;
            }
            input, select, button {
                font-size: 0.9rem;
            }
        }

        /* Specific styles for low stock alerts */
        #lowStockList li {
            background-color: #fef2f2; /* Red-50 */
            border-left: 4px solid #ef4444; /* Red-500 */
            padding: 8px 12px;
            margin-bottom: 5px;
            border-radius: 4px;
            font-size: 0.9em;
            color: #dc2626; /* Red-600 */
        }
        #lowStockAlerts {
            border: 1px dashed #fca5a5; /* Red-300 */
            background-color: #fef2f2; /* Red-50 */
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        #lowStockAlerts h3 {
            color: #b91c1c; /* Red-700 */
            margin-bottom: 10px;
            font-weight: 600;
        }

        /* Specific styles for PO part selection in modal */
        #poPartsContainer {
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 15px;
            background-color: var(--input-bg);
            max-height: 250px;
            overflow-y: auto;
            margin-bottom: 10px;
        }
        #poPartsContainer .flex {
            margin-bottom: 10px;
            align-items: center;
        }
        #poPartsContainer .flex:last-child {
            margin-bottom: 0;
        }
        .po-part-select {
            flex-grow: 1;
        }
        .po-part-qty {
            width: 80px; /* Fixed width for quantity input */
            flex-shrink: 0;
        }
        .remove-part-btn {
            background-color: var(--danger);
            padding: 6px 10px;
            font-size: 0.85em;
            border-radius: 4px;
        }
        .remove-part-btn:hover {
            background-color: #c0392b;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="sidebar">
            <h2>Lewa Workshop</h2>
            <p class="text-white text-opacity-80 text-center mb-4">Welcome, <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Parts Manager'); ?></p>
            <nav>
                <ul<?php
// lewa/dashboards/inventory_dashboard.php (as per your user_registration.php dashboard_access)

// Include shared authentication check
require_once __DIR__ . '/../auth_check.php';
auth_check(); // This function handles session start, security headers, and redirects if not authenticated or unauthorized

// Ensure the user has parts_manager dashboard access
// The auth_check() should have already redirected if dashboard_access is wrong,
// but an explicit check here can add a layer of safety or for debugging.
if (!isset($_SESSION['dashboard_access']) || $_SESSION['dashboard_access'] !== 'inventory_dashboard') {
    error_log("Unauthorized dashboard access attempt by user: " . ($_SESSION['username'] ?? 'unknown') . ". Expected inventory_dashboard.");
    header('Location: ' . BASE_URL . '/user_login.php?error=no_access');
    exit();
}

// Additional check for user_type for parts manager specific functionality
if (!isset($_SESSION['user_type']) || strtolower($_SESSION['user_type']) !== 'parts_manager') {
    // This is redundant if auth_check handles dashboard_access based on user_type mapping,
    // but useful if user_type is used for more granular checks *within* the dashboard.
    error_log("User " . ($_SESSION['username'] ?? 'unknown') . " is not a parts_manager, but accessed inventory_dashboard directly.");
    // You might redirect to a generic success page or back to login if no specific dashboard is allowed
    header('Location: ' . BASE_URL . '/user_login.php?error=no_access');
    exit();
}


// Include necessary files
require_once __DIR__ . '/../config.php'; // For database connection ($pdo)
require_once __DIR__ . '/../functions.php'; // For helper functions like sanitizeInput, formatDate

// Set a CSRF token for forms
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Fetch initial data for display (or let JS handle via AJAX after page load)
// It's generally better to let JavaScript fetch data dynamically for single-page dashboard experience
// However, if you want some initial data pre-loaded for faster first render, you can do it here.

// Example: Fetch initial stats for the dashboard overview
try {
    // Get the total number of parts
    $stmt_parts_count = $pdo->query("SELECT COUNT(*) AS total_parts, SUM(stock) AS total_stock_quantity FROM parts");
    $parts_stats = $stmt_parts_count->fetch(PDO::FETCH_ASSOC);

    // Get number of low stock items
    $stmt_low_stock = $pdo->query("SELECT COUNT(*) AS low_stock_count FROM parts WHERE stock < min_stock");
    $low_stock_stats = $stmt_low_stock->fetch(PDO::FETCH_ASSOC);

    // Get number of pending purchase orders
    $stmt_pending_pos = $pdo->query("SELECT COUNT(*) AS pending_po_count, SUM(total_cost) AS pending_po_value FROM purchase_orders WHERE status = 'Pending'");
    $pending_pos_stats = $stmt_pending_pos->fetch(PDO::FETCH_ASSOC);

    $dashboard_stats = [
        'total_parts_items' => $parts_stats['total_parts'] ?? 0,
        'total_parts_quantity' => $parts_stats['total_stock_quantity'] ?? 0,
        'low_stock_alerts' => $low_stock_stats['low_stock_count'] ?? 0,
        'pending_purchase_orders' => $pending_pos_stats['pending_po_count'] ?? 0,
        'pending_po_value' => $pending_pos_stats['pending_po_value'] ?? 0.00,
    ];

} catch (PDOException $e) {
    error_log("Database error fetching dashboard stats: " . $e->getMessage());
    $dashboard_stats = [
        'total_parts_items' => 0,
        'total_parts_quantity' => 0,
        'low_stock_alerts' => 0,
        'pending_purchase_orders' => 0,
        'pending_po_value' => 0.00,
    ];
}

// --- Pass Permissions to JavaScript ---
// Ensure these keys exist in $_SESSION, as they should be set during login/registration based on user_type
$can_manage_inventory = $_SESSION['can_manage_inventory'] ?? 0;
$can_request_parts = $_SESSION['can_request_parts'] ?? 0;

// Pass BASE_URL, CSRF token, and permissions to JavaScript
$BASE_URL_JS = BASE_URL;
$CSRF_TOKEN_JS = $_SESSION['csrf_token'];
$CURRENT_USER_ID = $_SESSION['user_id']; // This would be the user_id of the logged-in parts manager
$CAN_MANAGE_INVENTORY_JS = $can_manage_inventory;
$CAN_REQUEST_PARTS_JS = $can_request_parts;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lewa Automotive - Parts Manager Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Custom CSS from admin_dashboard.php, adapted for this dashboard */
        *, *::before, *::after {
            box-sizing: border-box;
        }

        :root {
            --primary: #3498db; /* Blue */
            --secondary: #2980b9; /* Darker Blue */
            --success: #2ecc71; /* Green */
            --danger: #e74c3c; /* Red */
            --warning: #f39c12; /* Orange */
            --light: #ecf0f1; /* Light Gray */
            --dark: #2c3e50; /* Dark Blue/Gray */
            --text-color: #333;
            --border-color: #ddd;
            --input-bg: #f9f9f9;
        }

        body {
            font-family: 'Inter', sans-serif; /* Changed to Inter */
            margin: 0;
            padding: 0;
            background-color: #f5f6fa; /* Lighter background */
            color: var(--text-color);
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
            flex-shrink: 0;
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
            display: flex;
            align-items: center;
            gap: 10px;
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
            overflow-x: hidden;
            background-color: #ffffff; /* White background for main content area */
            border-radius: 8px; /* Rounded corners for the main content block */
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); /* Subtle shadow */
            margin: 20px; /* Margin around the main content */
        }

        h1 {
            color: var(--dark);
            margin-bottom: 30px;
            text-align: center; /* Center align dashboard title */
            font-size: 2.5rem; /* Larger title */
            font-weight: 700;
        }

        .stat-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); /* Adjusted min-width for more cards */
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--light); /* Light gray background */
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            text-align: center;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            border-left: 5px solid var(--primary); /* Accent border */
            transition: transform 0.2s ease;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card h3 {
            margin-top: 0;
            color: var(--dark);
            font-size: 1.1rem; /* Slightly larger heading */
            margin-bottom: 10px;
        }

        .stat-card .value {
            font-size: 2.2rem; /* Larger value */
            font-weight: bold;
            color: var(--primary);
        }

        .tab-content {
            padding-top: 20px;
        }

        /* Form styles for modals and actions */
        .form-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.95rem;
        }

        input[type="text"],
        input[type="email"],
        input[type="number"],
        input[type="tel"],
        select,
        textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background-color: var(--input-bg);
            font-size: 1rem;
            color: var(--text-color);
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        input[type="text"]:focus,
        input[type="email"]:focus,
        input[type="number"]:focus,
        input[type="tel"]:focus,
        select:focus,
        textarea:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
        }

        button {
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 6px;
            cursor: pointer;
            transition: background-color 0.3s ease, transform 0.2s ease;
            font-size: 1rem;
            font-weight: bold;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        button.disabled {
            background-color: #ccc;
            cursor: not-allowed;
            transform: none;
        }
        button:hover:not(.disabled) {
            background-color: var(--secondary);
            transform: translateY(-2px);
        }
        button:active:not(.disabled) {
            transform: translateY(0);
            background-color: #2471a3;
        }


        /* Table styles */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            border-radius: 8px; /* Consistent rounded corners */
            overflow: hidden; /* Ensures rounded corners apply to content */
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        th, td {
            padding: 15px; /* More padding for readability */
            text-align: left;
            border-bottom: 1px solid #eee; /* Lighter border */
        }

        th {
            background-color: var(--light);
            color: var(--dark);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.9em;
        }

        td {
            background-color: #fff;
            color: #555;
            font-size: 0.95em;
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        tbody tr:hover {
            background-color: #f9f9f9;
        }

        /* Status indicators */
        .status-pending, .status-ordered, .status-cancelled {
            color: var(--warning); /* Orange */
            font-weight: bold;
        }
        .status-received {
            color: var(--success); /* Green */
            font-weight: bold;
        }
        .low-stock {
            background-color: #ffe0e0; /* Light red */
            color: var(--danger);
            font-weight: bold;
        }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.6); /* Darker overlay */
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease-out; /* Fade in animation */
        }

        .modal-content {
            background-color: #fefefe;
            padding: 30px; /* More padding */
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.2);
            width: 90%;
            max-width: 600px; /* Increased max-width */
            position: relative;
            animation: slideInTop 0.3s ease-out; /* Slide in animation */
        }

        .close-button {
            color: #aaa;
            position: absolute;
            top: 15px;
            right: 20px;
            font-size: 32px; /* Larger close button */
            font-weight: bold;
            cursor: pointer;
            transition: color 0.3s ease;
        }
        .close-button:hover,
        .close-button:focus {
            color: #333;
        }

        /* Message containers */
        .message-container {
            margin-top: 20px;
            padding: 10px;
            border-radius: 5px;
            font-weight: bold;
            display: none;
            border: 1px solid transparent;
            text-align: center;
        }

        .message-container.success {
            background-color: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }

        .message-container.error {
            background-color: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideInTop {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .dashboard-container {
                grid-template-columns: 1fr;
            }

            .sidebar {
                width: 100%;
                padding-bottom: 10px;
                display: flex;
                flex-direction: column;
                align-items: center;
            }

            .sidebar h2 {
                margin-bottom: 15px;
            }

            .sidebar nav ul {
                display: flex;
                flex-wrap: wrap;
                justify-content: center;
                gap: 5px;
            }
            .sidebar nav ul li {
                margin-bottom: 0;
            }
            .sidebar nav ul li a {
                padding: 8px 10px;
                font-size: 0.85em;
                text-align: center;
                flex-direction: column;
                gap: 3px;
            }
            .sidebar nav ul li a i {
                margin-right: 0;
            }

            .main-content {
                padding: 15px;
                margin: 10px;
            }

            h1 {
                font-size: 1.8rem;
                text-align: center;
            }

            .stat-cards {
                grid-template-columns: 1fr;
            }

            .stat-card {
                padding: 15px;
            }

            .stat-card .value {
                font-size: 1.8rem;
            }

            table {
                min-width: 100%;
            }

            th, td {
                padding: 10px;
                font-size: 0.9em;
            }

            button {
                padding: 10px 15px;
                font-size: 0.9em;
            }
            .modal-content {
                padding: 20px;
            }
            .close-button {
                font-size: 28px;
            }
        }

        @media (max-width: 480px) {
            .sidebar nav ul li a {
                font-size: 0.75em;
                padding: 5px 8px;
            }
            .sidebar h2 {
                font-size: 1.5rem;
            }
            .main-content {
                padding: 10px;
            }
            h1 {
                font-size: 1.5rem;
            }
            .stat-card .value {
                font-size: 1.5rem;
            }
            input, select, button {
                font-size: 0.9rem;
            }
        }

        /* Specific styles for low stock alerts */
        #lowStockList li {
            background-color: #fef2f2; /* Red-50 */
            border-left: 4px solid #ef4444; /* Red-500 */
            padding: 8px 12px;
            margin-bottom: 5px;
            border-radius: 4px;
            font-size: 0.9em;
            color: #dc2626; /* Red-600 */
        }
        #lowStockAlerts {
            border: 1px dashed #fca5a5; /* Red-300 */
            background-color: #fef2f2; /* Red-50 */
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        #lowStockAlerts h3 {
            color: #b91c1c; /* Red-700 */
            margin-bottom: 10px;
            font-weight: 600;
        }

        /* Specific styles for PO part selection in modal */
        #poPartsContainer {
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 15px;
            background-color: var(--input-bg);
            max-height: 250px;
            overflow-y: auto;
            margin-bottom: 10px;
        }
        #poPartsContainer .flex {
            margin-bottom: 10px;
            align-items: center;
        }
        #poPartsContainer .flex:last-child {
            margin-bottom: 0;
        }
        .po-part-select {
            flex-grow: 1;
        }
        .po-part-qty {
            width: 80px; /* Fixed width for quantity input */
            flex-shrink: 0;
        }
        .remove-part-btn {
            background-color: var(--danger);
            padding: 6px 10px;
            font-size: 0.85em;
            border-radius: 4px;
        }
        .remove-part-btn:hover {
            background-color: #c0392b;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="sidebar">
            <h2>Lewa Workshop</h2>
            <p class="text-white text-opacity-80 text-center mb-4">Welcome, <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Parts Manager'); ?></p>
            <nav>
                <ul>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/inventory_dashboard.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'inventory_dashboard.php' ? 'active' : ''); ?>"><i class="fas fa-tachometer-alt"></i> Dashboard Home</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/inventory.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'inventory.php' ? 'active' : ''); ?>"><i class="fas fa-warehouse"></i> Inventory</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/purchase_orders.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'purchase_orders.php' ? 'active' : ''); ?>"><i class="fas fa-file-invoice"></i> Purchase Orders</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/suppliers.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'suppliers.php' ? 'active' : ''); ?>"><i class="fas fa-truck"></i> Suppliers</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/parts_usage_log.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'parts_usage_log.php' ? 'active' : ''); ?>"><i class="fas fa-history"></i> Parts Usage Log</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </nav>
        </div>

        <div class="main-content">
            <h1>Parts Manager Dashboard</h1>

            <div class="stat-cards">
                <div class="stat-card">
                    <h3>Total Parts Items</h3>
                    <div class="value" id="stat-total-parts-items"><?php echo $dashboard_stats['total_parts_items']; ?></div>
                </div>
                <div class="stat-card">
                    <h3>Total Parts Quantity</h3>
                    <div class="value" id="stat-total-parts-quantity"><?php echo $dashboard_stats['total_parts_quantity']; ?></div>
                </div>
                <div class="stat-card">
                    <h3>Low Stock Alerts</h3>
                    <div class="value text-red-500" id="stat-low-stock-alerts"><?php echo $dashboard_stats['low_stock_alerts']; ?></div>
                </div>
                <div class="stat-card">
                    <h3>Pending Purchase Orders</h3>
                    <div class="value" id="stat-pending-pos"><?php echo $dashboard_stats['pending_purchase_orders']; ?></div>
                </div>
                <div class="stat-card">
                    <h3>Pending PO Value</h3>
                    <div class="value" id="stat-pending-po-value"><?php echo number_format($dashboard_stats['pending_po_value'], 2); ?></div>
                </div>
            </div>

            <div id="dashboardOverview">
                <h2 class="text-2xl font-semibold text-gray-800 mb-4">Dashboard Overview</h2>
                <p class="text-gray-600 mb-6">Quick insights into your inventory and purchase orders.</p>

                <div id="lowStockAlerts" class="mb-4 <?php echo ($dashboard_stats['low_stock_alerts'] > 0 ? '' : 'hidden'); ?>">
                    <h3 class="text-lg font-medium">Low Stock Alerts:</h3>
                    <ul id="lowStockList" class="list-disc pl-5">
                        <?php if ($dashboard_stats['low_stock_alerts'] > 0): ?>
                            <?php
                                // Re-fetch parts data to list low stock items directly here
                                try {
                                    $stmt_all_parts = $pdo->query("SELECT name, sku, stock, min_stock, unit FROM parts WHERE stock < min_stock");
                                    $low_stock_parts = $stmt_all_parts->fetchAll(PDO::FETCH_ASSOC);
                                    foreach ($low_stock_parts as $part): ?>
                                        <li><?php echo htmlspecialchars($part['name']); ?> (SKU: <?php echo htmlspecialchars($part['sku']); ?>) - Current: <?php echo htmlspecialchars($part['stock']); ?> <?php echo htmlspecialchars($part['unit'] ?? ''); ?>, Min: <?php echo htmlspecialchars($part['min_stock']); ?></li>
                                    <?php endforeach;
                                } catch (PDOException $e) {
                                    error_log("Database error fetching low stock parts for dashboard: " . $e->getMessage());
                                    echo "<li>Error loading low stock items.</li>";
                                }
                            ?>
                        <?php else: ?>
                            <li>No low stock items at the moment.</li>
                        <?php endif; ?>
                    </ul>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-6">
                    <div class="bg-white p-6 rounded-lg shadow">
                        <h3 class="text-lg font-medium text-gray-800 mb-2">Inventory Management</h3>
                        <p class="text-gray-600 mb-4">View and manage all your automotive parts, including current stock levels and locations.</p>
                        <a href="<?php echo BASE_URL; ?>/dashboards/inventory.php" class="text-blue-600 hover:underline">Go to Inventory <i class="fas fa-arrow-right ml-1"></i></a>
                    </div>
                    <div class="bg-white p-6 rounded-lg shadow">
                        <h3 class="text-lg font-medium text-gray-800 mb-2">Purchase Orders</h3>
                        <p class="text-gray-600 mb-4">Create, track, and manage all your incoming parts orders from suppliers.</p>
                        <a href="<?php echo BASE_URL; ?>/dashboards/purchase_orders.php" class="text-blue-600 hover:underline">Manage Purchase Orders <i class="fas fa-arrow-right ml-1"></i></a>
                    </div>
                    <div class="bg-white p-6 rounded-lg shadow">
                        <h3 class="text-lg font-medium text-gray-800 mb-2">Supplier Management</h3>
                        <p class="text-gray-600 mb-4">Maintain a list of all your suppliers and their contact information.</p>
                        <a href="<?php echo BASE_URL; ?>/dashboards/suppliers.php" class="text-blue-600 hover:underline">View Suppliers <i class="fas fa-arrow-right ml-1"></i></a>
                    </div>
                    <div class="bg-white p-6 rounded-lg shadow">
                        <h3 class="text-lg font-medium text-gray-800 mb-2">Parts Usage Log</h3>
                        <p class="text-gray-600 mb-4">Review historical data on parts consumed for jobs and received through orders.</p>
                        <a href="<?php echo BASE_URL; ?>/dashboards/parts_usage_log.php" class="text-blue-600 hover:underline">View Usage Log <i class="fas fa-arrow-right ml-1"></i></a>
                    </div>
                </div>

            </div>

        </div>
    </div>

    <script>
        const BASE_URL = "<?php echo $BASE_URL_JS; ?>";
        const CSRF_TOKEN = "<?php echo $CSRF_TOKEN_JS; ?>";
        const CURRENT_USER_ID = "<?php echo $CURRENT_USER_ID; ?>";
        const CAN_MANAGE_INVENTORY = <?php echo $CAN_MANAGE_INVENTORY_JS; ?>; // 0 or 1
        const CAN_REQUEST_PARTS = <?php echo $CAN_REQUEST_PARTS_JS; ?>; // 0 or 1

        // This dashboard will now only handle refreshing its own stats and basic overview.
        // All detailed table rendering and modal logic for Inventory, POs, Suppliers, Usage
        // will be moved to their respective dedicated PHP files.

        async function refreshDashboardStats() {
            try {
                const response = await fetch(`${BASE_URL}/api/parts.php`); // Get all parts for stats calculation
                const data = await response.json();
                if (data.success) {
                    const allParts = data.data;
                    let totalPartsItems = allParts.length;
                    let totalPartsQuantity = allParts.reduce((sum, part) => sum + parseInt(part.stock), 0);
                    let lowStockAlerts = allParts.filter(part => parseInt(part.stock) < parseInt(part.min_stock)).length;

                    const poResponse = await fetch(`${BASE_URL}/api/purchase_orders.php`);
                    const poData = await poResponse.json();
                    let pendingPurchaseOrders = 0;
                    let pendingPoValue = 0;
                    if(poData.success) {
                        const pendingPOs = poData.data.filter(po => po.status === 'Pending');
                        pendingPurchaseOrders = pendingPOs.length;
                        pendingPoValue = pendingPOs.reduce((sum, po) => sum + parseFloat(po.total_cost), 0);
                    }

                    document.getElementById('stat-total-parts-items').textContent = totalPartsItems;
                    document.getElementById('stat-total-parts-quantity').textContent = totalPartsQuantity;
                    document.getElementById('stat-low-stock-alerts').textContent = lowStockAlerts;
                    document.getElementById('stat-pending-pos').textContent = pendingPurchaseOrders;
                    document.getElementById('stat-pending-po-value').textContent = `${pendingPoValue.toFixed(2)}`;

                    // Update the visibility of the low stock alerts container
                    document.getElementById('lowStockAlerts').classList.toggle('hidden', lowStockAlerts === 0);

                    // Re-render low stock list in case it's on this page
                    renderLowStockAlertsOnDashboard(allParts);

                } else {
                    console.error('Error refreshing stats:', data.message);
                }
            } catch (error) {
                console.error('Error refreshing stats:', error);
            }
        }

        function renderLowStockAlertsOnDashboard(parts) {
            const lowStockList = document.getElementById('lowStockList');
            lowStockList.innerHTML = ''; // Clear existing list

            const lowStockParts = parts.filter(part => parseInt(part.stock) < parseInt(part.min_stock));

            if (lowStockParts.length > 0) {
                document.getElementById('lowStockAlerts').classList.remove('hidden');
                lowStockParts.forEach(part => {
                    const listItem = document.createElement('li');
                    listItem.textContent = `${part.name} (SKU: ${part.sku}) - Current: ${part.stock}, Min: ${part.min_stock} ${part.unit || ''}`;
                    lowStockList.appendChild(listItem);
                });
            } else {
                document.getElementById('lowStockAlerts').classList.add('hidden');
                const listItem = document.createElement('li');
                listItem.textContent = 'No low stock items at the moment.';
                lowStockList.appendChild(listItem);
            }
        }


        // Initialize dashboard on load
        document.addEventListener('DOMContentLoaded', () => {
            refreshDashboardStats(); // Fetch and display initial stats
            // The applyPermissions function is less critical here since direct navigation links
            // will rely on server-side checks for access to the linked pages.
            // However, if there are interactive elements remaining on this specific dashboard overview,
            // you might still use a simplified applyPermissions for them.
        });
    </script>
</body>
</html>
            </nav>
        </div>

        <div class="main-content">
            <h1>Parts Manager Dashboard</h1>

            <div class="stat-cards">
                <div class="stat-card">
                    <h3>Total Parts Items</h3>
                    <div class="value" id="stat-total-parts-items"><?php echo $dashboard_stats['total_parts_items']; ?></div>
                </div>
                <div class="stat-card">
                    <h3>Total Parts Quantity</h3>
                    <div class="value" id="stat-total-parts-quantity"><?php echo $dashboard_stats['total_parts_quantity']; ?></div>
                </div>
                <div class="stat-card">
                    <h3>Low Stock Alerts</h3>
                    <div class="value text-red-500" id="stat-low-stock-alerts"><?php echo $dashboard_stats['low_stock_alerts']; ?></div>
                </div>
                <div class="stat-card">
                    <h3>Pending Purchase Orders</h3>
                    <div class="value" id="stat-pending-pos"><?php echo $dashboard_stats['pending_purchase_orders']; ?></div>
                </div>
                <div class="stat-card">
                    <h3>Pending PO Value</h3>
                    <div class="value" id="stat-pending-po-value"><?php echo number_format($dashboard_stats['pending_po_value'], 2); ?></div>
                </div>
            </div>

            <!-- Inventory Section -->
            <div id="inventoryTab" class="tab-content">
                <h2 class="text-2xl font-semibold text-gray-800 mb-4">Manage Inventory</h2>
                <div class="flex justify-end items-center mb-4"> <!-- Removed search and kept only add button -->
                    <button id="addPartBtn" onclick="window.location.href='<?php echo BASE_URL; ?>/add_part_for_parts_manager.php'" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg shadow-md transition duration-300 ease-in-out transform hover:scale-105">
                        <i class="fas fa-plus"></i> Add New Part
                    </button>
                </div>

                <!-- Low Stock Alerts -->
                <div id="lowStockAlerts" class="mb-4 hidden">
                    <h3 class="text-lg font-medium">Low Stock Alerts:</h3>
                    <ul id="lowStockList" class="list-disc pl-5">
                        <!-- Low stock parts will be listed here by JS -->
                    </ul>
                </div>

                <div class="overflow-x-auto rounded-lg shadow-sm border border-gray-200">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>SKU</th>
                                <th>Current Stock</th>
                                <th>Min Stock</th>
                                <th>Price</th>
                                <th>Location</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="inventoryTableBody">
                            <!-- Inventory rows will be injected here by JS -->
                            <tr><td colspan="7" class="text-center py-4">Loading inventory...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Purchase Orders Section -->
            <div id="purchaseOrdersTab" class="tab-content hidden">
                <h2 class="text-2xl font-semibold text-gray-800 mb-4">Manage Purchase Orders</h2>
                <div class="flex justify-end items-center mb-4"> <!-- Removed search and kept only create button -->
                    <button id="createPoBtn" onclick="openModal('addPoModal')" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg shadow-md transition duration-300 ease-in-out transform hover:scale-105">
                        <i class="fas fa-file-invoice"></i> Create New PO
                    </button>
                </div>
                <div class="overflow-x-auto rounded-lg shadow-sm border border-gray-200">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead>
                            <tr>
                                <th>PO ID</th>
                                <th>Supplier</th>
                                <th>Date Created</th>
                                <th>Status</th>
                                <th>Total Cost</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="purchaseOrdersTableBody">
                            <!-- PO rows will be injected here by JS -->
                            <tr><td colspan="6" class="text-center py-4">Loading purchase orders...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Suppliers Section -->
            <div id="suppliersTab" class="tab-content hidden">
                <h2 class="text-2xl font-semibold text-gray-800 mb-4">Manage Suppliers</h2>
                <div class="flex justify-end items-center mb-4"> <!-- Removed search and kept only add button -->
                    <button id="addSupplierBtn" onclick="openAddSupplierModal()" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg shadow-md transition duration-300 ease-in-out transform hover:scale-105">
                        <i class="fas fa-truck"></i> Add New Supplier
                    </button>
                </div>
                <div class="overflow-x-auto rounded-lg shadow-sm border border-gray-200">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Contact Person</th>
                                <th>Phone</th>
                                <th>Email</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="suppliersTableBody">
                            <!-- Supplier rows will be injected here by JS -->
                            <tr><td colspan="5" class="text-center py-4">Loading suppliers...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Parts Usage Section -->
            <div id="usageTab" class="tab-content hidden">
                <h2 class="text-2xl font-semibold text-gray-800 mb-4">Parts Usage Log</h2>
                <p class="text-sm text-gray-600 mb-4">This section shows a log of parts used in various jobs or received via purchase orders.</p>
                <!-- Removed search input -->
                <div class="overflow-x-auto rounded-lg shadow-sm border border-gray-200">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead>
                            <tr>
                                <th>Part Name (SKU)</th>
                                <th>Source/Job ID</th>
                                <th>Quantity Change</th>
                                <th>Type</th>
                                <th>Date Logged</th>
                                <th>Logged By</th>
                            </tr>
                        </thead>
                        <tbody id="usageTableBody">
                            <!-- Usage logs will be injected here by JS -->
                            <tr><td colspan="6" class="text-center py-4">Loading usage logs...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <!-- Modals -->

    <!-- Add/Edit Supplier Modal -->
    <div id="addSupplierModal" class="modal">
        <div class="modal-content">
            <span class="close-button" onclick="closeModal('addSupplierModal')">&times;</span>
            <h3 class="text-xl font-semibold mb-6 text-gray-800" id="supplierModalTitle">Add New Supplier</h3>
            <div id="supplierMessage" class="message-container"></div>
            <form id="supplierForm">
                <input type="hidden" id="supplierId">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF_TOKEN_JS); ?>">
                <div class="form-group">
                    <label for="supplierName">Supplier Name</label>
                    <input type="text" id="supplierName" name="name" required>
                </div>
                <div class="form-group">
                    <label for="supplierContactPerson">Contact Person</label>
                    <input type="text" id="supplierContactPerson" name="contactPerson">
                </div>
                <div class="form-group">
                    <label for="supplierPhone">Phone</label>
                    <input type="tel" id="supplierPhone" name="phone">
                </div>
                <div class="form-group">
                    <label for="supplierEmail">Email</label>
                    <input type="email" id="supplierEmail" name="email">
                </div>
                <div class="flex justify-end mt-6">
                    <button type="submit" id="saveSupplierBtn"><i class="fas fa-save"></i> Save Supplier</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Create Purchase Order Modal -->
    <div id="addPoModal" class="modal">
        <div class="modal-content">
            <span class="close-button" onclick="closeModal('addPoModal')">&times;</span>
            <h3 class="text-xl font-semibold mb-6 text-gray-800">Create New Purchase Order</h3>
            <div id="poMessage" class="message-container"></div>
            <form id="poForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF_TOKEN_JS); ?>">
                <div class="form-group">
                    <label for="poSupplier">Supplier</label>
                    <select id="poSupplier" name="supplierId" required>
                        <option value="">Select a Supplier</option>
                        <!-- Options populated by JS via AJAX -->
                    </select>
                </div>

                <div class="form-group">
                    <label class="block text-sm font-medium text-gray-700">Parts for PO</label>
                    <div id="poPartsContainer">
                        <!-- Initial part selection field -->
                        <div class="flex items-center gap-2 mb-2">
                            <select class="po-part-select flex-grow" required>
                                <option value="">Select Part</option>
                            </select>
                            <input type="number" min="1" value="1" placeholder="Qty" class="po-part-qty" required>
                            <button type="button" onclick="removePoPartField(this)" class="remove-part-btn"><i class="fas fa-times"></i></button>
                        </div>
                    </div>
                    <button type="button" id="addAnotherPartBtn" onclick="addPoPartField()" class="mt-2 bg-green-600 hover:bg-green-700 text-white py-2 px-4 rounded-md text-sm transition duration-300 ease-in-out transform hover:scale-105">
                        <i class="fas fa-plus"></i> Add Another Part
                    </button>
                </div>

                <div class="flex justify-end mt-6">
                    <button type="submit" id="createPoFormBtn"><i class="fas fa-paper-plane"></i> Create PO</button>
                </div>
            </form>
        </div>
    </div>

    <!-- PO Details Modal -->
    <div id="poDetailsModal" class="modal">
        <div class="modal-content">
            <span class="close-button" onclick="closeModal('poDetailsModal')">&times;</span>
            <h3 class="text-xl font-semibold mb-4 text-gray-800">Purchase Order Details</h3>
            <div id="poDetailsContent" class="text-gray-700 text-base">
                <!-- Details loaded here -->
            </div>
            <div class="flex justify-end mt-6">
                <button onclick="closeModal('poDetailsModal')" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-4 rounded-lg shadow-md transition duration-300 ease-in-out transform hover:scale-105">Close</button>
            </div>
        </div>
    </div>


    <script>
        const BASE_URL = "<?php echo $BASE_URL_JS; ?>";
        const CSRF_TOKEN = "<?php echo $CSRF_TOKEN_JS; ?>";
        const CURRENT_USER_ID = "<?php echo $CURRENT_USER_ID; ?>";
        const CAN_MANAGE_INVENTORY = <?php echo $CAN_MANAGE_INVENTORY_JS; ?>; // 0 or 1
        const CAN_REQUEST_PARTS = <?php echo $CAN_REQUEST_PARTS_JS; ?>; // 0 or 1


        // Global data arrays (will be populated from API)
        let partsData = [];
        let suppliersData = [];
        let purchaseOrdersData = [];
        let usageLogsData = [];

        // --- Utility Functions ---
        function showMessage(element, message, type) {
            element.textContent = message;
            element.className = `message-container ${type}`;
            element.style.display = 'block';
            setTimeout(() => {
                element.style.display = 'none';
            }, 5000);
        }

        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
            if (modalId === 'addPoModal') {
                populatePoDropdowns();
            }
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            // Reset forms and messages on close
            if (modalId === 'addSupplierModal') {
                document.getElementById('supplierForm').reset();
                document.getElementById('supplierId').value = '';
                document.getElementById('supplierModalTitle').textContent = 'Add New Supplier';
                document.getElementById('supplierMessage').style.display = 'none';
            } else if (modalId === 'addPoModal') {
                document.getElementById('poForm').reset();
                document.getElementById('poMessage').style.display = 'none';
                // Reset dynamic part fields, keep one empty one
                const poPartsContainer = document.getElementById('poPartsContainer');
                poPartsContainer.innerHTML = `
                    <div class="flex items-center gap-2 mb-2">
                        <select class="po-part-select flex-grow" required>
                            <option value="">Select Part</option>
                        </select>
                        <input type="number" min="1" value="1" placeholder="Qty" class="po-part-qty" required>
                        <button type="button" onclick="removePoPartField(this)" class="remove-part-btn"><i class="fas fa-times"></i></button>
                    </div>
                `;
            }
            applyPermissions(); // Re-apply permissions after closing modal
        }

        window.onclick = function(event) {
            // Close modals if click outside content
            document.querySelectorAll('.modal').forEach(modal => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        }

        // --- Tab Switching Logic ---
        function switchTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.add('hidden');
            });
            // Deactivate all sidebar navigation links
            document.querySelectorAll('.sidebar nav ul li a').forEach(link => {
                link.classList.remove('active');
            });

            // Show the selected tab content
            document.getElementById(tabName + 'Tab').classList.remove('hidden');
            // Activate the corresponding sidebar link
            document.getElementById('sidebar' + tabName.charAt(0).toUpperCase() + tabName.slice(1) + 'Btn').classList.add('active');


            // Fetch and render content for the active tab
            if (tabName === 'inventory') {
                fetchParts();
            } else if (tabName === 'purchaseOrders') {
                fetchPurchaseOrders();
            } else if (tabName === 'suppliers') {
                fetchSuppliers();
            } else if (tabName === 'usage') {
                fetchUsageLogs();
            }
            applyPermissions(); // Apply permissions on tab switch
        }

        // --- API Fetching Functions ---
        async function fetchParts() {
            try {
                const response = await fetch(`${BASE_URL}/api/parts.php`);
                const data = await response.json();
                if (data.success) {
                    partsData = data.data;
                    renderInventory();
                } else {
                    console.error('Error fetching parts:', data.message);
                    document.getElementById('inventoryTableBody').innerHTML = `<tr><td colspan="7" class="text-center py-4 text-red-500">Error loading parts: ${data.message}</td></tr>`;
                }
            } catch (error) {
                console.error('Fetch error:', error);
                document.getElementById('inventoryTableBody').innerHTML = `<tr><td colspan="7" class="text-center py-4 text-red-500">Network error loading parts.</td></tr>`;
            }
        }

        async function fetchSuppliers() {
            try {
                const response = await fetch(`${BASE_URL}/api/suppliers.php`);
                const data = await response.json();
                if (data.success) {
                    suppliersData = data.data;
                    renderSuppliers();
                } else {
                    console.error('Error fetching suppliers:', data.message);
                    document.getElementById('suppliersTableBody').innerHTML = `<tr><td colspan="5" class="text-center py-4 text-red-500">Error loading suppliers: ${data.message}</td></tr>`;
                }
            } catch (error) {
                console.error('Fetch error:', error);
                document.getElementById('suppliersTableBody').innerHTML = `<tr><td colspan="5" class="text-center py-4 text-red-500">Network error loading suppliers.</td></tr>`;
            }
        }

        async function fetchPurchaseOrders() {
            try {
                const response = await fetch(`${BASE_URL}/api/purchase_orders.php`);
                const data = await response.json();
                if (data.success) {
                    purchaseOrdersData = data.data;
                    renderPurchaseOrders();
                } else {
                    console.error('Error fetching purchase orders:', data.message);
                    document.getElementById('purchaseOrdersTableBody').innerHTML = `<tr><td colspan="6" class="text-center py-4 text-red-500">Error loading purchase orders: ${data.message}</td></tr>`;
                }
            } catch (error) {
                console.error('Fetch error:', error);
                document.getElementById('purchaseOrdersTableBody').innerHTML = `<tr><td colspan="6" class="text-center py-4 text-red-500">Network error loading purchase orders.</td></tr>`;
            }
        }

        async function fetchUsageLogs() {
            try {
                const response = await fetch(`${BASE_URL}/api/usage_logs.php`);
                const data = await response.json();
                if (data.success) {
                    usageLogsData = data.data;
                    renderUsage();
                } else {
                    console.error('Error fetching usage logs:', data.message);
                    document.getElementById('usageTableBody').innerHTML = `<tr><td colspan="6" class="text-center py-4 text-red-500">Error loading usage logs: ${data.message}</td></tr>`;
                }
            } catch (error) {
                console.error('Fetch error:', error);
                document.getElementById('usageTableBody').innerHTML = `<tr><td colspan="6" class="text-center py-4 text-red-500">Network error loading usage logs.</td></tr>`;
            }
        }

        async function refreshDashboardStats() {
            try {
                const response = await fetch(`${BASE_URL}/api/parts.php`); // Get all parts for stats calculation
                const data = await response.json();
                if (data.success) {
                    const allParts = data.data;
                    let totalPartsItems = allParts.length;
                    let totalPartsQuantity = allParts.reduce((sum, part) => sum + parseInt(part.stock), 0);
                    let lowStockAlerts = allParts.filter(part => parseInt(part.stock) < parseInt(part.min_stock)).length;

                    const poResponse = await fetch(`${BASE_URL}/api/purchase_orders.php`);
                    const poData = await poResponse.json();
                    let pendingPurchaseOrders = 0;
                    let pendingPoValue = 0;
                    if(poData.success) {
                        const pendingPOs = poData.data.filter(po => po.status === 'Pending');
                        pendingPurchaseOrders = pendingPOs.length;
                        pendingPoValue = pendingPOs.reduce((sum, po) => sum + parseFloat(po.total_cost), 0);
                    }

                    document.getElementById('stat-total-parts-items').textContent = totalPartsItems;
                    document.getElementById('stat-total-parts-quantity').textContent = totalPartsQuantity;
                    document.getElementById('stat-low-stock-alerts').textContent = lowStockAlerts;
                    document.getElementById('stat-pending-pos').textContent = pendingPurchaseOrders;
                    document.getElementById('stat-pending-po-value').textContent = `${pendingPoValue.toFixed(2)}`; // Removed dollar sign for display

                    // Also update the low stock list in inventory tab
                    renderLowStockAlerts(allParts);

                } else {
                    console.error('Error refreshing stats:', data.message);
                }
            } catch (error) {
                console.error('Error refreshing stats:', error);
            }
        }

        // --- Rendering Functions ---
        function renderInventory() {
            const tableBody = document.getElementById('inventoryTableBody');
            tableBody.innerHTML = ''; // Clear existing rows

            // Removed searchTerm and filtering logic as per request
            const filteredParts = partsData; // No filtering

            if (filteredParts.length === 0) {
                tableBody.innerHTML = `<tr><td colspan="7" class="text-center py-4">No parts found.</td></tr>`;
                return;
            }

            filteredParts.forEach(part => {
                const row = tableBody.insertRow();
                // Add low-stock class if applicable
                if (parseInt(part.stock) < parseInt(part.min_stock)) {
                    row.classList.add('low-stock');
                }
                row.innerHTML = `
                    <td class="px-6 py-4 whitespace-nowrap">${part.name}</td>
                    <td class="px-6 py-4 whitespace-nowrap">${part.sku}</td>
                    <td class="px-6 py-4 whitespace-nowrap">${part.stock} ${part.unit || ''}</td>
                    <td class="px-6 py-4 whitespace-nowrap">${part.min_stock}</td>
                    <td class="px-6 py-4 whitespace-nowrap">${parseFloat(part.price).toFixed(2)}</td>
                    <td class="px-6 py-4 whitespace-nowrap">${part.location || 'N/A'}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <button onclick="editPart('${part.id}')" class="edit-part-btn bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1 rounded-md text-xs shadow-sm transition"><i class="fas fa-edit"></i> Edit</button>
                        <button onclick="deletePart('${part.id}')" class="delete-part-btn bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded-md text-xs shadow-sm transition ml-2"><i class="fas fa-trash-alt"></i> Delete</button>
                    </td>
                `;
            });
            renderLowStockAlerts(partsData); // Re-render low stock list on inventory change/filter
            applyPermissions(); // Apply permissions after rendering
        }

        function renderLowStockAlerts(parts) {
            const lowStockList = document.getElementById('lowStockList');
            lowStockList.innerHTML = '';
            let lowStockCount = 0;

            parts.filter(part => parseInt(part.stock) < parseInt(part.min_stock)).forEach(part => {
                const listItem = document.createElement('li');
                listItem.textContent = `${part.name} (SKU: ${part.sku}) - Current: ${part.stock}, Min: ${part.min_stock}`;
                lowStockList.appendChild(listItem);
                lowStockCount++;
            });

            document.getElementById('lowStockAlerts').style.display = lowStockCount > 0 ? 'block' : 'none';
        }


        function renderSuppliers() {
            const tableBody = document.getElementById('suppliersTableBody');
            tableBody.innerHTML = '';

            // Removed searchTerm and filtering logic as per request
            const filteredSuppliers = suppliersData; // No filtering

            if (filteredSuppliers.length === 0) {
                tableBody.innerHTML = `<tr><td colspan="5" class="text-center py-4">No suppliers found.</td></tr>`;
                return;
            }

            filteredSuppliers.forEach(supplier => {
                const row = tableBody.insertRow();
                row.innerHTML = `
                    <td class="px-6 py-4 whitespace-nowrap">${supplier.name}</td>
                    <td class="px-6 py-4 whitespace-nowrap">${supplier.contact_person || 'N/A'}</td>
                    <td class="px-6 py-4 whitespace-nowrap">${supplier.phone || 'N/A'}</td>
                    <td class="px-6 py-4 whitespace-nowrap">${supplier.email || 'N/A'}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <button onclick="editSupplier('${supplier.id}')" class="edit-supplier-btn bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1 rounded-md text-xs shadow-sm transition"><i class="fas fa-edit"></i> Edit</button>
                        <button onclick="deleteSupplier('${supplier.id}')" class="delete-supplier-btn bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded-md text-xs shadow-sm transition ml-2"><i class="fas fa-trash-alt"></i> Delete</button>
                    </td>
                `;
            });
            applyPermissions(); // Apply permissions after rendering
        }

        function renderPurchaseOrders() {
            const tableBody = document.getElementById('purchaseOrdersTableBody');
            tableBody.innerHTML = '';

            // Removed searchTerm and filtering logic as per request
            const filteredPOs = purchaseOrdersData; // No filtering

            if (filteredPOs.length === 0) {
                tableBody.innerHTML = `<tr><td colspan="6" class="text-center py-4">No purchase orders found.</td></tr>`;
                return;
            }

            filteredPOs.forEach(po => {
                const supplierName = suppliersData.find(s => s.id === po.supplier_id)?.name || 'Unknown Supplier';
                const row = tableBody.insertRow();
                row.innerHTML = `
                    <td class="px-6 py-4 whitespace-nowrap">${po.id}</td>
                    <td class="px-6 py-4 whitespace-nowrap">${supplierName}</td>
                    <td class="px-6 py-4 whitespace-nowrap">${new Date(po.date_created).toLocaleDateString()}</td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <select onchange="updatePoStatus('${po.id}', this.value)" class="po-status-select p-1 border border-gray-300 rounded-md shadow-sm ${po.status === 'Pending' ? 'status-pending' : (po.status === 'Ordered' ? 'status-ordered' : (po.status === 'Received' ? 'status-received' : 'status-cancelled'))}">
                            <option value="Pending" ${po.status === 'Pending' ? 'selected' : ''}>Pending</option>
                            <option value="Ordered" ${po.status === 'Ordered' ? 'selected' : ''}>Ordered</option>
                            <option value="Received" ${po.status === 'Received' ? 'selected' : ''}>Received</option>
                            <option value="Cancelled" ${po.status === 'Cancelled' ? 'selected' : ''}>Cancelled</option>
                        </select>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">${parseFloat(po.total_cost).toFixed(2)}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <button onclick="viewPoDetails('${po.id}')" class="view-po-details-btn bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded-md text-xs shadow-sm transition"><i class="fas fa-info-circle"></i> Details</button>
                        <button onclick="deletePurchaseOrder('${po.id}')" class="delete-po-btn bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded-md text-xs shadow-sm transition ml-2"><i class="fas fa-trash-alt"></i> Delete</button>
                    </td>
                `;
            });
            applyPermissions(); // Apply permissions after rendering
        }

        function renderUsage() {
            const tableBody = document.getElementById('usageTableBody');
            tableBody.innerHTML = '';

            // Removed searchTerm and filtering logic as per request
            const filteredUsage = usageLogsData; // No filtering

            if (filteredUsage.length === 0) {
                tableBody.innerHTML = `<tr><td colspan="6" class="text-center py-4">No usage logs found.</td></tr>`;
                return;
            }

            filteredUsage.forEach(log => {
                const row = tableBody.insertRow();
                const quantityDisplay = parseInt(log.quantity_change) < 0 ?
                    `<span class="text-red-600">${Math.abs(log.quantity_change)} (Used)</span>` :
                    `<span class="text-green-600">${log.quantity_change} (Received)</span>`;
                const sourceId = log.job_card_id ? `Job: ${log.job_card_id}` : (log.purchase_order_id ? `PO: ${log.purchase_order_id}` : 'N/A');

                row.innerHTML = `
                    <td class="px-6 py-4 whitespace-nowrap">${log.part_name} (${log.part_sku})</td>
                    <td class="px-6 py-4 whitespace-nowrap">${sourceId}</td>
                    <td class="px-6 py-4 whitespace-nowrap">${quantityDisplay}</td>
                    <td class="px-6 py-4 whitespace-nowrap">${log.log_type.charAt(0).toUpperCase() + log.log_type.slice(1)}</td>
                    <td class="px-6 py-4 whitespace-nowrap">${new Date(log.date_logged).toLocaleDateString()}</td>
                    <td class="px-6 py-4 whitespace-nowrap">${log.logged_by_user_name || 'System'}</td>
                `;
            });
            applyPermissions(); // Apply permissions after rendering
        }

        // --- Apply Permissions to UI Elements ---
        function applyPermissions() {
            // Inventory Tab
            const addPartBtn = document.getElementById('addPartBtn');
            if (addPartBtn) addPartBtn.disabled = !CAN_MANAGE_INVENTORY;


            document.querySelectorAll('.edit-part-btn').forEach(btn => {
                btn.disabled = !CAN_MANAGE_INVENTORY;
                btn.classList.toggle('disabled', !CAN_MANAGE_INVENTORY);
            });
            document.querySelectorAll('.delete-part-btn').forEach(btn => {
                btn.disabled = !CAN_MANAGE_INVENTORY;
                btn.classList.toggle('disabled', !CAN_MANAGE_INVENTORY);
            });

            // Suppliers Tab
            const addSupplierBtn = document.getElementById('addSupplierBtn');
            const saveSupplierBtn = document.getElementById('saveSupplierBtn');
            if (addSupplierBtn) addSupplierBtn.disabled = !CAN_MANAGE_INVENTORY;
            if (saveSupplierBtn) saveSupplierBtn.disabled = !CAN_MANAGE_INVENTORY;

            document.querySelectorAll('.edit-supplier-btn').forEach(btn => {
                btn.disabled = !CAN_MANAGE_INVENTORY;
                btn.classList.toggle('disabled', !CAN_MANAGE_INVENTORY);
            });
            document.querySelectorAll('.delete-supplier-btn').forEach(btn => {
                btn.disabled = !CAN_MANAGE_INVENTORY;
                btn.classList.toggle('disabled', !CAN_MANAGE_INVENTORY);
            });

            // Purchase Orders Tab
            const createPoBtn = document.getElementById('createPoBtn');
            const createPoFormBtn = document.getElementById('createPoFormBtn');
            const addAnotherPartBtn = document.getElementById('addAnotherPartBtn'); // Button inside PO modal
            if (createPoBtn) createPoBtn.disabled = !CAN_REQUEST_PARTS;
            if (createPoFormBtn) createPoFormBtn.disabled = !CAN_REQUEST_PARTS;
            if (addAnotherPartBtn) addAnotherPartBtn.disabled = !CAN_REQUEST_PARTS;

            document.querySelectorAll('.po-status-select').forEach(select => {
                select.disabled = !CAN_MANAGE_INVENTORY; // Assuming managing inventory includes updating PO status
                select.classList.toggle('disabled', !CAN_MANAGE_INVENTORY);
            });
            document.querySelectorAll('.delete-po-btn').forEach(btn => {
                btn.disabled = !CAN_MANAGE_INVENTORY;
                btn.classList.toggle('disabled', !CAN_MANAGE_INVENTORY);
            });

            // Make sure required fields are not affected in forms
            // If a form is shown but its save button is disabled, the user understands why.
        }

        // --- Inventory Actions (remaining for edit/delete, add is moved) ---

        // editPart and deletePart functions remain as they interact with the API directly
        async function editPart(id) {
            if (!CAN_MANAGE_INVENTORY) {
                alert('You do not have permission to manage inventory (Edit Parts).'); // Replace with custom modal
                return;
            }
            alert('Editing part functionality for ID: ' + id + ' would typically open an edit form or redirect to a dedicated page.');
            // Example: window.location.href = `${BASE_URL}/edit_part_for_parts_manager.php?id=${id}`;
        }

        async function deletePart(id) {
            if (!CAN_MANAGE_INVENTORY) {
                alert('You do not have permission to manage inventory (Delete Parts).');
                return;
            }
            if (!confirm('Are you sure you want to delete this part?')) {
                return;
            }
            try {
                const response = await fetch(`${BASE_URL}/api/parts.php?id=${id}`, {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ csrf_token: CSRF_TOKEN })
                });
                const data = await response.json();
                if (data.success) {
                    alert(data.message);
                    fetchParts();
                    refreshDashboardStats();
                } else {
                    alert('Error deleting part: ' + (data.message || ''));
                }
            } catch (error) {
                console.error('Error deleting part:', error);
                alert('An error occurred during deletion. Please try again. Details: ' + error.message);
            }
        }

        // Removed filterInventory() as search is removed

        // --- Supplier Actions ---
        function openAddSupplierModal() {
            if (!CAN_MANAGE_INVENTORY) {
                alert('You do not have permission to manage suppliers (Add/Edit Suppliers).');
                return;
            }
            document.getElementById('supplierModalTitle').textContent = 'Add New Supplier';
            document.getElementById('supplierId').value = '';
            document.getElementById('supplierForm').reset();
            openModal('addSupplierModal');
        }

        document.getElementById('supplierForm').addEventListener('submit', async function(event) {
            event.preventDefault();
            if (!CAN_MANAGE_INVENTORY) {
                showMessage(document.getElementById('supplierMessage'), 'You do not have permission to manage suppliers.', 'error');
                return;
            }
            const form = event.target;
            const messageElement = document.getElementById('supplierMessage');
            const id = document.getElementById('supplierId').value;
            const method = id ? 'PUT' : 'POST';
            const url = id ? `${BASE_URL}/api/suppliers.php?id=${id}` : `${BASE_URL}/api/suppliers.php`;

            const formData = {
                id: id,
                name: form.name.value,
                contactPerson: form.contactPerson.value,
                phone: form.phone.value,
                email: form.email.value
            };

            try {
                const response = await fetch(url, {
                    method: method,
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(formData)
                });
                const data = await response.json();

                if (data.success) {
                    showMessage(messageElement, data.message, 'success');
                    closeModal('addSupplierModal');
                    fetchSuppliers(); // Refresh data
                    populatePoDropdowns(); // Update supplier list in PO form
                } else {
                    showMessage(messageElement, data.message, 'error');
                }
            } catch (error) {
                console.error('Error saving supplier:', error);
                showMessage(messageElement, 'An unexpected error occurred. Please try again.', 'error');
            }
        });

        async function editSupplier(id) {
            if (!CAN_MANAGE_INVENTORY) {
                alert('You do not have permission to manage suppliers (Edit Suppliers).');
                return;
            }
            try {
                const response = await fetch(`${BASE_URL}/api/suppliers.php?id=${id}`);
                const data = await response.json();
                if (data.success && data.data.length > 0) {
                    const supplier = data.data[0];
                    document.getElementById('supplierModalTitle').textContent = 'Edit Supplier';
                    document.getElementById('supplierId').value = supplier.id;
                    document.getElementById('supplierName').value = supplier.name;
                    document.getElementById('supplierContactPerson').value = supplier.contact_person;
                    document.getElementById('supplierPhone').value = supplier.phone;
                    document.getElementById('supplierEmail').value = supplier.email;
                    openModal('addSupplierModal');
                } else {
                    alert('Supplier not found or error fetching details: ' + (data.message || ''));
                }
            } catch (error) {
                console.error('Error editing supplier:', error);
                alert('Network error while fetching supplier details.');
            }
        }

        async function deleteSupplier(id) {
            if (!CAN_MANAGE_INVENTORY) {
                alert('You do not have permission to manage suppliers (Delete Suppliers).');
                return;
            }
            if (!confirm('Are you sure you want to delete this supplier? This will also remove associated Purchase Orders.')) {
                return;
            }
            try {
                const response = await fetch(`${BASE_URL}/api/suppliers.php?id=${id}`, {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ csrf_token: CSRF_TOKEN })
                });
                const data = await response.json();
                if (data.success) {
                    alert(data.message);
                    fetchSuppliers();
                    fetchPurchaseOrders(); // POs might be affected
                } else {
                    alert('Error deleting supplier: ' + (data.message || ''));
                }
            }
            catch (error) {
                console.error('Error deleting supplier:', error);
                alert('Network error while deleting supplier.');
            }
        }

        // Removed filterSuppliers() as search is removed

        // --- Purchase Order Actions ---
        async function populatePoDropdowns() {
            // Populate Supplier Dropdown
            const supplierSelect = document.getElementById('poSupplier');
            supplierSelect.innerHTML = '<option value="">Select a Supplier</option>';
            // Ensure suppliersData is loaded
            if (suppliersData.length === 0) {
                await fetchSuppliers(); // Fetch if not already loaded
            }
            suppliersData.forEach(supplier => {
                const option = document.createElement('option');
                option.value = supplier.id;
                option.textContent = supplier.name;
                supplierSelect.appendChild(option);
            });

            // Populate Parts Dropdowns for all current part fields
            document.querySelectorAll('.po-part-select').forEach(select => {
                const currentSelectedPartId = select.value; // Preserve current selection if editing
                select.innerHTML = '<option value="">Select Part</option>';
                // Ensure partsData is loaded
                if (partsData.length === 0) {
                    fetchParts(); // Fetch but don't await, it will re-render
                }
                partsData.forEach(part => {
                    const option = document.createElement('option');
                    option.value = part.id;
                    option.textContent = `${part.name} (SKU: ${part.sku}) - Stock: ${part.stock} ${part.unit || ''}`; // Show unit in dropdown
                    if (part.id === currentSelectedPartId) {
                        option.selected = true;
                    }
                    select.appendChild(option);
                });
            });
        }

        function addPoPartField() {
            if (!CAN_REQUEST_PARTS) {
                showMessage(document.getElementById('poMessage'), 'You do not have permission to request parts (Add Parts to PO).', 'error');
                return;
            }
            const container = document.getElementById('poPartsContainer');
            const newField = document.createElement('div');
            newField.className = 'flex items-center gap-2 mb-2';
            newField.innerHTML = `
                <select class="po-part-select flex-grow" required>
                    <option value="">Select Part</option>
                </select>
                <input type="number" min="1" value="1" placeholder="Qty" class="po-part-qty" required>
                <button type="button" onclick="removePoPartField(this)" class="remove-part-btn"><i class="fas fa-times"></i></button>
            `;
            container.appendChild(newField);
            populatePoDropdowns(); // Re-populate parts dropdown in new field
            applyPermissions(); // Re-apply permissions to new button
        }

        function removePoPartField(button) {
            const container = document.getElementById('poPartsContainer');
            if (container.children.length > 1) { // Ensure at least one field remains
                button.closest('.flex').remove();
            } else {
                showMessage(document.getElementById('poMessage'), 'A Purchase Order must have at least one part.', 'error');
            }
        }

        document.getElementById('poForm').addEventListener('submit', async function(event) {
            event.preventDefault();
            if (!CAN_REQUEST_PARTS) {
                showMessage(document.getElementById('poMessage'), 'You do not have permission to request parts.', 'error');
                return;
            }
            const form = event.target;
            const messageElement = document.getElementById('poMessage');

            const supplierId = form.poSupplier.value;
            if (!supplierId) {
                showMessage(messageElement, 'Please select a supplier.', 'error');
                return;
            }

            const poItems = [];
            let totalCost = 0;
            let isValid = true;

            document.querySelectorAll('#poPartsContainer .flex').forEach(itemDiv => {
                const partId = itemDiv.querySelector('.po-part-select').value;
                const quantity = parseInt(itemDiv.querySelector('.po-part-qty').value);

                if (!partId) {
                    showMessage(messageElement, 'Please select a part for each item.', 'error');
                    isValid = false;
                    return;
                }
                if (quantity <= 0 || isNaN(quantity)) {
                    showMessage(messageElement, 'Quantity for each part must be a positive number.', 'error');
                    isValid = false;
                    return;
                }

                const part = partsData.find(p => p.id === partId);
                if (part) {
                    poItems.push({ partId, quantity, unitPrice: parseFloat(part.price) });
                    totalCost += quantity * parseFloat(part.price);
                } else {
                    showMessage(messageElement, 'Selected part not found in inventory. Please refresh data.', 'error');
                    isValid = false;
                    return;
                }
            });

            if (!isValid || poItems.length === 0) {
                return; // Error message already shown by inner loop
            }

            const formData = {
                supplierId: supplierId,
                items: poItems,
                totalCost: totalCost,
                created_by: CURRENT_USER_ID // Pass current user ID for logging
            };

            try {
                const response = await fetch(`${BASE_URL}/api/purchase_orders.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(formData)
                });
                const data = await response.json();

                if (data.success) {
                    showMessage(messageElement, data.message, 'success');
                    closeModal('addPoModal');
                    fetchPurchaseOrders(); // Refresh PO list
                    refreshDashboardStats(); // Update PO stats
                } else {
                    showMessage(messageElement, data.message, 'error');
                }
            } catch (error) {
                console.error('Error creating PO:', error);
                showMessage(messageElement, 'An unexpected error occurred while creating PO. Please try again.', 'error');
            }
        });

        async function updatePoStatus(poId, newStatus) {
            if (!CAN_MANAGE_INVENTORY) { // Assuming updating PO status is part of managing inventory
                alert('You do not have permission to update purchase order status.');
                fetchPurchaseOrders(); // Re-fetch to revert UI
                return;
            }

            if (!confirm(`Are you sure you want to change status of PO ${poId} to ${newStatus}?`)) {
                fetchPurchaseOrders(); // Re-fetch to revert UI
                return;
            }

            try {
                const response = await fetch(`${BASE_URL}/api/purchase_orders.php`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: poId, status: newStatus, csrf_token: CSRF_TOKEN })
                });
                const data = await response.json();
                if (data.success) {
                    alert(data.message);
                    fetchPurchaseOrders(); // Refresh POs
                    fetchParts(); // Refresh parts to reflect stock changes if status was 'Received'
                    fetchUsageLogs(); // Refresh usage logs
                    refreshDashboardStats(); // Update stats
                } else {
                    alert('Error updating PO status: ' + (data.message || ''));
                    fetchPurchaseOrders(); // Revert UI on error
                }
            } catch (error) {
                console.error('Error updating PO status:', error);
                alert('Network error while updating PO status.');
                fetchPurchaseOrders(); // Revert UI on network error
            }
        }

        async function viewPoDetails(poId) {
            try {
                const response = await fetch(`${BASE_URL}/api/purchase_orders.php?id=${poId}`);
                const data = await response.json();
                if (data.success && data.data.length > 0) {
                    const po = data.data[0];
                    const supplier = suppliersData.find(s => s.id === po.supplier_id);

                    let detailsHtml = `
                        <p><strong>PO ID:</strong> ${po.id}</p>
                        <p><strong>Supplier:</strong> ${supplier ? supplier.name : 'N/A'}</p>
                        <p><strong>Date Created:</strong> ${new Date(po.date_created).toLocaleString()}</p>
                        <p><strong>Status:</strong> <span class="status-${po.status.toLowerCase()}">${po.status}</span></p>
                        <p><strong>Total Cost:</strong> ${parseFloat(po.total_cost).toFixed(2)}</p>
                        <h4 class="font-semibold text-gray-800 mt-4 mb-2">Items:</h4>
                        <ul class="list-disc pl-5 text-gray-700">
                    `;
                    if (po.items && po.items.length > 0) {
                        po.items.forEach(item => {
                            detailsHtml += `<li>${item.part_name} (SKU: ${item.part_sku}) - Qty: ${item.quantity} ${item.unit || ''}, Unit Price: ${parseFloat(item.unit_price).toFixed(2)}</li>`;
                        });
                    } else {
                        detailsHtml += `<li>No items listed for this PO.</li>`;
                    }
                    detailsHtml += `</ul>`;

                    document.getElementById('poDetailsContent').innerHTML = detailsHtml;
                    openModal('poDetailsModal');
                } else {
                    alert('Purchase Order not found or error fetching details: ' + (data.message || ''));
                }
            } catch (error) {
                console.error('Error fetching PO details:', error);
                alert('Network error while fetching PO details.');
            }
        }

        async function deletePurchaseOrder(poId) {
            if (!CAN_MANAGE_INVENTORY) {
                alert('You do not have permission to delete purchase orders.');
                return;
            }
            if (!confirm('Are you sure you want to delete this purchase order? This action cannot be undone.')) {
                return;
            }
            try {
                const response = await fetch(`${BASE_URL}/api/purchase_orders.php?id=${poId}`, {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ csrf_token: CSRF_TOKEN })
                });
                const data = await response.json();
                if (data.success) {
                    alert(data.message);
                    fetchPurchaseOrders();
                    refreshDashboardStats();
                } else {
                    alert('Error deleting purchase order: ' + (data.message || ''));
                }
            } catch (error) {
                console.error('Error deleting purchase order:', error);
                alert('Network error while deleting purchase order.');
            }
        }

        // Removed filterPurchaseOrders() as search is removed

        // Removed filterUsage() as search is removed

        // Initialize dashboard on load
        document.addEventListener('DOMContentLoaded', () => {
            // Initial data fetch and render for the default tab (Inventory)
            switchTab('inventory');
            refreshDashboardStats(); // Fetch and display initial stats
            applyPermissions(); // Apply permissions on initial load
        });
    </script>
</body>
</html>