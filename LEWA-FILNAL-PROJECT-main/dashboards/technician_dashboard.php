<?php
session_start();

//SECURITY CHECKUP
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'mechanic') {
    header("Location: user_login.php");
    exit();
}

include '../db_connect.php';

//DATABASE CONNECTION VALIDATION
if (!isset($conn) || $conn->connect_error) {
    error_log("FATAL ERROR: Database connection failed in " . __FILE__ . ": " . ($conn->connect_error ?? 'Connection object not set.'));
    $_SESSION['error_message'] = "A critical system error occurred. Please try again later.";
    header("Location: user_login.php");
    exit();
}

// --- MECHANIC DETAILS ---
$mechanic_id = $_SESSION['user_id'];
$mechanic_full_name = 'mechanic'; // Default value
$mechanic_initial = '?'; // Default initial

$mechanic_query = "SELECT full_name, username FROM users WHERE user_id = ?";
$stmt = $conn->prepare($mechanic_query);

if ($stmt === false) {
    error_log("Failed to prepare statement for mechanic details: " . $conn->error);
    $_SESSION['error_message'] = "Error fetching mechanic details. Please try again.";
    header("Location: user_login.php"); // Redirect to login as user details are fundamental
    exit();
}

if (!$stmt->bind_param("i", $mechanic_id) || !$stmt->execute()) {
    error_log("Failed to execute statement for mechanic details: " . $stmt->error);
    $stmt->close();
    $_SESSION['error_message'] = "Error fetching mechanic details. Please try again.";
    header("Location: user_login.php"); // Redirect to login
    exit();
}

$result = $stmt->get_result();
if ($result && $mechanic_data = $result->fetch_assoc()) {
    $mechanic_full_name = htmlspecialchars($mechanic_data['full_name'] ?? $mechanic_data['username']);
    $mechanic_initial = strtoupper(substr($mechanic_data['full_name'] ?? $mechanic_data['username'], 0, 1));
} else {
    // This case indicates a security or data integrity issue if user_id exists but not in users table
    error_log("SECURITY ALERT: Mechanic with user_id {$mechanic_id} not found in DB or full_name is null.");
    $_SESSION['error_message'] = "Your user account could not be found. Please log in again.";
    header("Location: user_login.php"); // Force re-login
    exit();
}
$stmt->close();

// --- PHP Logic for Fetching Assigned Jobs for the main dashboard view ---
$assigned_jobs = [];
$sql_assigned_jobs = "SELECT
                            jc.job_card_id,
                            v.registration_number,
                            v.make,
                            v.model,
                            u.full_name AS driver_full_name,
                            jc.description AS description, /* Changed to reflect column in your SQL */
                            jc.status AS status, /* Changed to reflect column in your SQL */
                            jc.completed_at AS completed_at, /* Renamed for consistency with your existing code, though it's an estimated date */
                            jc.created_at
                        FROM
                            job_cards jc
                        JOIN
                            vehicles v ON jc.vehicle_id = v.vehicle_id
                        JOIN
                            users u ON jc.driver_id = u.user_id
                        WHERE
                            jc.assigned_mechanic_id = ?
                        ORDER BY
                            CASE
                                WHEN jc.status = 'Pending' THEN 1
                                WHEN jc.status = 'In Progress' THEN 2
                                WHEN jc.status = 'On Hold' THEN 3
                                WHEN jc.status = 'Completed' THEN 4
                                WHEN jc.status = 'Canceled' THEN 5
                                ELSE 6
                            END,
                            jc.created_at DESC";


if ($stmt_jobs = $conn->prepare($sql_assigned_jobs)) {
    $stmt_jobs->bind_param("i", $mechanic_id);
    $stmt_jobs->execute();
    $result_jobs = $stmt_jobs->get_result();
    while ($row = $result_jobs->fetch_assoc()) {
        $assigned_jobs[] = $row;
    }
    $stmt_jobs->close();
} else {
    error_log("Failed to prepare statement for assigned jobs: " . $conn->error);
    $_SESSION['error_message'] = "Error fetching assigned jobs. Please try again.";
}

// --- PHP Logic for AJAX Endpoints (Job Details, Updates, Requests) ---
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'Invalid action or missing parameters.'];

    switch ($_GET['action']) {
        case 'get_job_details':
            $job_card_id = isset($_GET['job_id']) ? intval($_GET['job_id']) : 0;
            if ($job_card_id > 0) {
                $job_details = null;
                $job_updates = [];
                $parts_inventory = [];

                $sql_job = "SELECT
                                jc.job_card_id,
                                v.registration_number, v.make, v.model, v.year, v.mileage,
                                d.full_name AS driver_full_name,
                                d.phone_number AS driver_phone, d.email AS driver_email,
                                jc.service_type, jc.description_of_problem, jc.current_status,
                                jc.estimated_completion_date, jc.created_at
                            FROM
                                job_cards jc
                            JOIN vehicles v ON jc.vehicle_id = v.vehicle_id
                            JOIN users d ON jc.driver_id = d.user_id
                            WHERE
                                jc.job_card_id = ? AND jc.assigned_mechanic_id = ?";
                if ($stmt_job = $conn->prepare($sql_job)) {
                    $stmt_job->bind_param("ii", $job_card_id, $mechanic_id);
                    $stmt_job->execute();
                    $result_job = $stmt_job->get_result();
                    $job_details = $result_job->fetch_assoc();
                    $stmt_job->close();
                }

                if ($job_details) {
                    $sql_updates = "SELECT jcu.description, jcu.timestamp, jcu.update_type, u.full_name AS mechanic_name, jcu.photo_path
                                       FROM job_card_updates jcu
                                       JOIN users u ON jcu.mechanic_id = u.user_id
                                       WHERE jcu.job_card_id = ? ORDER BY jcu.timestamp DESC";
                    if ($stmt_updates = $conn->prepare($sql_updates)) {
                        $stmt_updates->bind_param("i", $job_card_id);
                        $stmt_updates->execute();
                        $result_updates = $stmt_updates->get_result();
                        while ($row = $result_updates->fetch_assoc()) {
                            $job_updates[] = $row;
                        }
                        $stmt_updates->close();
                    }

                    // Fetch parts used for this job
                    $parts_used = [];
                    $sql_parts_used = "SELECT jcp.quantity_used, p.part_name, p.part_number
                                         FROM job_card_parts_used jcp
                                         JOIN parts p ON jcp.part_id = p.part_id
                                         WHERE jcp.job_card_id = ?";
                    if ($stmt_parts_used = $conn->prepare($sql_parts_used)) {
                        $stmt_parts_used->bind_param("i", $job_card_id);
                        $stmt_parts_used->execute();
                        $result_parts_used = $stmt_parts_used->get_result();
                        while ($row = $result_parts_used->fetch_assoc()) {
                            $parts_used[] = $row;
                        }
                        $stmt_parts_used->close();
                    }


                    $sql_parts = "SELECT part_id, part_name, current_stock FROM parts ORDER BY part_name ASC";
                    $result_parts = $conn->query($sql_parts);
                    while ($row = $result_parts->fetch_assoc()) {
                        $parts_inventory[] = $row;
                    }

                    ob_start();
                    ?>
                    <h2>Job Card #<?php echo $job_details['job_card_id']; ?> Details</h2>
                    <p><strong>Vehicle:</strong> <?php echo htmlspecialchars($job_details['year'] . ' ' . $job_details['make'] . ' ' . $job_details['model'] . ' (' . $job_details['registration_number'] . ')'); ?></p>
                    <p><strong>Mileage:</strong> <?php echo htmlspecialchars($job_details['mileage']); ?> km</p>
                    <p><strong>Driver:</strong> <?php echo htmlspecialchars($job_details['driver_full_name'] . ' (' . $job_details['driver_phone'] . ')'); ?></p>
                    <p><strong>Service Type:</strong> <?php echo htmlspecialchars($job_details['service_type']); ?></p>
                    <p><strong>Problem Description:</strong> <?php echo htmlspecialchars($job_details['description_of_problem']); ?></p>
                    <p><strong>Current Status:</strong> <span id='currentJobStatus' class='status-<?php echo strtolower(str_replace(' ', '-', $job_details['current_status'])); ?>'><?php echo htmlspecialchars($job_details['current_status']); ?></span></p>
                    <p><strong>Estimated Completion:</strong> <?php echo htmlspecialchars($job_details['estimated_completion_date']); ?></p>
                    <p><strong>Job Started:</strong> <?php echo htmlspecialchars($job_details['created_at']); ?></p>

                    ---
                    <h3>Update Job Status</h3>
                    <form id='updateStatusForm' data-job-id='<?php echo $job_card_id; ?>'>
                        <label for='newStatus'>New Status:</label>
                        <select id='newStatus' name='new_status'>
                            <option value='In Progress'<?php echo ($job_details['current_status'] == 'In Progress' ? ' selected' : ''); ?>>In Progress</option>
                            <option value='On Hold'<?php echo ($job_details['current_status'] == 'On Hold' ? ' selected' : ''); ?>>On Hold</option>
                            <option value='Completed'<?php echo ($job_details['current_status'] == 'Completed' ? ' selected' : ''); ?>>Completed</option>
                            <option value='Canceled'<?php echo ($job_details['current_status'] == 'Canceled' ? ' selected' : ''); ?>>Canceled</option>
                        </select>
                        <button type='submit' class='btn btn-primary'>Update Status</button>
                    </form>
                    <div id='statusUpdateMessage' class='message'></div>

                    ---
                    <h3>Log Work Performed (Issues Identified, Solutions, Work Done)</h3>
                    <form id='logWorkForm' data-job-id='<?php echo $job_card_id; ?>'>
                        <textarea id='workDescription' name='work_description' rows='6' placeholder='Describe identified issues, solutions implemented, and detailed work performed...' required></textarea>
                        <button type='submit' class='btn btn-success'>Log Work</button>
                    </form>
                    <div id='workLogMessage' class='message'></div>

                    ---
                    <h3>Record Parts Used (and deplete from Inventory)</h3>
                    <form id='recordPartsUsedForm' data-job-id='<?php echo $job_card_id; ?>'>
                        <label for='partUsedSelect'>Select Part Used:</label>
                        <select id='partUsedSelect' name='part_id' required>
                            <option value=''>-- Select a Part --</option>
                            <?php foreach ($parts_inventory as $part): ?>
                                <option value='<?php echo $part['part_id']; ?>' data-stock='<?php echo htmlspecialchars($part['current_stock']); ?>'><?php echo htmlspecialchars($part['part_name']); ?> (Stock: <?php echo htmlspecialchars($part['current_stock']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <label for='quantityUsed'>Quantity Used:</label>
                        <input type='number' id='quantityUsed' name='quantity' min='1' value='1' required>
                        <button type='submit' class='btn btn-primary'>Record Part Used</button>
                    </form>
                    <div id='recordPartsUsedMessage' class='message'></div>

                    ---
                    <h3>Request New Parts (if not in stock)</h3>
                    <form id='requestPartsForm' data-job-id='<?php echo $job_card_id; ?>'>
                        <label for='partRequestSelect'>Select Part for Request:</label>
                        <select id='partRequestSelect' name='part_id' required>
                            <option value=''>-- Select a Part --</option>
                            <?php foreach ($parts_inventory as $part): ?>
                                <option value='<?php echo $part['part_id']; ?>'><?php echo htmlspecialchars($part['part_name']); ?> (Stock: <?php echo htmlspecialchars($part['current_stock']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <label for='partRequestQuantity'>Quantity to Request:</label>
                        <input type='number' id='partRequestQuantity' name='quantity' min='1' value='1' required>
                        <button type='submit' class='btn btn-warning'>Request Part</button>
                    </form>
                    <div id='partsRequestMessage' class='message'></div>

                    ---
                    <h3>Upload Photos/Videos (Requires server-side implementation)</h3>
                    <form id='uploadMediaForm' data-job-id='<?php echo $job_card_id; ?>' enctype='multipart/form-data'>
                        <label for='mediaFile'>Select File:</label>
                        <input type='file' id='mediaFile' name='media_file' accept='image/*,video/*' disabled title='Media upload functionality is not yet implemented.'>
                        <button type='submit' class='btn btn-secondary' disabled>Upload Media</button>
                        <p><small><em>Note: This feature requires server-side file handling and storage setup.</em></small></p>
                    </form>
                    <div id='mediaUploadMessage' class='message'></div>

                    ---
                    <h3>Parts Used History</h3>
                    <?php if (empty($parts_used)): ?>
                        <p>No parts recorded as used for this job yet.</p>
                    <?php else: ?>
                        <div class='parts-used-list'>
                            <?php foreach ($parts_used as $part): ?>
                                <div class='used-item'>
                                    <p><strong><?php echo htmlspecialchars($part['part_name']); ?> (Part #<?php echo htmlspecialchars($part['part_number']); ?>):</strong> <?php echo htmlspecialchars($part['quantity_used']); ?> units</p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    ---
                    <h3>Work History / Job Updates</h3>
                    <?php if (empty($job_updates)): ?>
                        <p>No updates or work logs for this job yet.</p>
                    <?php else: ?>
                        <div class='job-updates-list'>
                            <?php foreach ($job_updates as $update): ?>
                                <div class='update-item'>
                                    <p><strong><?php echo htmlspecialchars($update['update_type']); ?> by <?php echo htmlspecialchars($update['mechanic_name']); ?> on <?php echo htmlspecialchars($update['timestamp']); ?>:</strong></p>
                                    <p><?php echo nl2br(htmlspecialchars($update['description'])); ?></p>
                                    <?php if (!empty($update['photo_path'])): ?>
                                        <p><a href="<?php echo htmlspecialchars($update['photo_path']); ?>" target="_blank"><i class="fas fa-image"></i> View Attachment</a></p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php
                    $response = ['success' => true, 'html' => ob_get_clean()];
                } else {
                    $response = ['success' => false, 'message' => 'Job details not found or you are not assigned to this job.'];
                }
            }
            echo json_encode($response);
            exit();

        case 'update_job_status':
            $job_card_id = isset($_POST['job_id']) ? intval($_POST['job_id']) : 0;
            $new_status = isset($_POST['new_status']) ? $_POST['new_status'] : '';

            if ($job_card_id > 0 && !empty($new_status)) {
                $conn->begin_transaction();
                try {
                    $sql_update_job = "UPDATE job_cards SET current_status = ? WHERE job_card_id = ? AND assigned_mechanic_id = ?";
                    if ($stmt_update = $conn->prepare($sql_update_job)) {
                        $stmt_update->bind_param("sii", $new_status, $job_card_id, $mechanic_id);
                        if ($stmt_update->execute()) {
                            $log_description = "Job status changed to '{$new_status}'";
                            $sql_log = "INSERT INTO job_card_updates (job_card_id, mechanic_id, update_type, description) VALUES (?, ?, 'Status Change', ?)";
                            if ($stmt_log = $conn->prepare($sql_log)) {
                                $stmt_log->bind_param("iis", $job_card_id, $mechanic_id, $log_description);
                                if (!$stmt_log->execute()) {
                                    // Log the error but don't fail the transaction if logging fails, as the main action is done
                                    error_log("Failed to log status update for job {$job_card_id}: " . $stmt_log->error);
                                }
                                $stmt_log->close();
                            } else {
                                throw new Exception("Failed to prepare log statement: " . $conn->error);
                            }
                            $conn->commit();
                            $response = ['success' => true, 'message' => 'Job status updated successfully.', 'new_status' => $new_status, 'log_entry' => ['description' => $log_description, 'timestamp' => date('Y-m-d H:i:s'), 'mechanic_name' => $mechanic_full_name, 'update_type' => 'Status Change']];
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
            echo json_encode($response);
            exit();

        case 'log_work':
            $job_card_id = isset($_POST['job_id']) ? intval($_POST['job_id']) : 0;
            $work_description = isset($_POST['work_description']) ? trim($_POST['work_description']) : '';

            if ($job_card_id > 0 && !empty($work_description)) {
                $sql_log = "INSERT INTO job_card_updates (job_card_id, mechanic_id, update_type, description) VALUES (?, ?, 'Work Log', ?)";
                if ($stmt = $conn->prepare($sql_log)) {
                    $stmt->bind_param("iis", $job_card_id, $mechanic_id, $work_description);
                    if ($stmt->execute()) {
                        $response = ['success' => true, 'message' => 'Work log added successfully.', 'log_entry' => ['description' => $work_description, 'timestamp' => date('Y-m-d H:i:s'), 'mechanic_name' => $mechanic_full_name, 'update_type' => 'Work Log']];
                    } else {
                        $response = ['success' => false, 'message' => 'Failed to log work: ' . $stmt->error];
                    }
                    $stmt->close();
                } else {
                    $response = ['success' => false, 'message' => 'Database error preparing log statement: ' . $conn->error];
                }
            } else {
                $response = ['success' => false, 'message' => 'Invalid job ID or empty work description.'];
            }
            echo json_encode($response);
            exit();

        case 'record_parts_used':
            $job_card_id = isset($_POST['job_id']) ? intval($_POST['job_id']) : 0;
            $part_id = isset($_POST['part_id']) ? intval($_POST['part_id']) : 0;
            $quantity_used = isset($_POST['quantity']) ? intval($_POST['quantity']) : 0;

            if ($job_card_id > 0 && $part_id > 0 && $quantity_used > 0) {
                $conn->begin_transaction();
                try {
                    // 1. Get part name and current stock
                    $part_name = '';
                    $part_number = ''; // Also fetch part_number
                    $current_stock = 0;
                    $sql_part_info = "SELECT part_name, part_number, current_stock FROM parts WHERE part_id = ? FOR UPDATE"; // Lock row
                    if ($stmt_part_info = $conn->prepare($sql_part_info)) {
                        $stmt_part_info->bind_param("i", $part_id);
                        $stmt_part_info->execute();
                        $result_part_info = $stmt_part_info->get_result();
                        if ($row = $result_part_info->fetch_assoc()) {
                            $part_name = $row['part_name'];
                            $part_number = $row['part_number'];
                            $current_stock = $row['current_stock'];
                        }
                        $stmt_part_info->close();
                    } else {
                        throw new Exception("Failed to prepare part info statement: " . $conn->error);
                    }

                    if (empty($part_name)) {
                        throw new Exception("Invalid part selected.");
                    }
                    if ($current_stock < $quantity_used) {
                        throw new Exception("Not enough stock for {$part_name}. Available: {$current_stock}, Requested: {$quantity_used}.");
                    }

                    // 2. Insert into job_card_parts_used
                    $sql_record_used = "INSERT INTO job_card_parts_used (job_card_id, part_id, quantity_used, recorded_by_mechanic_id) VALUES (?, ?, ?, ?)";
                    if ($stmt_record = $conn->prepare($sql_record_used)) {
                        $stmt_record->bind_param("iiii", $job_card_id, $part_id, $quantity_used, $mechanic_id);
                        if (!$stmt_record->execute()) {
                            throw new Exception("Failed to record part usage: " . $stmt_record->error);
                        }
                        $stmt_record->close();
                    } else {
                        throw new Exception("Database error preparing record parts used statement: " . $conn->error);
                    }

                    // 3. Update inventory (deduct stock)
                    $sql_update_stock = "UPDATE parts SET current_stock = current_stock - ? WHERE part_id = ?";
                    if ($stmt_update_stock = $conn->prepare($sql_update_stock)) {
                        $stmt_update_stock->bind_param("ii", $quantity_used, $part_id);
                        if (!$stmt_update_stock->execute()) {
                            throw new Exception("Failed to update part inventory: " . $stmt_update_stock->error);
                        }
                        $stmt_update_stock->close();
                    } else {
                        throw new Exception("Database error preparing stock update statement: " . $conn->error);
                    }

                    // 4. Log the action in job_card_updates
                    $log_description = "Recorded usage of {$quantity_used} x '{$part_name}' (Part #{$part_number}).";
                    $sql_log = "INSERT INTO job_card_updates (job_card_id, mechanic_id, update_type, description) VALUES (?, ?, 'Parts Used', ?)";
                    if ($stmt_log = $conn->prepare($sql_log)) {
                        $stmt_log->bind_param("iis", $job_card_id, $mechanic_id, $log_description);
                        if (!$stmt_log->execute()) {
                            // Log but don't fail transaction if logging fails, as main action is done
                            error_log("Failed to log parts used update for job {$job_card_id}: " . $stmt_log->error);
                        }
                        $stmt_log->close();
                    }

                    $conn->commit();
                    $response = ['success' => true, 'message' => 'Parts recorded and inventory updated successfully.', 'log_entry' => ['description' => $log_description, 'timestamp' => date('Y-m-d H:i:s'), 'mechanic_name' => $mechanic_full_name, 'update_type' => 'Parts Used'], 'new_stock' => ($current_stock - $quantity_used), 'part_details' => ['part_name' => $part_name, 'part_number' => $part_number, 'quantity_used' => $quantity_used]];

                } catch (Exception $e) {
                    $conn->rollback();
                    error_log("Error recording parts used: " . $e->getMessage());
                    $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
                }
            } else {
                $response = ['success' => false, 'message' => 'Invalid job ID, part, or quantity provided.'];
            }
            echo json_encode($response);
            exit();

        case 'request_parts':
            $job_card_id = isset($_POST['job_id']) ? intval($_POST['job_id']) : 0;
            $part_id = isset($_POST['part_id']) ? intval($_POST['part_id']) : 0;
            $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 0;

            if ($job_card_id > 0 && $part_id > 0 && $quantity > 0) {
                $part_name = '';
                $sql_part_name = "SELECT part_name FROM parts WHERE part_id = ?";
                if ($stmt_part_name = $conn->prepare($sql_part_name)) {
                    $stmt_part_name->bind_param("i", $part_id);
                    $stmt_part_name->execute();
                    $result_part_name = $stmt_part_name->get_result();
                    if ($row = $result_part_name->fetch_assoc()) {
                        $part_name = $row['part_name'];
                    }
                    $stmt_part_name->close();
                }

                if (empty($part_name)) {
                    $response = ['success' => false, 'message' => 'Invalid part selected.'];
                } else {
                    $conn->begin_transaction(); // Start transaction for requisition and log
                    try {
                        $sql_request = "INSERT INTO parts_requisitions (job_card_id, mechanic_id, part_id, quantity, status) VALUES (?, ?, ?, ?, 'Pending')";
                        if ($stmt_request = $conn->prepare($sql_request)) {
                            $stmt_request->bind_param("iiii", $job_card_id, $mechanic_id, $part_id, $quantity);
                            if ($stmt_request->execute()) {
                                $log_description = "Requested {$quantity} x '{$part_name}' (Part ID: {$part_id}). Requisition Status: Pending.";
                                $sql_log = "INSERT INTO job_card_updates (job_card_id, mechanic_id, update_type, description) VALUES (?, ?, 'Parts Requisition', ?)";
                                if ($stmt_log = $conn->prepare($sql_log)) {
                                    $stmt_log->bind_param("iis", $job_card_id, $mechanic_id, $log_description);
                                    if (!$stmt_log->execute()) {
                                        error_log("Failed to log parts requisition for job {$job_card_id}: " . $stmt_log->error);
                                    }
                                    $stmt_log->close();
                                }
                                $conn->commit();
                                $response = ['success' => true, 'message' => 'Part request submitted successfully.', 'log_entry' => ['description' => $log_description, 'timestamp' => date('Y-m-d H:i:s'), 'mechanic_name' => $mechanic_full_name, 'update_type' => 'Parts Requisition']];
                            } else {
                                throw new Exception("Failed to submit part request: " . $stmt_request->error);
                            }
                            $stmt_request->close();
                        } else {
                            throw new Exception("Database error preparing request statement: " . $conn->error);
                        }
                    } catch (Exception $e) {
                        $conn->rollback();
                        error_log("Error requesting parts: " . $e->getMessage());
                        $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
                    }
                }
            } else {
                $response = ['success' => false, 'message' => 'Invalid job ID, part, or quantity provided.'];
            }
            echo json_encode($response);
            exit();

        // New AJAX case for file uploads
        case 'upload_media':
            $job_card_id = isset($_POST['job_id']) ? intval($_POST['job_id']) : 0;

            if ($job_card_id > 0 && isset($_FILES['media_file']) && $_FILES['media_file']['error'] == UPLOAD_ERR_OK) {
                $upload_dir = 'uploads/job_media/'; // Define your upload directory
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }

                $file_name = uniqid() . '_' . basename($_FILES['media_file']['name']);
                $target_file = $upload_dir . $file_name;
                $file_type = mime_content_type($_FILES['media_file']['tmp_name']);

                // Basic validation for file types
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'video/mp4', 'video/quicktime'];
                if (!in_array($file_type, $allowed_types)) {
                    $response = ['success' => false, 'message' => 'Invalid file type. Only images (jpeg, png, gif) and videos (mp4, mov) are allowed.'];
                    echo json_encode($response);
                    exit();
                }

                if (move_uploaded_file($_FILES['media_file']['tmp_name'], $target_file)) {
                    $log_description = "Uploaded media file: " . htmlspecialchars($file_name);
                    $sql_log = "INSERT INTO job_card_updates (job_card_id, mechanic_id, update_type, description, photo_path) VALUES (?, ?, 'Media Upload', ?, ?)";
                    if ($stmt_log = $conn->prepare($sql_log)) {
                        $stmt_log->bind_param("iiss", $job_card_id, $mechanic_id, $log_description, $target_file);
                        if ($stmt_log->execute()) {
                            $response = ['success' => true, 'message' => 'Media uploaded and logged successfully.', 'log_entry' => ['description' => $log_description, 'timestamp' => date('Y-m-d H:i:s'), 'mechanic_name' => $mechanic_full_name, 'update_type' => 'Media Upload', 'photo_path' => $target_file]];
                        } else {
                            $response = ['success' => false, 'message' => 'Media uploaded but failed to log in database: ' . $stmt_log->error];
                            // Consider deleting the uploaded file if DB log fails
                            unlink($target_file);
                        }
                        $stmt_log->close();
                    } else {
                        $response = ['success' => false, 'message' => 'Database error preparing media log statement: ' . $conn->error];
                        unlink($target_file);
                    }
                } else {
                    $response = ['success' => false, 'message' => 'Failed to upload media file to server.'];
                }
            } else {
                $response = ['success' => false, 'message' => 'No file uploaded or invalid job ID. Error: ' . ($_FILES['media_file']['error'] ?? 'N/A')];
            }
            echo json_encode($response);
            exit();

        default:
            echo json_encode($response);
            exit();
    }
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mechanic Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Base styles from driver_portal.php combined with mechanic_dashboard styles */
        :root {
            --primary: #3498db;
            --secondary: #2c3e50;
            --success: #2ecc71;
            --warning: #f39c12;
            --light: #ecf0f1;
            --dark: #34495e;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f5f6fa;
            color: #333;
        }

        .dashboard {
            display: grid;
            grid-template-columns: 250px 1fr;
            min-height: 100vh;
        }

        .sidebar {
            background-color: var(--secondary);
            color: white;
            padding: 20px 0;
        }

        .sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .sidebar-menu {
            padding: 20px 0;
        }

        .menu-item {
            padding: 12px 20px;
            color: var(--light);
            text-decoration: none;
            display: block;
            transition: all 0.3s;
        }

        .menu-item:hover, .menu-item.active {
            background-color: rgba(255,255,255,0.1);
            color: white;
        }

        .menu-item i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }

        .main-content {
            padding: 20px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #ddd;
        }

        .user-info {
            display: flex;
            align-items: center;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 10px;
            font-weight: bold;
        }

        .card {
            background-color: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }

        .card-title {
            margin-top: 0;
            color: var(--secondary);
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }

        .btn {
            padding: 8px 15px;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            margin-top: 10px;
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
        }
        .btn-primary:hover {
            background-color: #2a7fb8;
        }

        .btn-success {
            background-color: var(--success);
            color: white;
        }
        .btn-success:hover {
            background-color: #27ae60;
        }

        .btn-warning {
            background-color: var(--warning);
            color: white;
        }
        .btn-warning:hover {
            background-color: #e67e22;
        }

        .btn-secondary {
            background-color: #95a5a6;
            color: white;
        }
        .btn-secondary:hover {
            background-color: #7f8c8d;
        }

        .job-card-list {
            display: grid;
            gap: 20px;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        }

        .job-card-item {
            background-color: #fff;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border-left: 5px solid var(--primary);
            transition: transform 0.2s ease-in-out;
            cursor: pointer;
        }
        .job-card-item:hover {
            transform: translateY(-5px);
        }

        .job-card-item h4 {
            margin-top: 0;
            color: var(--dark);
        }

        .job-card-item p {
            margin: 5px 0;
            font-size: 0.9em;
            color: #555;
        }

        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.8em;
            font-weight: bold;
            color: white;
            text-transform: capitalize;
        }

        .status-pending { background-color: #f39c12; }
        .status-in-progress { background-color: #3498db; }
        .status-completed { background-color: #2ecc71; }
        .status-on-hold { background-color: #e74c3c; }
        .status-canceled { background-color: #95a5a6; }

        /* Modal Styles */
        .modal {
            display: none; /* Hidden by default */
            position: fixed; /* Stay in place */
            z-index: 1000; /* Sit on top */
            left: 0;
            top: 0;
            width: 100%; /* Full width */
            height: 100%; /* Full height */
            overflow: auto; /* Enable scroll if needed */
            background-color: rgba(0,0,0,0.6); /* Black w/ opacity */
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .modal-content {
            background-color: #fefefe;
            margin: auto;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            width: 90%;
            max-width: 800px;
            position: relative;
            max-height: 90vh;
            overflow-y: auto;
        }

        .close-button {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            position: absolute;
            top: 15px;
            right: 25px;
        }

        .close-button:hover,
        .close-button:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }

        .message {
            margin-top: 15px;
            padding: 10px;
            border-radius: 5px;
            font-weight: bold;
        }
        .message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Forms within modal */
        .modal-content form {
            margin-bottom: 20px;
            padding: 15px;
            border: 1px solid #eee;
            border-radius: 8px;
            background-color: #f9f9f9;
        }

        .modal-content label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #555;
        }

        .modal-content select,
        .modal-content input[type='number'],
        .modal-content textarea {
            width: calc(100% - 22px);
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 1em;
        }
        .modal-content textarea {
            resize: vertical;
        }

        .modal-content button {
            width: auto;
            padding: 10px 20px;
            font-size: 1em;
        }

        .job-updates-list, .parts-used-list {
            border: 1px solid #e0e0e0;
            border-radius: 5px;
            padding: 10px;
            max-height: 250px;
            overflow-y: auto;
            background-color: #fafafa;
        }

        .update-item, .used-item {
            border-bottom: 1px dashed #eee;
            padding: 10px 0;
        }
        .update-item:last-child, .used-item:last-child {
            border-bottom: none;
        }
        .update-item p, .used-item p {
            font-size: 0.9em;
            line-height: 1.4;
        }
        .update-item strong {
            color: var(--secondary);
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <aside class="sidebar">
            <div class="sidebar-header">
                <h3>Mechanic Panel</h3>
            </div>
            <nav class="sidebar-menu">
                <a href="mechanic_dashboard.php" class="menu-item active"><i class="fas fa-wrench"></i> Assigned Jobs</a>
                <a href="#" class="menu-item"><i class="fas fa-cogs"></i> My Work History</a>
                <a href="#" class="menu-item"><i class="fas fa-tools"></i> Inventory</a>
                <a href="logout.php" class="menu-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </nav>
        </aside>

        <main class="main-content">
            <header class="header">
                <h1>Welcome, <?php echo $mechanic_full_name; ?>!</h1>
                <div class="user-info">
                    <div class="user-avatar"><?php echo $mechanic_initial; ?></div>
                    <span><?php echo $mechanic_full_name; ?></span>
                </div>
            </header>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="message error">
                    <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                </div>
            <?php endif; ?>

            <section class="assigned-jobs">
                <div class="card">
                    <h2 class="card-title">Your Assigned Jobs</h2>
                    <div class="job-card-list">
                        <?php if (empty($assigned_jobs)): ?>
                            <p>No jobs currently assigned to you. Keep up the great work!</p>
                        <?php else: ?>
                            <?php foreach ($assigned_jobs as $job): ?>
                                <div class="job-card-item" data-job-id="<?php echo htmlspecialchars($job['job_card_id']); ?>">
                                    <h4>Job #<?php echo htmlspecialchars($job['job_card_id']); ?> - <?php echo htmlspecialchars($job['registration_number']); ?></h4>
                                    <p>Vehicle: <?php echo htmlspecialchars($job['make'] . ' ' . $job['model']); ?></p>
                                    <p>Driver: <?php echo htmlspecialchars($job['driver_full_name']); ?></p>
                                    <p>Issue: <?php echo htmlspecialchars(substr($job['description'], 0, 70)) . (strlen($job['description']) > 70 ? '...' : ''); ?></p>
                                    <p>Status: <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $job['status'])); ?>"><?php echo htmlspecialchars($job['status']); ?></span></p>
                                    <button class="btn btn-primary view-details-btn">View Details</button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <div id="jobDetailsModal" class="modal">
                <div class="modal-content">
                    <span class="close-button">&times;</span>
                    <div id="jobDetailsContent">
                        Loading job details...
                    </div>
                </div>
            </div>

        </main>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            const jobDetailsModal = $('#jobDetailsModal');
            const jobDetailsContent = $('#jobDetailsContent');
            const closeButton = $('.close-button');

            // Open modal and load job details
            $('.view-details-btn').on('click', function() {
                const jobId = $(this).closest('.job-card-item').data('job-id');
                jobDetailsModal.css('display', 'flex'); // Use flex to center the modal
                jobDetailsContent.html('Loading job details...'); // Show loading message

                $.ajax({
                    url: 'mechanic_dashboard.php?action=get_job_details&job_id=' + jobId,
                    method: 'GET',
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            jobDetailsContent.html(response.html);
                            // Attach event listeners to forms inside the newly loaded content
                            attachFormListeners();
                        } else {
                            jobDetailsContent.html('<p class="message error">' + response.message + '</p>');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error("AJAX Error: ", status, error);
                        jobDetailsContent.html('<p class="message error">Error loading job details. Please try again.</p>');
                    }
                });
            });

            // Close modal
            closeButton.on('click', function() {
                jobDetailsModal.css('display', 'none');
            });

            // Close modal when clicking outside of it
            $(window).on('click', function(event) {
                if ($(event.target).is(jobDetailsModal)) {
                    jobDetailsModal.css('display', 'none');
                }
            });

            function attachFormListeners() {
                // Form Submission for Update Job Status
                $('#updateStatusForm').on('submit', function(e) {
                    e.preventDefault();
                    const jobId = $(this).data('job-id');
                    const newStatus = $('#newStatus').val();
                    const statusUpdateMessage = $('#statusUpdateMessage');

                    statusUpdateMessage.removeClass('success error').text('');

                    $.ajax({
                        url: 'mechanic_dashboard.php?action=update_job_status',
                        method: 'POST',
                        dataType: 'json',
                        data: {
                            job_id: jobId,
                            new_status: newStatus
                        },
                        success: function(response) {
                            if (response.success) {
                                statusUpdateMessage.addClass('success').text(response.message);
                                $('#currentJobStatus').text(response.new_status).removeClass().addClass('status-badge status-' + response.new_status.toLowerCase().replace(' ', '-'));
                                // Optionally, refresh the entire job list or just the specific job card status
                                // For now, we'll suggest a full refresh on modal close or specific element update
                                updateJobCardStatusInList(jobId, response.new_status);
                                appendLogEntry(response.log_entry);

                            } else {
                                statusUpdateMessage.addClass('error').text(response.message);
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error("AJAX Error: ", status, error);
                            statusUpdateMessage.addClass('error').text('Error updating status. Please try again.');
                        }
                    });
                });

                // Form Submission for Log Work Performed
                $('#logWorkForm').on('submit', function(e) {
                    e.preventDefault();
                    const jobId = $(this).data('job-id');
                    const workDescription = $('#workDescription').val();
                    const workLogMessage = $('#workLogMessage');

                    workLogMessage.removeClass('success error').text('');

                    $.ajax({
                        url: 'mechanic_dashboard.php?action=log_work',
                        method: 'POST',
                        dataType: 'json',
                        data: {
                            job_id: jobId,
                            work_description: workDescription
                        },
                        success: function(response) {
                            if (response.success) {
                                workLogMessage.addClass('success').text(response.message);
                                $('#workDescription').val(''); // Clear textarea
                                appendLogEntry(response.log_entry);
                            } else {
                                workLogMessage.addClass('error').text(response.message);
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error("AJAX Error: ", status, error);
                            workLogMessage.addClass('error').text('Error logging work. Please try again.');
                        }
                    });
                });

                // Form Submission for Record Parts Used
                $('#recordPartsUsedForm').on('submit', function(e) {
                    e.preventDefault();
                    const jobId = $(this).data('job-id');
                    const partId = $('#partUsedSelect').val();
                    const quantityUsed = $('#quantityUsed').val();
                    const recordPartsUsedMessage = $('#recordPartsUsedMessage');
                    const selectedOption = $('#partUsedSelect option:selected');
                    const partName = selectedOption.text().split(' (Stock:')[0]; // Extract part name

                    recordPartsUsedMessage.removeClass('success error').text('');

                    $.ajax({
                        url: 'mechanic_dashboard.php?action=record_parts_used',
                        method: 'POST',
                        dataType: 'json',
                        data: {
                            job_id: jobId,
                            part_id: partId,
                            quantity: quantityUsed
                        },
                        success: function(response) {
                            if (response.success) {
                                recordPartsUsedMessage.addClass('success').text(response.message);
                                // Update the stock displayed in the select option
                                selectedOption.attr('data-stock', response.new_stock);
                                selectedOption.text(partName + ' (Stock: ' + response.new_stock + ')');
                                $('#quantityUsed').val(1); // Reset quantity
                                $('#partUsedSelect').val(''); // Reset selected part

                                // Append to Parts Used History
                                let partsUsedList = $('.parts-used-list');
                                if (partsUsedList.length === 0) {
                                    // If no history exists, create the container
                                    $('<h3>Parts Used History</h3>').insertBefore(recordPartsUsedMessage.closest('form').nextAll('h3:contains("Parts Used History")'));
                                    partsUsedList = $('<div class="parts-used-list"></div>').insertAfter($('h3:contains("Parts Used History")'));
                                    partsUsedList.closest('.modal-content').find('p:contains("No parts recorded as used for this job yet.")').remove(); // Remove the "no parts" message
                                }
                                const newPartUsedEntry = `
                                    <div class='used-item'>
                                        <p><strong>${response.part_details.part_name} (Part #${response.part_details.part_number}):</strong> ${response.part_details.quantity_used} units</p>
                                    </div>
                                `;
                                partsUsedList.prepend(newPartUsedEntry); // Add to the top of the list

                                appendLogEntry(response.log_entry); // Also log as a general update
                            } else {
                                recordPartsUsedMessage.addClass('error').text(response.message);
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error("AJAX Error: ", status, error);
                            recordPartsUsedMessage.addClass('error').text('Error recording parts. Please try again.');
                        }
                    });
                });

                // Form Submission for Request New Parts
                $('#requestPartsForm').on('submit', function(e) {
                    e.preventDefault();
                    const jobId = $(this).data('job-id');
                    const partId = $('#partRequestSelect').val();
                    const quantity = $('#partRequestQuantity').val();
                    const partsRequestMessage = $('#partsRequestMessage');

                    partsRequestMessage.removeClass('success error').text('');

                    $.ajax({
                        url: 'mechanic_dashboard.php?action=request_parts',
                        method: 'POST',
                        dataType: 'json',
                        data: {
                            job_id: jobId,
                            part_id: partId,
                            quantity: quantity
                        },
                        success: function(response) {
                            if (response.success) {
                                partsRequestMessage.addClass('success').text(response.message);
                                $('#partRequestQuantity').val(1); // Reset quantity
                                $('#partRequestSelect').val(''); // Reset selected part
                                appendLogEntry(response.log_entry);
                            } else {
                                partsRequestMessage.addClass('error').text(response.message);
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error("AJAX Error: ", status, error);
                            partsRequestMessage.addClass('error').text('Error requesting parts. Please try again.');
                        }
                    });
                });

                // Form Submission for Upload Photos/Videos
                $('#uploadMediaForm').on('submit', function(e) {
                    e.preventDefault();
                    const jobId = $(this).data('job-id');
                    const mediaFile = $('#mediaFile')[0].files[0];
                    const mediaUploadMessage = $('#mediaUploadMessage');

                    mediaUploadMessage.removeClass('success error').text('');

                    if (!mediaFile) {
                        mediaUploadMessage.addClass('error').text('Please select a file to upload.');
                        return;
                    }

                    const formData = new FormData();
                    formData.append('job_id', jobId);
                    formData.append('media_file', mediaFile);

                    $.ajax({
                        url: 'mechanic_dashboard.php?action=upload_media',
                        method: 'POST',
                        dataType: 'json',
                        data: formData,
                        processData: false, // Important: tell jQuery not to process the data
                        contentType: false, // Important: tell jQuery not to set contentType
                        success: function(response) {
                            if (response.success) {
                                mediaUploadMessage.addClass('success').text(response.message);
                                $('#mediaFile').val(''); // Clear the file input
                                appendLogEntry(response.log_entry);
                            } else {
                                mediaUploadMessage.addClass('error').text(response.message);
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error("AJAX Error: ", status, error);
                            mediaUploadMessage.addClass('error').text('Error uploading media. Please check file size and type, then try again.');
                        }
                    });
                });

                // Helper function to append log entries to the job updates history
                function appendLogEntry(logEntry) {
                    let jobUpdatesList = $('.job-updates-list');
                    if (jobUpdatesList.length === 0) {
                        // If no history exists, create the container
                        $('<h3>Work History / Job Updates</h3>').insertAfter($('h3:contains("Parts Used History")')); // Adjust insertion point if needed
                        jobUpdatesList = $('<div class="job-updates-list"></div>').insertAfter($('h3:contains("Work History / Job Updates")'));
                        jobUpdatesList.closest('.modal-content').find('p:contains("No updates or work logs for this job yet.")').remove(); // Remove the "no updates" message
                    }

                    const newEntry = `
                        <div class='update-item'>
                            <p><strong>${logEntry.update_type} by ${logEntry.mechanic_name} on ${logEntry.timestamp}:</strong></p>
                            <p>${logEntry.description.replace(/\n/g, '<br>')}</p>
                            ${logEntry.photo_path ? `<p><a href="${logEntry.photo_path}" target="_blank"><i class="fas fa-image"></i> View Attachment</a></p>` : ''}
                        </div>
                    `;
                    jobUpdatesList.prepend(newEntry); // Add to the top of the list
                }

                // Helper function to update the status on the main dashboard list
                function updateJobCardStatusInList(jobId, newStatus) {
                    const jobCardItem = $(`.job-card-item[data-job-id='${jobId}']`);
                    if (jobCardItem.length) {
                        const statusBadge = jobCardItem.find('.status-badge');
                        statusBadge.text(newStatus);
                        statusBadge.removeClass().addClass('status-badge status-' + newStatus.toLowerCase().replace(' ', '-'));
                    }
                }
            }
        });
    </script>
</body>
</html>