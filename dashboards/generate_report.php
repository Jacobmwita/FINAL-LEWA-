<?php
session_start();
header('Content-Type: application/json');

// Security check
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'supervisor') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

// Include necessary files
include '../db_connect.php';

// Sanitize and validate input
$report_type = $_GET['type'] ?? 'daily';
$start_date = $_GET['start_date'] ?? null;
$end_date = $_GET['end_date'] ?? null;

$query_condition = '';
$report_period = '';
$params = [];
$types = '';

switch ($report_type) {
    case 'daily':
        $report_period = 'Today';
        $today = date('Y-m-d');
        $query_condition = " WHERE DATE(i.invoice_date) = ?";
        $params = [$today];
        $types = 's';
        break;
    case 'weekly':
        $report_period = 'This Week';
        $start_of_week = date('Y-m-d', strtotime('monday this week'));
        $end_of_week = date('Y-m-d', strtotime('sunday this week'));
        $query_condition = " WHERE DATE(i.invoice_date) BETWEEN ? AND ?";
        $params = [$start_of_week, $end_of_week];
        $types = 'ss';
        break;
    case 'monthly':
        $report_period = 'This Month';
        $start_of_month = date('Y-m-01');
        $end_of_month = date('Y-m-t');
        $query_condition = " WHERE DATE(i.invoice_date) BETWEEN ? AND ?";
        $params = [$start_of_month, $end_of_month];
        $types = 'ss';
        break;
    case 'custom':
        if ($start_date && $end_date) {
            $report_period = "From " . htmlspecialchars($start_date) . " to " . htmlspecialchars($end_date);
            $query_condition = " WHERE DATE(i.invoice_date) BETWEEN ? AND ?";
            $params = [$start_date, $end_date];
            $types = 'ss';
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Start and end dates are required for a custom report.']);
            exit();
        }
        break;
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid report type.']);
        exit();
}

// Base query for report data
$base_query = "SELECT i.invoice_id, i.total_amount, i.labor_cost, i.parts_cost, i.invoice_date,
                      jc.job_card_id, v.registration_number, u_gen.full_name AS generated_by_name
               FROM invoices i
               JOIN job_cards jc ON i.job_card_id = jc.job_card_id
               JOIN vehicles v ON jc.vehicle_id = v.vehicle_id
               JOIN users u_gen ON i.generated_by = u_gen.user_id";

$full_query = $base_query . $query_condition . " ORDER BY i.invoice_date DESC";

try {
    $stmt = $conn->prepare($full_query);
    if (!$stmt) {
        throw new Exception("Statement preparation failed: " . $conn->error);
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();

    $invoices = [];
    $total_revenue = 0;
    $total_labor_cost = 0;
    $total_parts_cost = 0;

    while ($row = $result->fetch_assoc()) {
        $invoices[] = $row;
        $total_revenue += $row['total_amount'];
        $total_labor_cost += $row['labor_cost'];
        $total_parts_cost += $row['parts_cost'];
    }

    // Build HTML table for detailed view
    $invoices_table = '<table><thead><tr><th>Invoice #</th><th>Job Card #</th><th>Vehicle</th><th>Total Amount</th><th>Date</th></tr></thead><tbody>';
    if (!empty($invoices)) {
        foreach ($invoices as $invoice) {
            $invoices_table .= '<tr>';
            $invoices_table .= '<td>INV-' . htmlspecialchars($invoice['invoice_id']) . '</td>';
            $invoices_table .= '<td>JC-' . htmlspecialchars($invoice['job_card_id']) . '</td>';
            $invoices_table .= '<td>' . htmlspecialchars($invoice['registration_number']) . '</td>';
            $invoices_table .= '<td>KSh ' . number_format($invoice['total_amount'], 2) . '</td>';
            $invoices_table .= '<td>' . htmlspecialchars(date('M d, Y', strtotime($invoice['invoice_date']))) . '</td>';
            $invoices_table .= '</tr>';
        }
    } else {
        $invoices_table .= '<tr><td colspan="5" style="text-align: center;">No invoices found for this period.</td></tr>';
    }
    $invoices_table .= '</tbody></table>';
    
    $stmt->close();
    $conn->close();

    echo json_encode([
        'success' => true,
        'report_period' => $report_period,
        'total_invoices' => count($invoices),
        'total_revenue' => $total_revenue,
        'total_labor_cost' => $total_labor_cost,
        'total_parts_cost' => $total_parts_cost,
        'invoices_table' => $invoices_table
    ]);

} catch (Exception $e) {
    error_log("Report generation failed: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to generate report: ' . $e->getMessage()]);
    if ($conn) {
        $conn->close();
    }
}
?>
