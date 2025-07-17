<?php
session_start(); // Start session for access to $_SESSION variables

// SECURITY CHECKUP for the API endpoint
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'supervisor') {
    http_response_code(403); // Forbidden
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

// Include MySQLi database connection
include '../db_connect.php'; // Adjust path relative to this file

// DATABASE CONNECTION VALIDATION
if (!isset($conn) || $conn->connect_error) {
    error_log("FATAL ERROR: Database connection failed in " . __FILE__ . ": " . ($conn->connect_error ?? 'Connection object not set.'));
    http_response_code(500); // Internal Server Error
    echo json_encode(['success' => false, 'message' => 'A critical system error occurred. Please try again later.']);
    exit();
}

// Include other necessary files
require_once '../../includes/functions.php'; // For formatDate

header('Content-Type: application/json');

$response = ['success' => false, 'message' => '', 'html' => ''];

$input = file_get_contents('php://input');
$data = json_decode($input, true);

$startDate = $data['start_date'] ?? null;
$endDate = $data['end_date'] ?? null;
$reportType = $data['report_type'] ?? 'summary'; // Default to summary

if (!$startDate || !$endDate || !in_array($reportType, ['summary', 'detailed', 'invoice'])) {
    $response['message'] = 'Invalid parameters for custom report.';
    echo json_encode($response);
    $conn->close();
    exit();
}

// Add time components to dates for full day range
$startDate = $startDate . ' 00:00:00';
$endDate = $endDate . ' 23:59:59';

try {
    ob_start(); // Start output buffering
    ?>
    <div class="report-summary">
        <h3>Custom Financial Report (<?php echo htmlspecialchars(ucfirst($reportType)); ?>)</h3>
        <p>Period: <strong><?php echo htmlspecialchars(date('M d, Y', strtotime($startDate))); ?></strong> to <strong><?php echo htmlspecialchars(date('M d, Y', strtotime($endDate))); ?></strong></p>
    </div>
    <?php

    if ($reportType === 'summary' || $reportType === 'detailed') {
        // Fetch job cards for summary/detailed reports
        $jobCards = [];
        $stmtJobs = $conn->prepare("SELECT j.*, u.full_name as created_by_name
                                   FROM job_cards j
                                   JOIN users u ON j.created_by = u.id
                                   WHERE j.created_at BETWEEN ? AND ?
                                   ORDER BY j.created_at DESC");
        if (!$stmtJobs) {
            throw new Exception("Failed to prepare statement for job cards fetch: " . $conn->error);
        }
        $stmtJobs->bind_param("ss", $startDate, $endDate);
        $stmtJobs->execute();
        $resultJobs = $stmtJobs->get_result();
        $jobCards = $resultJobs->fetch_all(MYSQLI_ASSOC);
        $stmtJobs->close();

        // Fetch invoices for summary/detailed reports
        $invoices = [];
        $stmtInvoices = $conn->prepare("SELECT i.*, j.customer_name, j.vehicle_model, u.full_name as generated_by_name
                                     FROM invoices i
                                     JOIN job_cards j ON i.job_card_id = j.id
                                     JOIN users u ON i.generated_by = u.id
                                     WHERE i.created_at BETWEEN ? AND ?
                                     ORDER BY i.created_at DESC");
        if (!$stmtInvoices) {
            throw new Exception("Failed to prepare statement for invoices fetch: " . $conn->error);
        }
        $stmtInvoices->bind_param("ss", $startDate, $endDate);
        $stmtInvoices->execute();
        $resultInvoices = $stmtInvoices->get_result();
        $invoices = $resultInvoices->fetch_all(MYSQLI_ASSOC);
        $stmtInvoices->close();

        $totalRevenue = array_sum(array_column($invoices, 'amount'));
        $totalJobsCreated = count($jobCards);
        $totalInvoicesGenerated = count($invoices);

        if ($reportType === 'summary') {
            ?>
            <p>Total Revenue from Invoices: <strong>$<?php echo number_format($totalRevenue, 2); ?></strong></p>
            <p>Total Job Cards Created: <strong><?php echo $totalJobsCreated; ?></strong></p>
            <p>Total Invoices Generated: <strong><?php echo $totalInvoicesGenerated; ?></strong></p>
            <?php
        } elseif ($reportType === 'detailed') {
            ?>
            <h4>Job Cards Created in Period</h4>
            <?php if (count($jobCards) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Customer</th>
                        <th>Vehicle</th>
                        <th>Status</th>
                        <th>Created By</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jobCards as $job): ?>
                    <tr>
                        <td>JC-<?php echo htmlspecialchars($job['id']); ?></td>
                        <td><?php echo htmlspecialchars($job['customer_name']); ?></td>
                        <td><?php echo htmlspecialchars($job['vehicle_model']); ?></td>
                        <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $job['status']))); ?></td>
                        <td><?php echo htmlspecialchars($job['created_by_name']); ?></td>
                        <td><?php echo formatDate($job['created_at']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p>No job cards created in this period.</p>
            <?php endif; ?>

            <h4 style="margin-top: 20px;">Invoices Generated in Period</h4>
            <?php if (count($invoices) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Job Card ID</th>
                        <th>Customer</th>
                        <th>Vehicle</th>
                        <th>Amount</th>
                        <th>Generated By</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $invoice): ?>
                    <tr>
                        <td>INV-<?php echo htmlspecialchars($invoice['id']); ?></td>
                        <td>JC-<?php echo htmlspecialchars($invoice['job_card_id']); ?></td>
                        <td><?php echo htmlspecialchars($invoice['customer_name']); ?></td>
                        <td><?php echo htmlspecialchars($invoice['vehicle_model']); ?></td>
                        <td>$<?php echo number_format($invoice['amount'], 2); ?></td>
                        <td><?php echo htmlspecialchars($invoice['generated_by_name']); ?></td>
                        <td><?php echo formatDate($invoice['created_at']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p>No invoices generated in this period.</p>
            <?php endif; ?>
            <?php
        }
    } elseif ($reportType === 'invoice') {
        // Fetch only invoices for invoice report type
        $invoices = [];
        $stmtInvoices = $conn->prepare("SELECT i.*, j.customer_name, j.vehicle_model, u.full_name as generated_by_name
                                     FROM invoices i
                                     JOIN job_cards j ON i.job_card_id = j.id
                                     JOIN users u ON i.generated_by = u.id
                                     WHERE i.created_at BETWEEN ? AND ?
                                     ORDER BY i.created_at DESC");
        if (!$stmtInvoices) {
            throw new Exception("Failed to prepare statement for invoice report: " . $conn->error);
        }
        $stmtInvoices->bind_param("ss", $startDate, $endDate);
        $stmtInvoices->execute();
        $resultInvoices = $stmtInvoices->get_result();
        $invoices = $resultInvoices->fetch_all(MYSQLI_ASSOC);
        $stmtInvoices->close();

        ?>
        <h4>Invoices in Period</h4>
        <?php if (count($invoices) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Invoice #</th>
                    <th>Job Card ID</th>
                    <th>Customer</th>
                    <th>Vehicle</th>
                    <th>Amount</th>
                    <th>Generated By</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoices as $invoice): ?>
                <tr>
                    <td>INV-<?php echo htmlspecialchars($invoice['id']); ?></td>
                    <td>JC-<?php echo htmlspecialchars($invoice['job_card_id']); ?></td>
                    <td><?php echo htmlspecialchars($invoice['customer_name']); ?></td>
                    <td><?php echo htmlspecialchars($invoice['vehicle_model']); ?></td>
                    <td>$<?php echo number_format($invoice['amount'], 2); ?></td>
                    <td><?php echo htmlspecialchars($invoice['generated_by_name']); ?></td>
                    <td><?php echo formatDate($invoice['created_at']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p>No invoices found for this period.</p>
        <?php endif; ?>
        <?php
    }

    $response['html'] = ob_get_clean(); // Get the buffered content
    $response['success'] = true;

} catch (Exception $e) {
    error_log("Custom report generation failed: " . $e->getMessage());
    $response['message'] = 'Database error: ' . $e->getMessage();
} finally {
    $conn->close();
}

echo json_encode($response);
?>
