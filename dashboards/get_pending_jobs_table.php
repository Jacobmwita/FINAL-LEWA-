<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

include __DIR__ . '/../db_connect.php';

if (!$conn) {
    error_log("Database connection failed in get_pending_jobs_table.php: " . mysqli_connect_error());
    // Optionally return an empty string or error message for AJAX
    echo ""; 
    exit();
}

$jobs_query = "SELECT j.job_card_id, j.description, j.created_at, j.completed_at,
                         v.make, v.model, v.registration_number,
                         d.full_name as driver_name
                FROM job_cards j
                JOIN vehicles v ON j.vehicle_id = v.vehicle_id
                JOIN users d ON j.driver_id = d.user_id
                WHERE j.status = 'pending'
                ORDER BY j.created_at DESC";

$jobs_stmt = $conn->prepare($jobs_query);
$jobs = []; 
if ($jobs_stmt) {
    $jobs_stmt->execute();
    $result = $jobs_stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $jobs[] = $row;
    }
    $jobs_stmt->close();
} else {
    error_log("Failed to prepare pending job cards query for AJAX: " . $conn->error);
}
$conn->close();

if (count($jobs) > 0) {
    foreach ($jobs as $job): ?>
        <tr id="job-<?php echo htmlspecialchars($job['job_card_id']); ?>">
            <td>#<?php echo htmlspecialchars($job['job_card_id']); ?></td>
            <td>
                <?php echo htmlspecialchars($job['make'] . ' ' . $job['model']); ?>
                <br><small><?php echo htmlspecialchars($job['registration_number']); ?></small>
            </td>
            <td><?php echo htmlspecialchars($job['driver_name']); ?></td>
            <td><?php echo htmlspecialchars($job['description']); ?></td>
            <td><?php echo date('M d, H:i', strtotime($job['created_at'])); ?></td>
            <td>
                <button type="button" class="btn btn-primary open-assign-modal-btn" 
                        data-job-id="<?php echo htmlspecialchars($job['job_card_id']); ?>"
                        data-vehicle-info="<?php echo htmlspecialchars($job['make'] . ' ' . $job['model'] . ' (' . $job['registration_number'] . ')'); ?>"
                        data-job-description="<?php echo htmlspecialchars($job['description']); ?>">
                    <i class="fas fa-user-check"></i> Assign Mechanic & Parts
                </button>
            </td>
        </tr>
    <?php endforeach;
} else {
    // No jobs, so return an empty string to indicate no rows
    echo "";
}
?>