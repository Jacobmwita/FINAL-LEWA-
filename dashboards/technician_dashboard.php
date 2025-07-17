<?php
ob_start(); // Start output buffering at the very beginning
session_start();

// BASE_URL definition - crucial for consistent linking
// This ensures that all paths (e.g., to API endpoints, other dashboards) are correctly formed.
if (!defined('BASE_URL')) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    // Adjust 'lewa' if your application is installed in a different subdirectory.
    // For example, if your app is directly under the document root, it would be define('BASE_URL', "{$protocol}://{$host}");
    define('BASE_URL', "{$protocol}://{$host}/lewa");
}

// SECURITY CHECKUP: Ensures only authorized user types can access this dashboard.
// This array defines which user roles are permitted.
$allowed_access_roles = ['mechanic', 'workshop_manager', 'admin', 'administrator', 'manager'];

if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['user_type'] ?? ''), $allowed_access_roles)) {
    // If the user is not logged in or their user type is not allowed, redirect them to the login page.
    header("Location: ../user_login.php"); // Path adjusted relative to this file (dashboards/technician_dashboard.php)
    exit();
}

// Include the database connection file.
include '../db_connect.php'; // Path adjusted relative to this file

// DATABASE CONNECTION VALIDATION: Critical check to ensure the database connection is established.
if (!isset($conn) || $conn->connect_error) {
    // Log a fatal error if the connection fails, providing details for debugging.
    error_log("FATAL ERROR: Database connection failed in " . __FILE__ . ": " . ($conn->connect_error ?? 'Connection object not set.'));
    // Set a user-friendly error message in the session and redirect to the login page.
    $_SESSION['error_message'] = "A critical system error occurred. Please try again later.";
    header("Location: ../user_login.php"); // Redirect to login page
    exit();
}

// --- USER DETAILS (Mechanic or Admin/Manager) ---
// Retrieve current user's ID and type from the session.
$current_user_id = $_SESSION['user_id'];
$current_user_type = strtolower($_SESSION['user_type']);
$current_user_full_name = 'User'; // Default value for user's full name
$current_user_initial = '?'; // Default initial for user avatar

// Prepare and execute a query to fetch the full name and username of the current user.
$user_query = "SELECT full_name, username FROM users WHERE user_id = ?";
$stmt = $conn->prepare($user_query);

if ($stmt === false) {
    // Log an error if the statement preparation fails.
    error_log("Failed to prepare statement for user details: " . $conn->error);
    $_SESSION['error_message'] = "Error fetching user details. Please try again.";
    header("Location: ../user_login.php"); // Redirect to login as user details are fundamental
    exit();
}

// Bind the current user's ID to the prepared statement and execute it.
if (!$stmt->bind_param("i", $current_user_id) || !$stmt->execute()) {
    // Log an error if the statement execution fails.
    error_log("Failed to execute statement for user details: " . $stmt->error);
    $stmt->close(); // Close the statement
    $_SESSION['error_message'] = "Error fetching user details. Please try again.";
    header("Location: ../user_login.php"); // Redirect to login
    exit();
}

$result = $stmt->get_result();
if ($result && $user_data = $result->fetch_assoc()) {
    // Populate user details, preferring full_name over username if available.
    $current_user_full_name = htmlspecialchars($user_data['full_name'] ?? $user_data['username']);
    $current_user_initial = strtoupper(substr($user_data['full_name'] ?? $user_data['username'], 0, 1));
} else {
    // This scenario indicates a potential data inconsistency or security issue:
    // a user ID exists in the session but not in the database.
    error_log("SECURITY ALERT: User with user_id {$current_user_id} not found in DB or full_name is null.");
    $_SESSION['error_message'] = "Your user account could not be found. Please log in again.";
    header("Location: ../user_login.php"); // Force re-login
    exit();
}
$stmt->close(); // Close the statement after use

// --- Fetch Dashboard Statistics (PHP direct fetch) ---
date_default_timezone_set('Africa/Nairobi'); // Ensure consistent timezone for date calculations

// New Jobs Today
$new_jobs_today_count = 0;
$today = date('Y-m-d');
$stmt_new_jobs = $conn->prepare("SELECT COUNT(*) AS count FROM job_cards WHERE DATE(created_at) = ?");
if ($stmt_new_jobs) {
    $stmt_new_jobs->bind_param("s", $today);
    $stmt_new_jobs->execute();
    $result_new_jobs = $stmt_new_jobs->get_result()->fetch_assoc();
    $new_jobs_today_count = $result_new_jobs['count'];
    $stmt_new_jobs->close();
} else {
    error_log("Error fetching new jobs today count: " . $conn->error);
}

// In Progress Jobs
$in_progress_jobs_count = 0;
$stmt_in_progress = $conn->prepare("SELECT COUNT(*) AS count FROM job_cards WHERE status IN ('in_progress', 'assigned')");
if ($stmt_in_progress) {
    $stmt_in_progress->execute();
    $result_in_progress = $stmt_in_progress->get_result()->fetch_assoc();
    $in_progress_jobs_count = $result_in_progress['count'];
    $stmt_in_progress->close();
} else {
    error_log("Error fetching in progress jobs count: " . $conn->error);
}

// Completed This Week
$completed_this_week_count = 0;
$start_of_week = date('Y-m-d 00:00:00', strtotime('last monday'));
$end_of_week = date('Y-m-d 23:59:59', strtotime('this sunday'));
$stmt_completed_week = $conn->prepare("SELECT COUNT(*) AS count FROM job_cards WHERE status = 'completed' AND completed_at BETWEEN ? AND ?");
if ($stmt_completed_week) {
    $stmt_completed_week->bind_param("ss", $start_of_week, $end_of_week);
    $stmt_completed_week->execute();
    $result_completed_week = $stmt_completed_week->get_result()->fetch_assoc();
    $completed_this_week_count = $result_completed_week['count'];
    $stmt_completed_week->close();
} else {
    error_log("Error fetching completed this week count: " . $conn->error);
}

// Pending Assignments (only for managers/admins)
$pending_assignments_count = 0;
if (in_array($current_user_type, ['workshop_manager', 'admin', 'administrator', 'manager'])) {
    $stmt_pending_assignments = $conn->prepare("SELECT COUNT(*) AS count FROM job_cards WHERE status = 'pending'");
    if ($stmt_pending_assignments) {
        $stmt_pending_assignments->execute();
        $result_pending_assignments = $stmt_pending_assignments->get_result()->fetch_assoc();
        $pending_assignments_count = $result_pending_assignments['count'];
        $stmt_pending_assignments->close();
    } else {
        error_log("Error fetching pending assignments count: " . $conn->error);
    }
}


// --- PHP Logic for AJAX Endpoints (Job Details, Updates, Requests) ---
// This block handles AJAX requests for dynamic content and actions within the dashboard.
if (isset($_GET['action'])) {
    header('Content-Type: application/json'); // Set content type to JSON for AJAX responses
    $response = ['success' => false, 'message' => 'An unknown error occurred.']; // Default error response structure

    try {
        // CSRF token check for all POST requests to prevent Cross-Site Request Forgery.
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                throw new Exception('CSRF token mismatch.');
            }
        }

        // Handle different actions based on the 'action' GET parameter.
        switch ($_GET['action']) {
            case 'get_job_details':
                $job_card_id = isset($_GET['job_id']) ? intval($_GET['job_id']) : 0;
                if ($job_card_id > 0) {
                    $job_details = null;
                    $job_updates = [];
                    $parts_inventory = [];
                    $parts_used = [];

                    // SQL query to fetch detailed job card information.
                    // It joins with 'vehicles' and 'users' (for the driver who created the job).
                    $sql_job = "SELECT
                                        jc.job_card_id,
                                        v.registration_number, v.make, v.model, v.year, v.v_milage AS mileage,
                                        d.full_name AS driver_full_name,
                                        d.phone_number AS driver_phone, d.email AS driver_email,
                                        jc.description AS service_type, jc.description AS description_of_problem, jc.status AS current_status,
                                        jc.completed_at AS estimated_completion_date, jc.created_at,
                                        jc.labor_cost
                                    FROM
                                        job_cards jc
                                    LEFT JOIN vehicles v ON jc.vehicle_id = v.vehicle_id
                                    LEFT JOIN users d ON jc.created_by_user_id = d.user_id
                                    WHERE jc.job_card_id = ?";

                    // If the current user is a 'mechanic', restrict job details to only jobs assigned to them.
                    // For other roles (managers, admins), they can view any job.
                    if ($current_user_type === 'mechanic') {
                        $sql_job .= " AND jc.assigned_to_mechanic_id = ?"; // Filter by assigned mechanic
                        $stmt_job = $conn->prepare($sql_job);
                        $stmt_job->bind_param("ii", $job_card_id, $current_user_id);
                    } else {
                        $stmt_job = $conn->prepare($sql_job);
                        $stmt_job->bind_param("i", $job_card_id);
                    }

                    if ($stmt_job) {
                        $stmt_job->execute();
                        $result_job = $stmt_job->get_result();
                        $job_details = $result_job->fetch_assoc();
                        $stmt_job->close();
                    } else {
                        error_log("Failed to prepare job details statement: " . $conn->error);
                    }

                    if ($job_details) {
                        // Fetch job updates (work logs, status changes, media uploads) for the job card.
                        // Joins with 'users' to get the full name of the mechanic who logged the update.
                        $sql_updates = "SELECT jcu.description, jcu.timestamp, jcu.update_type, u.full_name AS mechanic_name, jcu.photo_path
                                            FROM job_card_updates jcu
                                            LEFT JOIN users u ON jcu.mechanic_id = u.user_id -- Correctly links to the user who made the update
                                            WHERE jcu.job_card_id = ? ORDER BY jcu.timestamp DESC";
                        if ($stmt_updates = $conn->prepare($sql_updates)) {
                            $stmt_updates->bind_param("i", $job_card_id);
                            $stmt_updates->execute();
                            $result_updates = $stmt_updates->get_result();
                            while ($row = $result_updates->fetch_assoc()) {
                                $job_updates[] = $row;
                            }
                            $stmt_updates->close();
                        } else {
                            error_log("Failed to prepare job updates statement: " . $conn->error);
                        }

                        // Fetch parts used for this specific job, including their names, numbers, and unit prices from inventory.
                        $sql_parts_used = "SELECT jp.quantity_used, inv.item_name, inv.item_number, inv.price AS unit_price
                                            FROM job_parts jp
                                            JOIN inventory inv ON jp.item_id = inv.item_id
                                            WHERE jp.job_card_id = ?";
                        if ($stmt_parts_used = $conn->prepare($sql_parts_used)) {
                            $stmt_parts_used->bind_param("i", $job_card_id);
                            $stmt_parts_used->execute();
                            $result_parts_used = $stmt_parts_used->get_result();
                            while ($row = $result_parts_used->fetch_assoc()) {
                                $parts_used[] = $row;
                            }
                            $stmt_parts_used->close();
                        } else {
                            error_log("Failed to prepare parts used statement: " . $conn->error);
                        }

                        // Fetch all available parts from inventory to populate dropdowns in the modal forms.
                        $sql_parts = "SELECT item_id, item_name, quantity AS current_stock, item_number FROM inventory ORDER BY item_name ASC";
                        $result_parts = $conn->query($sql_parts);
                        if ($result_parts) {
                            while ($row = $result_parts->fetch_assoc()) {
                                $parts_inventory[] = $row;
                            }
                        } else {
                            error_log("Failed to fetch parts inventory: " . $conn->error);
                        }

                        // Build the HTML content for the modal dynamically.
                        // This includes job details, forms for updates, and history sections.
                        $html_content = '';
                        $html_content .= '<div class="job-details-section">';
                        $html_content .= '<h3>Job Card #' . htmlspecialchars($job_details['job_card_id']) . ' Details</h3>';
                        $html_content .= '<p><strong>Vehicle:</strong> ' . htmlspecialchars($job_details['year'] . ' ' . $job_details['make'] . ' ' . $job_details['model'] . ' (' . ($job_details['registration_number'] ?? 'N/A') . ')') . '</p>';
                        $html_content .= '<p><strong>Mileage:</strong> ' . htmlspecialchars($job_details['mileage'] ?? 'N/A') . ' km</p>';
                        $html_content .= '<p><strong>Driver:</strong> ' . htmlspecialchars($job_details['driver_full_name'] ?? 'N/A') . ' (' . htmlspecialchars($job_details['driver_phone'] ?? 'N/A') . ')</p>';
                        $html_content .= '<p><strong>Problem Description:</strong> ' . htmlspecialchars($job_details['description_of_problem']) . '</p>';
                        $html_content .= '<p><strong>Current Status:</strong> <span id=\'currentJobStatus\' class=\'status-badge status-' . strtolower(str_replace(' ', '-', $job_details['current_status'])) . '\'>' . htmlspecialchars($job_details['current_status']) . '</span></p>';
                        $html_content .= '<p><strong>Estimated Completion:</strong> ' . htmlspecialchars($job_details['estimated_completion_date'] ? date('Y-m-d', strtotime($job_details['estimated_completion_date'])) : 'N/A') . '</p>';
                        $html_content .= '<p><strong>Job Created:</strong> ' . htmlspecialchars(date('Y-m-d H:i', strtotime($job_details['created_at']))) . '</p>';
                        $html_content .= '<p><strong>Labor Cost:</strong> KES ' . htmlspecialchars(number_format($job_details['labor_cost'] ?? 0, 2)) . '</p>';
                        $html_content .= '</div>';

                        $html_content .= '<hr>';

                        $html_content .= '<div class="job-actions-section">';
                        $html_content .= '<h3>Update Job Status</h3>';
                        $html_content .= '<form id=\'updateStatusForm\'>';
                        $html_content .= '<label for=\'newStatus\'>New Status:</label>';
                        $html_content .= '<select id=\'newStatus\' name=\'new_status\' class="form-control">';
                        $html_content .= '<option value=\'pending\'' . (strtolower($job_details['current_status']) == 'pending' ? ' selected' : '') . '>Pending</option>';
                        $html_content .= '<option value=\'assigned\'' . (strtolower($job_details['current_status']) == 'assigned' ? ' selected' : '') . '>Assigned</option>';
                        $html_content .= '<option value=\'in_progress\'' . (strtolower($job_details['current_status']) == 'in_progress' ? ' selected' : '') . '>In Progress</option>';
                        $html_content .= '<option value=\'on_hold\'' . (strtolower($job_details['current_status']) == 'on_hold' ? ' selected' : '') . '>On Hold</option>';
                        $html_content .= '<option value=\'completed\'' . (strtolower($job_details['current_status']) == 'completed' ? ' selected' : '') . '>Completed</option>';
                        $html_content .= '<option value=\'canceled\'' . (strtolower($job_details['current_status']) == 'canceled' ? ' selected' : '') . '>Canceled</option>';
                        $html_content .= '<option value=\'waiting_for_parts\'' . (strtolower($job_details['current_status']) == 'waiting_for_parts' ? ' selected' : '') . '>Waiting for Parts</option>';
                        $html_content .= '</select>';
                        $html_content .= '<button type=\'submit\' class=\'btn btn-primary\'><i class="fas fa-sync-alt"></i> Update Status</button>';
                        $html_content .= '</form>';
                        $html_content .= '<div id=\'statusUpdateMessage\' class=\'message\'></div>';

                        $html_content .= '<h3>Log Work Performed</h3>';
                        $html_content .= '<form id=\'logWorkForm\'>';
                        $html_content .= '<textarea id=\'workDescription\' name=\'work_description\' rows=\'4\' placeholder=\'Describe identified issues, solutions implemented, and detailed work performed...\' class="form-control" required></textarea>';
                        $html_content .= '<button type=\'submit\' class=\'btn btn-success\'><i class="fas fa-clipboard-check"></i> Log Work</button>';
                        $html_content .= '</form>';
                        $html_content .= '<div id=\'workLogMessage\' class=\'message\'></div>';

                        $html_content .= '<h3>Record Parts Used</h3>';
                        $html_content .= '<form id=\'recordPartsUsedForm\'>';
                        $html_content .= '<label for=\'partUsedSelect\'>Select Part Used:</label>';
                        $html_content .= '<select id=\'partUsedSelect\' name=\'part_id\' class="form-control" required>';
                        $html_content .= '<option value=\'\'>-- Select a Part --</option>';
                        foreach ($parts_inventory as $part) {
                            $html_content .= '<option value=\'' . htmlspecialchars($part['item_id']) . '\' data-stock=\'' . htmlspecialchars($part['current_stock']) . '\'>' . htmlspecialchars($part['item_name']) . ' (Stock: ' . htmlspecialchars($part['current_stock']) . ')</option>';
                        }
                        $html_content .= '</select>';
                        $html_content .= '<label for=\'quantityUsed\'>Quantity Used:</label>';
                        $html_content .= '<input type=\'number\' id=\'quantityUsed\' name=\'quantity\' min=\'1\' value=\'1\' class="form-control" required>';
                        $html_content .= '<button type=\'submit\' class=\'btn btn-primary\'><i class="fas fa-boxes"></i> Record Part Used</button>';
                        $html_content .= '</form>';
                        $html_content .= '<div id=\'recordPartsUsedMessage\' class=\'message\'></div>';

                        $html_content .= '<h3>Request New Parts</h3>';
                        $html_content .= '<form id=\'requestPartsForm\'>';
                        $html_content .= '<label for=\'partRequestSelect\'>Select Part for Request:</label>';
                        $html_content .= '<select id=\'partRequestSelect\' name=\'part_id\' class="form-control" required>';
                        $html_content .= '<option value=\'\'>-- Select a Part --</option>';
                        foreach ($parts_inventory as $part) {
                            $html_content .= '<option value=\'' . htmlspecialchars($part['item_id']) . '\'>' . htmlspecialchars($part['item_name']) . ' (Stock: ' . htmlspecialchars($part['current_stock']) . ')</option>';
                        }
                        $html_content .= '</select>';
                        $html_content .= '<label for=\'partRequestQuantity\'>Quantity to Request:</label>';
                        $html_content .= '<input type=\'number\' id=\'partRequestQuantity\' name=\'quantity\' min=\'1\' value=\'1\' class="form-control" required>';
                        $html_content .= '<button type=\'submit\' class=\'btn btn-warning\'><i class="fas fa-cart-plus"></i> Request Part</button>';
                        $html_content .= '</form>';
                        $html_content .= '<div id=\'partsRequestMessage\' class=\'message\'></div>';

                        $html_content .= '<h3>Upload Photos/Videos</h3>';
                        $html_content .= '<form id=\'uploadMediaForm\' enctype=\'multipart/form-data\'>';
                        $html_content .= '<label for=\'mediaFile\'>Select File:</label>';
                        $html_content .= '<input type=\'file\' id=\'mediaFile\' name=\'media_file\' accept=\'image/*,video/*\' class="form-control">';
                        $html_content .= '<button type=\'submit\' class=\'btn btn-secondary\'><i class="fas fa-upload"></i> Upload Media</button>';
                        $html_content .= '<p><small><em>Note: Max file size is typically 2MB. Only images and videos are supported.</em></small></p>';
                        $html_content .= '</form>';
                        $html_content .= '<div id=\'mediaUploadMessage\' class=\'message\'></div>';
                        $html_content .= '</div>'; // Close job-actions-section

                        $html_content .= '<hr>';

                        $html_content .= '<div class="history-section">';
                        $html_content .= '<h3>Parts Used History</h3>';
                        if (empty($parts_used)) {
                            $html_content .= '<p class="no-data-message">No parts recorded as used for this job yet.</p>';
                        } else {
                            $html_content .= '<div class=\'parts-used-list\'>';
                            $html_content .= '<table class="parts-table">';
                            $html_content .= '<thead>';
                            $html_content .= '<tr>';
                            $html_content .= '<th>Item</th>';
                            $html_content .= '<th>Quantity</th>';
                            $html_content .= '<th>Unit Price</th>';
                            $html_content .= '<th>Total Cost</th>';
                            $html_content .= '</tr>';
                            $html_content .= '</thead>';
                            $html_content .= '<tbody>';
                            foreach ($parts_used as $part) {
                                $html_content .= '<tr>';
                                $html_content .= '<td>' . htmlspecialchars($part['item_name']) . ' (Part #' . htmlspecialchars($part['item_number']) . ')</td>';
                                $html_content .= '<td>' . htmlspecialchars($part['quantity_used']) . '</td>';
                                $html_content .= '<td>KES ' . htmlspecialchars(number_format($part['unit_price'], 2)) . '</td>';
                                $html_content .= '<td>KES ' . htmlspecialchars(number_format($part['quantity_used'] * $part['unit_price'], 2)) . '</td>';
                                $html_content .= '</tr>';
                            }
                            $html_content .= '</tbody>';
                            $html_content .= '</table>';
                            $html_content .= '</div>';
                        }

                        $html_content .= '<h3>Work History / Job Updates</h3>';
                        if (empty($job_updates)) {
                            $html_content .= '<p class="no-data-message">No updates or work logs for this job yet.</p>';
                        } else {
                            $html_content .= '<div class=\'job-updates-list\'>';
                            foreach ($job_updates as $update) {
                                $html_content .= '<div class=\'update-item\'>';
                                $html_content .= '<p><strong>' . htmlspecialchars($update['update_type']) . ' by ' . htmlspecialchars($update['mechanic_name'] ?? 'N/A') . ' on ' . htmlspecialchars(date('Y-m-d H:i', strtotime($update['timestamp']))) . ':</strong></p>';
                                $html_content .= '<p>' . nl2br(htmlspecialchars($update['description'])) . '</p>';
                                if (!empty($update['photo_path'])) {
                                    // Ensure the path is correct for the client-side
                                    $html_content .= '<p><a href="../' . htmlspecialchars($update['photo_path']) . '" target="_blank"><i class="fas fa-image"></i> View Attachment</a></p>';
                                }
                                $html_content .= '</div>';
                            }
                            $html_content .= '</div>';
                        }
                        $html_content .= '</div>'; // Close history-section

                        $response = ['success' => true, 'html' => $html_content];
                    } else {
                        $response = ['success' => false, 'message' => 'Job details not found or you do not have permission to view this job.'];
                    }
                }
                break;

            case 'update_job_status':
                $job_card_id = isset($_POST['job_id']) ? intval($_POST['job_id']) : 0;
                $new_status = isset($_POST['new_status']) ? $_POST['new_status'] : '';

                // Ensure only allowed roles can update status
                if (!in_array($current_user_type, ['mechanic', 'workshop_manager', 'admin', 'administrator', 'manager'])) {
                    throw new Exception('Unauthorized to update job status.');
                }

                if ($job_card_id > 0 && !empty($new_status)) {
                    $conn->begin_transaction();
                    try {
                        // Check if the job is assigned to the current mechanic if the user is a mechanic
                        if ($current_user_type === 'mechanic') {
                            // This condition already correctly uses assigned_to_mechanic_id
                            $check_assignment_sql = "SELECT job_card_id FROM job_cards WHERE job_card_id = ? AND assigned_to_mechanic_id = ?";
                            $stmt_check_assignment = $conn->prepare($check_assignment_sql);
                            $stmt_check_assignment->bind_param("ii", $job_card_id, $current_user_id);
                            $stmt_check_assignment->execute();
                            if ($stmt_check_assignment->get_result()->num_rows === 0) {
                                throw new Exception("You are not assigned to this job or job not found.");
                            }
                            $stmt_check_assignment->close();
                        }

                        $sql_update_job = "UPDATE job_cards SET status = ?";
                        if ($new_status === 'completed') {
                            $sql_update_job .= ", completed_at = NOW()";
                        } else {
                            $sql_update_job .= ", completed_at = NULL"; // Clear completion date if not completed
                        }
                        $sql_update_job .= " WHERE job_card_id = ?";

                        if ($stmt_update = $conn->prepare($sql_update_job)) {
                            $stmt_update->bind_param("si", $new_status, $job_card_id);
                            if ($stmt_update->execute()) {
                                $log_description = "Job status changed to '" . htmlspecialchars($new_status) . "'";
                                // This INSERT uses 'mechanic_id' which is correct for the logger
                                $sql_log = "INSERT INTO job_card_updates (job_card_id, mechanic_id, update_type, description) VALUES (?, ?, 'Status Change', ?)";
                                if ($stmt_log = $conn->prepare($sql_log)) {
                                    $stmt_log->bind_param("iis", $job_card_id, $current_user_id, $log_description);
                                    if (!$stmt_log->execute()) {
                                        error_log("Failed to log status update for job {$job_card_id}: " . $stmt_log->error);
                                    }
                                    $stmt_log->close();
                                } else {
                                    throw new Exception("Failed to prepare log statement: " . $conn->error);
                                }
                                $conn->commit();
                                $response = ['success' => true, 'message' => 'Job status updated successfully.', 'new_status' => $new_status, 'log_entry' => ['description' => $log_description, 'timestamp' => date('Y-m-d H:i:s'), 'mechanic_name' => $current_user_full_name, 'update_type' => 'Status Change']];
                            } else {
                                throw new Exception("Failed to update job status in main table: " . $stmt_update->error);
                            }
                            $stmt_update->close();
                        } else {
                            throw new Exception("Database error preparing update statement: " . $conn->error);
                        }
                    } catch (Exception $e) {
                        $conn->rollback();
                        error_log("Error updating job status: " . $e->getMessage());
                        $response = ['success' => false, 'message' => $e->getMessage()];
                    }
                } else {
                    $response = ['success' => false, 'message' => 'Invalid job ID or status provided.'];
                }
                break;

            case 'log_work':
                $job_card_id = isset($_POST['job_id']) ? intval($_POST['job_id']) : 0;
                $work_description = isset($_POST['work_description']) ? trim($_POST['work_description']) : '';

                // Ensure only allowed roles can log work
                if (!in_array($current_user_type, ['mechanic', 'workshop_manager', 'admin', 'administrator', 'manager'])) {
                    throw new Exception('Unauthorized to log work.');
                }

                if ($job_card_id > 0 && !empty($work_description)) {
                    // Check if the job is assigned to the current mechanic if the user is a mechanic
                    if ($current_user_type === 'mechanic') {
                        // This condition already correctly uses assigned_to_mechanic_id
                        $check_assignment_sql = "SELECT job_card_id FROM job_cards WHERE job_card_id = ? AND assigned_to_mechanic_id = ?";
                        $stmt_check_assignment = $conn->prepare($check_assignment_sql);
                        $stmt_check_assignment->bind_param("ii", $job_card_id, $current_user_id);
                        $stmt_check_assignment->execute();
                        if ($stmt_check_assignment->get_result()->num_rows === 0) {
                            throw new Exception('You are not assigned to this job or job not found.');
                        }
                        $stmt_check_assignment->close();
                    }

                    // This INSERT uses 'mechanic_id' which is correct for the logger
                    $sql_log = "INSERT INTO job_card_updates (job_card_id, mechanic_id, update_type, description) VALUES (?, ?, 'Work Log', ?)";
                    if ($stmt = $conn->prepare($sql_log)) {
                        $stmt->bind_param("iis", $job_card_id, $current_user_id, $work_description);
                        if ($stmt->execute()) {
                            $response = ['success' => true, 'message' => 'Work log added successfully.', 'log_entry' => ['description' => $work_description, 'timestamp' => date('Y-m-d H:i:s'), 'mechanic_name' => $current_user_full_name, 'update_type' => 'Work Log']];
                        } else {
                            throw new Exception('Failed to log work: ' . $stmt->error);
                        }
                        $stmt->close();
                    } else {
                        throw new Exception('Database error preparing log statement: ' . $conn->error);
                    }
                } else {
                    $response = ['success' => false, 'message' => 'Invalid job ID or empty work description.'];
                }
                break;

            case 'record_parts_used':
                $job_card_id = isset($_POST['job_id']) ? intval($_POST['job_id']) : 0;
                $part_id = isset($_POST['part_id']) ? intval($_POST['part_id']) : 0;
                $quantity_used = isset($_POST['quantity']) ? intval($_POST['quantity']) : 0;

                // Ensure only allowed roles can record parts
                if (!in_array($current_user_type, ['mechanic', 'workshop_manager', 'admin', 'administrator', 'manager'])) {
                    throw new Exception('Unauthorized to record parts.');
                }

                if ($job_card_id > 0 && $part_id > 0 && $quantity_used > 0) {
                    $conn->begin_transaction();
                    try {
                        // Check if the job is assigned to the current mechanic if the user is a mechanic
                        if ($current_user_type === 'mechanic') {
                            // This condition already correctly uses assigned_to_mechanic_id
                            $check_assignment_sql = "SELECT job_card_id FROM job_cards WHERE job_card_id = ? AND assigned_to_mechanic_id = ?";
                            $stmt_check_assignment = $conn->prepare($check_assignment_sql);
                            $stmt_check_assignment->bind_param("ii", $job_card_id, $current_user_id);
                            $stmt_check_assignment->execute();
                            if ($stmt_check_assignment->get_result()->num_rows === 0) {
                                throw new Exception("You are not assigned to this job or job not found.");
                            }
                            $stmt_check_assignment->close();
                        }

                        // 1. Get part name, item number, current stock, and price
                        $part_name = '';
                        $item_number = '';
                        $current_stock = 0;
                        $unit_price = 0.00; // Initialize unit price
                        $sql_part_info = "SELECT item_name, item_number, quantity AS current_stock, price FROM inventory WHERE item_id = ? FOR UPDATE"; // Lock row to prevent race conditions
                        if ($stmt_part_info = $conn->prepare($sql_part_info)) {
                            $stmt_part_info->bind_param("i", $part_id);
                            $stmt_part_info->execute();
                            $result_part_info = $stmt_part_info->get_result();
                            if ($row = $result_part_info->fetch_assoc()) {
                                $part_name = $row['item_name'];
                                $item_number = $row['item_number'];
                                $current_stock = $row['current_stock'];
                                $unit_price = $row['price']; // Fetch the price
                            }
                            $stmt_part_info->close();
                        } else {
                            throw new Exception("Failed to prepare part info statement: " . $conn->error);
                        }

                        if (empty($part_name)) {
                            throw new Exception("Invalid part selected.");
                        }
                        if ($current_stock < $quantity_used) {
                            throw new Exception("Not enough stock for " . htmlspecialchars($part_name) . ". Available: " . htmlspecialchars($current_stock) . ", Requested: " . htmlspecialchars($quantity_used) . ".");
                        }

                        // 2. Insert into job_parts (records parts used for a specific job card)
                        $sql_record_used = "INSERT INTO job_parts (job_card_id, item_id, quantity_used, assigned_by_user_id) VALUES (?, ?, ?, ?)";
                        if ($stmt_record = $conn->prepare($sql_record_used)) {
                            $stmt_record->bind_param("iiii", $job_card_id, $part_id, $quantity_used, $current_user_id);
                            if (!$stmt_record->execute()) {
                                throw new Exception("Failed to record parts used: " . $stmt_record->error);
                            }
                            $stmt_record->close();

                            // 3. Update inventory stock (decrease quantity)
                            $sql_update_stock = "UPDATE inventory SET quantity = quantity - ? WHERE item_id = ?";
                            if ($stmt_update_stock = $conn->prepare($sql_update_stock)) {
                                $stmt_update_stock->bind_param("ii", $quantity_used, $part_id);
                                if (!$stmt_update_stock->execute()) {
                                    throw new Exception("Failed to update inventory stock: " . $stmt_update_stock->error);
                                }
                                $stmt_update_stock->close();
                            } else {
                                throw new Exception("Failed to prepare inventory update statement: " . $conn->error);
                            }

                            // 4. Log parts usage in job_card_updates for historical tracking
                            $log_description = "Recorded usage of {$quantity_used} x '" . htmlspecialchars($part_name) . "' (Part #{$item_number}).";
                            $sql_log_usage = "INSERT INTO job_card_updates (job_card_id, mechanic_id, update_type, description) VALUES (?, ?, 'Parts Used', ?)";
                            if ($stmt_log_usage = $conn->prepare($sql_log_usage)) {
                                $stmt_log_usage->bind_param("iis", $job_card_id, $current_user_id, $log_description);
                                if (!$stmt_log_usage->execute()) {
                                    error_log("Failed to log parts usage for job {$job_card_id}: " . $stmt_log_usage->error);
                                }
                                $stmt_log_usage->close();
                            } else {
                                throw new Exception("Failed to prepare parts usage log statement: " . $conn->error);
                            }

                            $conn->commit(); // Commit the transaction if all operations are successful
                            $response = ['success' => true, 'message' => 'Parts recorded successfully and inventory updated.', 'log_entry' => ['description' => $log_description, 'timestamp' => date('Y-m-d H:i:s'), 'mechanic_name' => $current_user_full_name, 'update_type' => 'Parts Used']];
                        } else {
                            throw new Exception("Database error preparing record parts statement: " . $conn->error);
                        }
                    } catch (Exception $e) {
                        $conn->rollback(); // Rollback transaction on error
                        error_log("Error recording parts used: " . $e->getMessage());
                        $response = ['success' => false, 'message' => $e->getMessage()];
                    }
                } else {
                    $response = ['success' => false, 'message' => 'Invalid job ID, part ID, or quantity provided for parts usage.'];
                }
                break;

            case 'upload_media':
                $job_card_id = isset($_POST['job_id']) ? intval($_POST['job_id']) : 0;
                $file = $_FILES['media_file'] ?? null;

                // Ensure only allowed roles can upload media
                if (!in_array($current_user_type, ['mechanic', 'workshop_manager', 'admin', 'administrator', 'manager'])) {
                    throw new Exception('Unauthorized to upload media.');
                }

                if ($job_card_id > 0 && $file && $file['error'] === UPLOAD_ERR_OK) {
                    // Check if the job is assigned to the current mechanic if the user is a mechanic
                    if ($current_user_type === 'mechanic') {
                        // This condition already correctly uses assigned_to_mechanic_id
                        $check_assignment_sql = "SELECT job_card_id FROM job_cards WHERE job_card_id = ? AND assigned_to_mechanic_id = ?";
                        $stmt_check_assignment = $conn->prepare($check_assignment_sql);
                        $stmt_check_assignment->bind_param("ii", $job_card_id, $current_user_id);
                        $stmt_check_assignment->execute();
                        if ($stmt_check_assignment->get_result()->num_rows === 0) {
                            throw new Exception('You are not assigned to this job or job not found.');
                        }
                        $stmt_check_assignment->close();
                    }

                    $target_dir = "../uploads/job_media/"; // Directory to save uploaded files
                    if (!is_dir($target_dir)) {
                        mkdir($target_dir, 0777, true); // Create directory if it doesn't exist
                    }

                    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'mov', 'avi']; // Allowed file types
                    if (!in_array($file_extension, $allowed_extensions)) {
                        throw new Exception("Invalid file type. Only images (jpg, jpeg, png, gif) and videos (mp4, mov, avi) are allowed.");
                    }

                    // Generate a unique file name to prevent overwrites and security issues.
                    $new_file_name = uniqid('media_') . '.' . $file_extension;
                    $target_file = $target_dir . $new_file_name;

                    if (move_uploaded_file($file['tmp_name'], $target_file)) {
                        $file_path_for_db = 'uploads/job_media/' . $new_file_name; // Path stored in database, relative to BASE_URL

                        // Log the media upload in job_card_updates.
                        $log_description = "Uploaded media file: " . htmlspecialchars($new_file_name);
                        $sql_log_media = "INSERT INTO job_card_updates (job_card_id, mechanic_id, update_type, description, photo_path) VALUES (?, ?, 'Media Upload', ?, ?)";
                        if ($stmt_log_media = $conn->prepare($sql_log_media)) {
                            $stmt_log_media->bind_param("iiss", $job_card_id, $current_user_id, $log_description, $file_path_for_db);
                            if (!$stmt_log_media->execute()) {
                                error_log("Failed to log media upload: " . $stmt_log_media->error);
                            }
                            $stmt_log_media->close();
                        } else {
                            throw new Exception("Failed to prepare media log statement: " . $conn->error);
                        }
                        $response = ['success' => true, 'message' => 'Media uploaded successfully.', 'log_entry' => ['description' => $log_description, 'timestamp' => date('Y-m-d H:i:s'), 'mechanic_name' => $current_user_full_name, 'update_type' => 'Media Upload', 'photo_path' => $file_path_for_db]];
                    } else {
                        throw new Exception("Failed to move uploaded file.");
                    }
                } else {
                    throw new Exception("No file uploaded or invalid job ID.");
                }
                break;

            default:
                throw new Exception('Invalid action specified.');
        }
    } catch (Exception $e) {
        // Catch any exceptions thrown during AJAX processing and return an error response.
        $response = ['success' => false, 'message' => $e->getMessage()];
    } finally {
        // Ensure the database connection is closed after the AJAX request is handled.
        if (isset($conn) && $conn->ping()) {
            $conn->close();
        }
    }
    echo json_encode($response); // Output the JSON response
    exit(); // Terminate script execution after AJAX response
}

// --- Fetch Job Cards for the Dashboard Display (for initial page load) ---
$job_cards = [];
// This query fetches job cards along with related vehicle, assigned mechanic, and service advisor names.
// It filters jobs based on the user's role: mechanics see only their assigned jobs,
// while managers/admins see all jobs.
$sql_job_cards = "SELECT
                        jc.job_card_id,
                        jc.description AS issue_description,
                        jc.status,
                        jc.created_at,
                        jc.completed_at,
                        v.registration_number,
                        v.make,
                        v.model,
                        u.full_name AS assigned_mechanic_name,
                        sa.full_name AS service_advisor_name
                    FROM
                        job_cards jc
                    LEFT JOIN
                        vehicles v ON jc.vehicle_id = v.vehicle_id
                    LEFT JOIN
                        users u ON jc.assigned_to_mechanic_id = u.user_id -- Correctly joins with the assigned mechanic
                    LEFT JOIN
                        users sa ON jc.service_advisor_id = sa.user_id
                    WHERE
                        jc.assigned_to_mechanic_id = ? OR ? IN (SELECT user_id FROM users WHERE user_type IN ('workshop_manager', 'admin', 'administrator', 'manager'))
                    ORDER BY
                        jc.created_at DESC";

$stmt_job_cards = $conn->prepare($sql_job_cards);

if ($stmt_job_cards) {
    // Bind parameters: the first 'i' is for jc.assigned_to_mechanic_id = ?, and the second 'i' is for the subquery's IN clause.
    $stmt_job_cards->bind_param("ii", $current_user_id, $current_user_id);
    $stmt_job_cards->execute();
    $result_job_cards = $stmt_job_cards->get_result();
    while ($row = $result_job_cards->fetch_assoc()) {
        $job_cards[] = $row;
    }
    $stmt_job_cards->close();
} else {
    error_log("Failed to prepare job cards query: " . $conn->error);
    $_SESSION['error_message'] = "Error fetching job cards. Please try again.";
}

// Fetch pending parts requests specifically for the current mechanic.
$pending_parts_requests = [];
if ($current_user_type === 'mechanic') {
    $sql_pending_requests = "SELECT pr.request_id, pr.job_card_id, inv.item_name, pr.quantity_requested, pr.status, pr.created_at
                             FROM parts_requests pr
                             JOIN inventory inv ON pr.item_id = inv.item_id
                             WHERE pr.assigned_to_mechanic_id = ? AND pr.status = 'pending'
                             ORDER BY pr.created_at DESC";
    $stmt_pending_requests = $conn->prepare($sql_pending_requests);
    if ($stmt_pending_requests) {
        $stmt_pending_requests->bind_param("i", $current_user_id);
        $stmt_pending_requests->execute();
        $result_pending_requests = $stmt_pending_requests->get_result();
        while ($row = $result_pending_requests->fetch_assoc()) {
            $pending_parts_requests[] = $row;
        }
        $stmt_pending_requests->close();
    } else {
        error_log("Failed to prepare pending parts requests query: " . $conn->error);
    }
}

$conn->close(); // Close the database connection after all data fetching for the main page load.
ob_end_flush(); // End output buffering and send all buffered content to the browser.
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lewa Workshop - Technician Dashboard</title>
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* General Styles */
        :root {
            --primary: #3498db;
            --secondary: #2980b9;
            --success: #2ecc71;
            --danger: #e74c3c;
            --warning: #f39c12;
            --info: #17a2b8;
            --light: #ecf0f1;
            --dark: #2c3e50;
            --text-color: #333;
            --border-color: #ddd;
            --input-bg: #f9f9f9;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f5f5f5;
            color: var(--text-color);
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styles */
        .sidebar {
            background-color: var(--dark);
            color: white;
            padding: 20px;
            width: 250px;
            flex-shrink: 0;
            box-shadow: 2px 0 5px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
        }

        .sidebar h2 {
            text-align: center;
            color: var(--primary);
            margin-bottom: 30px;
        }

        .user-profile {
            text-align: center;
            margin-bottom: 30px;
        }

        .user-avatar {
            width: 80px;
            height: 80px;
            background-color: var(--primary);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5em;
            font-weight: bold;
            margin: 0 auto 10px auto;
        }

        .user-profile p {
            margin: 5px 0 0 0;
            font-size: 1.1em;
            font-weight: 600;
        }

        .user-profile small {
            color: #bbb;
        }

        .sidebar nav ul {
            list-style: none;
            padding: 0;
            margin: 0;
            flex-grow: 1;
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

        /* Main Content Styles */
        .main-content {
            flex-grow: 1;
            padding: 20px;
            overflow-x: hidden;
        }

        h1 {
            color: var(--dark);
            margin-bottom: 30px;
        }

        /* Stat Cards */
        .stat-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .stat-card h3 {
            margin-top: 0;
            color: var(--dark);
            font-size: 1rem;
        }

        .stat-card .value {
            font-size: 2rem;
            font-weight: bold;
            color: var(--primary);
        }

        /* Job Cards and Parts Requests Sections */
        .job-cards-section,
        .parts-requests-section {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
            overflow-x: auto;
        }

        .job-cards-section h2,
        .parts-requests-section h2 {
            color: var(--dark);
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 1.5rem;
            border-bottom: 2px solid var(--primary);
            padding-bottom: 10px;
        }

        /* Tables */
        table {
            width: 100%;
            min-width: 700px;
            border-collapse: collapse;
            margin-top: 15px;
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

        td {
            color: #555;
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 5px;
            font-weight: bold;
            text-transform: capitalize;
            font-size: 0.85em;
        }

        .status-pending {
            background-color: #ffe0b2;
            color: var(--warning);
        }

        .status-assigned {
            background-color: #bbdefb;
            color: #2196F3;
        }

        .status-in-progress {
            background-color: #b3e5fc;
            color: var(--primary);
        }

        .status-completed {
            background-color: #c8e6c9;
            color: var(--success);
        }

        .status-on-hold {
            background-color: #ffcdd2;
            color: var(--danger);
        }

        .status-assessment_requested {
            background-color: #e0f2f7;
            color: var(--info);
        }

        .status-waiting_for_parts {
            background-color: #e1bee7;
            color: #9C27B0;
        }

        .status-canceled {
            background-color: #cfd8dc;
            color: #607d8b;
        }

        /* Buttons */
        .btn {
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s ease;
            font-size: 0.9em;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
        }

        .btn:hover {
            background-color: var(--secondary);
        }

        .btn-success {
            background-color: var(--success);
        }

        .btn-success:hover {
            background-color: #27ae60;
        }

        .btn-warning {
            background-color: var(--warning);
        }

        .btn-warning:hover {
            background-color: #e67e22;
        }

        .btn-secondary {
            background-color: #6c757d;
        }

        .btn-secondary:hover {
            background-color: #5a6268;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1001;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.6);
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .modal-content {
            background-color: #fefefe;
            margin: auto;
            padding: 30px;
            border: 1px solid #888;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            width: 90%;
            max-width: 900px;
            position: relative;
            max-height: 90vh;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .close-button {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            position: absolute;
            top: 15px;
            right: 25px;
            cursor: pointer;
        }

        .close-button:hover,
        .close-button:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }

        .modal h3 {
            color: var(--dark);
            margin-top: 0;
            margin-bottom: 15px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }

        .modal p {
            margin-bottom: 10px;
            line-height: 1.5;
        }

        .modal .form-control {
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid var(--border-color);
            border-radius: 5px;
            box-sizing: border-box;
        }

        .modal .btn {
            margin-top: 10px;
        }

        .job-details-section,
        .job-actions-section,
        .history-section {
            padding: 15px;
            border: 1px solid #eee;
            border-radius: 8px;
            background-color: #fdfdfd;
        }

        .job-details-section p strong {
            display: inline-block;
            width: 150px;
        }

        .job-updates-list .update-item {
            background-color: #f9f9f9;
            border: 1px solid #eee;
            border-radius: 5px;
            padding: 10px;
            margin-bottom: 10px;
        }

        .job-updates-list .update-item p {
            margin: 5px 0;
        }

        .parts-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .parts-table th,
        .parts-table td {
            padding: 8px;
            border: 1px solid #eee;
            text-align: left;
        }

        .parts-table th {
            background-color: #f0f0f0;
        }

        /* Notification */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            background-color: #4CAF50;
            color: white;
            padding: 15px;
            border-radius: 5px;
            display: none;
            opacity: 0;
            transition: opacity 0.5s ease-in-out;
            z-index: 1000;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .notification.show {
            opacity: 1;
            display: block;
        }

        .notification-error {
            background-color: #f44336;
        }

        .notification-success {
            background-color: #4CAF50;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            body {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
                height: auto;
                padding-bottom: 10px;
            }

            .sidebar nav ul {
                flex-direction: row;
                flex-wrap: wrap;
                justify-content: center;
                gap: 5px;
            }

            .sidebar nav ul li {
                margin-bottom: 0;
            }

            .sidebar nav ul li a {
                padding: 8px 10px;
                font-size: 0.9em;
            }

            .main-content {
                padding: 15px;
            }

            .stat-cards {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            }

            .modal-content {
                width: 95%;
                padding: 20px;
            }

            table {
                min-width: 100%;
            }
        }

        @media (max-width: 480px) {
            .stat-cards {
                grid-template-columns: 1fr;
            }

            .sidebar nav ul {
                flex-direction: column;
                align-items: center;
            }

            .sidebar nav ul li {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>

<body>
    <div class="sidebar">
        <div class="user-profile">
            <div class="user-avatar"><?php echo $current_user_initial; ?></div>
            <p><?php echo $current_user_full_name; ?></p>
            <small><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $_SESSION['user_type']))); ?></small>
        </div>
        <nav>
            <ul>
                <li><a href="#" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <?php if (in_array($current_user_type, ['workshop_manager', 'admin', 'administrator', 'manager'])) : ?>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/create_job_card.php"><i class="fas fa-clipboard-list"></i> Create Job Card</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/add_vehicle.php"><i class="fas fa-car"></i> Add Vehicle</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/add_driver.php"><i class="fas fa-user-plus"></i> Add Driver</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/manage_users.php"><i class="fas fa-users"></i> Manage Users</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/parts_manager.php"><i class="fas fa-boxes"></i> Parts Manager</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboards/reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
                <?php endif; ?>
                <li><a href="<?php echo BASE_URL; ?>/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </nav>
    </div>

    <div class="main-content">
        <h1>Technician Dashboard</h1>

        <div class="stat-cards">
            <div class="stat-card">
                <h3>New Jobs Today</h3>
                <p class="value" id="newJobsToday"><?php echo htmlspecialchars($new_jobs_today_count); ?></p>
            </div>
            <div class="stat-card">
                <h3>In Progress Jobs</h3>
                <p class="value" id="inProgressJobs"><?php echo htmlspecialchars($in_progress_jobs_count); ?></p>
            </div>
            <div class="stat-card">
                <h3>Completed This Week</h3>
                <p class="value" id="completedThisWeek"><?php echo htmlspecialchars($completed_this_week_count); ?></p>
            </div>
            <?php if (in_array($current_user_type, ['workshop_manager', 'admin', 'administrator', 'manager'])) : // Only show for managers/admins ?>
                <div class="stat-card">
                    <h3>Pending Assignments</h3>
                    <p class="value" id="pendingAssignments"><?php echo htmlspecialchars($pending_assignments_count); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <div class="job-cards-section">
            <h2><?php echo ($current_user_type === 'mechanic') ? 'My Assigned Jobs' : 'All Job Cards'; ?></h2>
            <table>
                <thead>
                    <tr>
                        <th>Job ID</th>
                        <th>Vehicle</th>
                        <th>License Plate</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th>Assigned Mechanic</th>
                        <th>Service Advisor</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($job_cards)) : ?>
                        <?php foreach ($job_cards as $job) : ?>
                            <tr class="job-card-item" data-job-id="<?php echo htmlspecialchars($job['job_card_id']); ?>">
                                <td><?php echo htmlspecialchars($job['job_card_id']); ?></td>
                                <td><?php echo htmlspecialchars($job['make'] . ' ' . $job['model']); ?></td>
                                <td><?php echo htmlspecialchars($job['registration_number']); ?></td>
                                <td><?php echo htmlspecialchars($job['issue_description']); ?></td>
                                <td><span class="status-badge status-<?php echo str_replace(' ', '-', strtolower(htmlspecialchars($job['status']))); ?>">
                                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $job['status']))); ?>
                                    </span></td>
                                <td><?php echo htmlspecialchars($job['assigned_mechanic_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($job['service_advisor_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($job['created_at']))); ?></td>
                                <td>
                                    <button class="btn view-details-btn" data-job-id="<?php echo htmlspecialchars($job['job_card_id']); ?>">
                                        <i class="fas fa-eye"></i> View Details
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="9">No job cards found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($current_user_type === 'mechanic' && !empty($pending_parts_requests)) : ?>
            <div class="parts-requests-section">
                <h2>My Pending Parts Requests</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Request ID</th>
                            <th>Job Card ID</th>
                            <th>Part Name</th>
                            <th>Quantity</th>
                            <th>Status</th>
                            <th>Requested At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_parts_requests as $request) : ?>
                            <tr>
                                <td><?php echo htmlspecialchars($request['request_id']); ?></td>
                                <td><?php echo htmlspecialchars($request['job_card_id']); ?></td>
                                <td><?php echo htmlspecialchars($request['item_name']); ?></td>
                                <td><?php echo htmlspecialchars($request['quantity_requested']); ?></td>
                                <td><span class="status-badge status-<?php echo str_replace(' ', '-', strtolower(htmlspecialchars($request['status']))); ?>">
                                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $request['status']))); ?>
                                    </span></td>
                                <td><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($request['requested_at']))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- Job Details Modal -->
        <div id="jobDetailsModal" class="modal">
            <div class="modal-content">
                <span class="close-button" onclick="closeJobDetailsModal()">&times;</span>
                <div id="modalContent">
                    <!-- Job details will be loaded here via AJAX -->
                </div>
            </div>
        </div>

    </div>

    <div id="notification" class="notification"></div>

    <!-- jQuery library for AJAX and DOM manipulation -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        // Function to display notifications (success/error messages) to the user.
        function showNotification(message, type) {
            const notification = $('#notification');
            notification.removeClass().addClass('notification show notification-' + type); // Apply appropriate styling
            notification.html('<i class="fas ' + (type === 'success' ? 'fa-check-circle' : 'fa-times-circle') + '"></i> ' + message);
            // Automatically hide the notification after 5 seconds.
            setTimeout(() => {
                notification.removeClass('show');
            }, 5000);
        }

        // Modal functionality for displaying job details.
        const jobDetailsModal = document.getElementById('jobDetailsModal');
        const modalContent = document.getElementById('modalContent');
        let currentJobId = null; // Variable to store the job_id of the currently viewed job.

        // Opens the job details modal and fetches content via AJAX.
        function openJobDetailsModal(jobId) {
            currentJobId = jobId; // Set the current job ID for use in modal forms.
            $.ajax({
                url: `<?php echo BASE_URL; ?>/dashboards/technician_dashboard.php?action=get_job_details&job_id=${jobId}`,
                method: 'GET',
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        modalContent.innerHTML = data.html; // Insert fetched HTML into the modal.
                        jobDetailsModal.style.display = 'flex'; // Display the modal (using flex for centering).
                        attachModalFormListeners(); // Attach event listeners to forms inside the newly loaded modal content.
                    } else {
                        showNotification(data.message || 'Failed to load job details.', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error("Failed to fetch job details:", status, error);
                    showNotification("An error occurred while fetching job details.", "error");
                }
            });
        }

        // Closes the job details modal and clears its content.
        function closeJobDetailsModal() {
            jobDetailsModal.style.display = 'none'; // Hide the modal.
            modalContent.innerHTML = ''; // Clear modal content to prepare for next view.
            currentJobId = null; // Reset current job ID.
        }

        // Document ready function: executes when the DOM is fully loaded.
        $(document).ready(function() {
            // No need to call fetchStats() here anymore, as PHP populates the initial counts.

            // Attach click listener to all "View Details" buttons.
            $('.view-details-btn').on('click', function() {
                const jobId = $(this).data('job-id'); // Get the job ID from the data attribute.
                openJobDetailsModal(jobId); // Open the modal for the selected job.
            });
        });

        // Function to attach event listeners to forms loaded dynamically within the modal.
        function attachModalFormListeners() {
            // Event listener for the "Update Status" form.
            $('#updateStatusForm').on('submit', function(e) {
                e.preventDefault(); // Prevent default form submission.
                const newStatus = $('#newStatus').val();
                if (!currentJobId || !newStatus) {
                    showNotification('Job ID or new status is missing.', 'error');
                    return;
                }

                $.ajax({
                    url: '<?php echo BASE_URL; ?>/dashboards/technician_dashboard.php?action=update_job_status',
                    method: 'POST',
                    data: {
                        job_id: currentJobId,
                        new_status: newStatus,
                        csrf_token: '<?php echo $_SESSION['csrf_token']; ?>' // Include CSRF token
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (data.success) {
                            showNotification(data.message, 'success');
                            // Update the status badge on the main dashboard table.
                            updateJobCardStatusInList(currentJobId, data.new_status);
                            // Update the status text within the modal itself.
                            $('#currentJobStatus').text(data.new_status).removeClass().addClass('status-badge status-' + data.new_status.toLowerCase().replace(' ', '-'));
                            // Append the new status change to the work history.
                            if (data.log_entry) {
                                appendLogEntry(data.log_entry);
                            }
                        } else {
                            showNotification(data.message || 'Failed to update status.', 'error');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error("Status update failed:", status, error);
                        showNotification("An error occurred during status update.", "error");
                    }
                });
            });

            // Event listener for the "Log Work Performed" form.
            $('#logWorkForm').on('submit', function(e) {
                e.preventDefault();
                const workDescription = $('#workDescription').val();
                if (!currentJobId || !workDescription) {
                    showNotification('Job ID or work description is missing.', 'error');
                    return;
                }

                $.ajax({
                    url: '<?php echo BASE_URL; ?>/dashboards/technician_dashboard.php?action=log_work',
                    method: 'POST',
                    data: {
                        job_id: currentJobId,
                        work_description: workDescription,
                        csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (data.success) {
                            showNotification(data.message, 'success');
                            $('#workDescription').val(''); // Clear the textarea after successful submission.
                            if (data.log_entry) {
                                appendLogEntry(data.log_entry); // Add the new log entry to history.
                            }
                        } else {
                            showNotification(data.message || 'Failed to log work.', 'error');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error("Work log failed:", status, error);
                        showNotification("An error occurred during work logging.", "error");
                    }
                });
            });

            // Event listener for the "Record Parts Used" form.
            $('#recordPartsUsedForm').on('submit', function(e) {
                e.preventDefault();
                const partId = $('#partUsedSelect').val();
                const quantity = $('#quantityUsed').val();
                if (!currentJobId || !partId || !quantity) {
                    showNotification('Job ID, part, or quantity is missing.', 'error');
                    return;
                }

                $.ajax({
                    url: '<?php echo BASE_URL; ?>/dashboards/technician_dashboard.php?action=record_parts_used',
                    method: 'POST',
                    data: {
                        job_id: currentJobId,
                        part_id: partId,
                        quantity: quantity,
                        csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (data.success) {
                            showNotification(data.message, 'success');
                            $('#partUsedSelect').val(''); // Clear part selection.
                            $('#quantityUsed').val(1); // Reset quantity to 1.
                            // Re-fetch all job details to update the "Parts Used History" section in the modal.
                            openJobDetailsModal(currentJobId);
                        } else {
                            showNotification(data.message || 'Failed to record parts.', 'error');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error("Record parts failed:", status, error);
                        showNotification("An error occurred during parts recording.", "error");
                    }
                });
            });

            // Event listener for the "Request New Parts" form.
            $('#requestPartsForm').on('submit', function(e) {
                e.preventDefault();
                const partId = $('#partRequestSelect').val();
                const quantity = $('#partRequestQuantity').val();
                if (!currentJobId || !partId || !quantity) {
                    showNotification('Job ID, part, or quantity is missing for request.', 'error');
                    return;
                }

                $.ajax({
                    url: '<?php echo BASE_URL; ?>/dashboards/request_parts.php', // This points to a separate script for parts requests.
                    method: 'POST',
                    data: {
                        job_card_id: currentJobId,
                        part_item_id: partId,
                        quantity: quantity,
                        csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (data.success) {
                            showNotification(data.message, 'success');
                            $('#partRequestSelect').val('');
                            $('#partRequestQuantity').val(1);
                            // Optionally, you might want to refresh a "My Pending Parts Requests" section on the main dashboard.
                        } else {
                            showNotification(data.message || 'Failed to submit parts request.', 'error');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error("Parts request failed:", status, error);
                        showNotification("An error occurred during parts request.", "error");
                    }
                });
            });

            // Event listener for the "Upload Photos/Videos" form.
            $('#uploadMediaForm').on('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this); // Use FormData for file uploads.
                formData.append('job_id', currentJobId);
                formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

                $.ajax({
                    url: '<?php echo BASE_URL; ?>/dashboards/technician_dashboard.php?action=upload_media',
                    method: 'POST',
                    data: formData,
                    processData: false, // Important: Don't process the files (jQuery handles it).
                    contentType: false, // Important: Let jQuery set the content type for multipart/form-data.
                    dataType: 'json',
                    success: function(data) {
                        if (data.success) {
                            showNotification(data.message, 'success');
                            $('#mediaFile').val(''); // Clear the file input.
                            if (data.log_entry) {
                                appendLogEntry(data.log_entry); // Add the media upload log to history.
                            }
                        } else {
                            showNotification(data.message || 'Failed to upload media.', 'error');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error("Media upload failed:", status, error);
                        showNotification("An error occurred during media upload.", "error");
                    }
                });
            });
        }

        // Helper function to dynamically append a new log entry (status change, work log, parts used, media upload)
        // to the "Work History / Job Updates" section within the modal.
        function appendLogEntry(logEntry) {
            let jobUpdatesList = $('.job-updates-list');
            if (jobUpdatesList.length === 0) {
                // If the work history section doesn't exist (e.g., no previous updates), create it.
                $('#modalContent').append('<hr><div class="history-section"><h3>Work History / Job Updates</h3><div class="job-updates-list"></div></div>');
                jobUpdatesList = $('.job-updates-list'); // Re-select the newly created element.
            }
            // Ensure the "Work History / Job Updates" heading is present.
            if ($('#modalContent').find('h3:contains("Work History / Job Updates")').length === 0) {
                $('<hr><h3>Work History / Job Updates</h3>').insertBefore(jobUpdatesList);
            }

            // Construct the HTML for the new log entry.
            const newEntry = `
                <div class='update-item'>
                    <p><strong>${logEntry.update_type} by ${logEntry.mechanic_name} on ${new Date(logEntry.timestamp).toLocaleString()}:</strong></p>
                    <p>${logEntry.description.replace(/\n/g, '<br>')}</p>
                    ${logEntry.photo_path ? `<p><a href="../${logEntry.photo_path}" target="_blank"><i class="fas fa-image"></i> View Attachment</a></p>` : ''}
                </div>
            `;
            jobUpdatesList.prepend(newEntry); // Add the new entry to the top of the list (most recent first).
        }

        // Helper function to update the job card's status badge on the main dashboard table.
        function updateJobCardStatusInList(jobId, newStatus) {
            const jobCardItem = $(`.job-card-item[data-job-id='${jobId}']`);
            if (jobCardItem.length) {
                const statusBadge = jobCardItem.find('.status-badge');
                statusBadge.text(newStatus);
                // Update the class to reflect the new status color/styling.
                statusBadge.removeClass().addClass('status-badge status-' + newStatus.toLowerCase().replace(' ', '-'));
            }
        }
    </script>
</body>

</html>
