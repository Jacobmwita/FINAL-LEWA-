<?php
// view_invoice.php
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['user_id'])) {
    header("Location: ../user_login.php");
    exit();
}

include __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../config.php'; // Include config.php to potentially get ORG_NAME

if (!$conn) {
    error_log("Database connection failed: " . mysqli_connect_error());
    die("A critical database error occurred. Please try again later.");
}

$invoice_id = $_GET['invoice_id'] ?? null;

if (!$invoice_id) {
    die("Invoice ID is required.");
}

// Fetch invoice details
$invoice_query = "SELECT
                    i.invoice_id,
                    i.invoice_date,
                    i.labor_cost,
                    i.parts_cost,
                    i.total_amount,
                    jc.job_card_id,
                    jc.description AS job_description, -- Changed from jc.description to jc.issue_description
                    v.registration_number,
                    v.make,
                    v.model,
                    v.year,
                    v.color,
                    v.v_milage,
                    mech.full_name AS mechanic_name,
                    adv.full_name AS service_advisor_name,
                    d.full_name AS driver_name
                  FROM invoices i
                  JOIN job_cards jc ON i.job_card_id = jc.job_card_id
                  JOIN vehicles v ON jc.vehicle_id = v.vehicle_id
                  LEFT JOIN users mech ON i.assigned_to_mechanic_id = mech.user_id -- CORRECTED: Referencing i.assigned_to_mechanic_id
                  LEFT JOIN users adv ON i.service_advisor_id = adv.user_id
                  LEFT JOIN users d ON jc.created_by_user_id = d.user_id -- Driver is linked to job_card, not vehicle directly in this schema
                  WHERE i.invoice_id = ?";

$stmt = $conn->prepare($invoice_query);
if (!$stmt) {
    die("Failed to prepare invoice query: " . $conn->error);
}
$stmt->bind_param("i", $invoice_id);
$stmt->execute();
$invoice_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$invoice_data) {
    die("Invoice not found.");
}

// Fetch parts used for this job card
$parts_used = [];
$parts_query = "SELECT jcp.quantity_used, inv.item_name, inv.price AS unit_price
                FROM job_parts jcp
                JOIN inventory inv ON jcp.item_id = inv.item_id
                WHERE jcp.job_card_id = ?";
$parts_stmt = $conn->prepare($parts_query);
if ($parts_stmt) {
    $parts_stmt->bind_param("i", $invoice_data['job_card_id']);
    $parts_stmt->execute();
    $parts_result = $parts_stmt->get_result();
    while ($row = $parts_result->fetch_assoc()) {
        $parts_used[] = $row;
    }
    $parts_stmt->close();
} else {
    error_log("Failed to fetch parts for invoice: " . $conn->error);
}

$conn->close();

// Define organization name - use from config if available, otherwise a placeholder
$organizationName = defined('ORG_NAME') ? ORG_NAME : 'LEWA WILDLIFE CONSERVANCY';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #<?php echo htmlspecialchars($invoice_data['invoice_id']); ?></title>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f4f4f4;
            color: #333;
        }
        .invoice-container {
            width: 100%;
            max-width: 800px;
            margin: 20px auto;
            background-color: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            box-sizing: border-box;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #eee;
            padding-bottom: 20px;
        }
        .header h1 {
            color: #3498db;
            margin: 0;
            font-size: 2.5em;
        }
        .header p {
            margin: 5px 0;
            color: #777;
        }
        .organization-name {
            font-size: 1.8em; /* Slightly smaller than main H1, but prominent */
            font-weight: bold;
            color: #2c3e50; /* Darker color for prominence */
            margin-bottom: 10px;
        }
        .logo {
            max-width: 150px; /* Adjust as needed */
            height: auto;
            margin-bottom: 15px;
        }
        .invoice-details, .vehicle-details, .job-details, .parts-details, .summary, .signatures {
            margin-bottom: 25px;
            padding: 15px;
            border: 1px solid #eee;
            border-radius: 5px;
        }
        .invoice-details div, .vehicle-details div, .job-details div {
            margin-bottom: 10px;
        }
        .invoice-details strong, .vehicle-details strong, .job-details strong {
            display: inline-block;
            width: 150px;
            color: #555;
        }
        .parts-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        .parts-table th, .parts-table td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
        }
        .parts-table th {
            background-color: #f8f8f8;
            color: #555;
        }
        .summary {
            text-align: right;
            font-size: 1.1em;
        }
        .summary div {
            margin-bottom: 8px;
        }
        .summary .total {
            font-size: 1.4em;
            font-weight: bold;
            color: #3498db;
            border-top: 1px dashed #ccc;
            padding-top: 10px;
            margin-top: 15px;
        }
        .signatures {
            display: flex;
            justify-content: space-around;
            margin-top: 40px;
            text-align: center;
            border: none; /* Remove border for signatures section */
            padding: 0;
        }
        .signature-block {
            flex: 1;
            padding: 10px;
            border-top: 1px solid #ccc;
            margin: 0 10px;
        }
        .signature-block p {
            margin-top: 5px;
            font-size: 0.9em;
            color: #666;
        }
        .footer {
            text-align: center;
            margin-top: 50px;
            font-size: 0.8em;
            color: #888;
        }

        /* Print specific styles */
        @media print {
            body {
                background-color: #fff;
                margin: 0;
                padding: 0;
            }
            .invoice-container {
                box-shadow: none;
                border: none;
                margin: 0;
                padding: 0;
                width: 100%; /* Ensure it takes full width for print */
                max-width: none; /* Remove max-width constraint for print */
                page-break-after: always; /* Ensures only one invoice per print job */
            }
            .btn-print {
                display: none; /* Hide the print button when printing */
            }
            /* Adjust font sizes and spacing for print to fit more content */
            body, .invoice-container {
                font-size: 10pt; /* Smaller base font size for print */
            }
            .header h1 {
                font-size: 2em;
            }
            .organization-name {
                font-size: 1.5em;
            }
            .invoice-details strong, .vehicle-details strong, .job-details strong {
                width: 120px; /* Adjust width for labels */
            }
            .parts-table th, .parts-table td {
                padding: 5px; /* Reduce padding in tables */
            }
            .summary {
                font-size: 1em; /* Adjust summary font size */
            }
            .summary .total {
                font-size: 1.2em;
            }
            .signatures {
                margin-top: 20px; /* Reduce margin for signatures */
            }
            .signature-block {
                margin: 0 5px; /* Reduce margin between signature blocks */
            }
        }
        .btn-print {
            display: block;
            width: 150px;
            margin: 20px auto;
            padding: 10px 20px;
            background-color: #3498db;
            color: white;
            text-align: center;
            border-radius: 5px;
            text-decoration: none;
            font-size: 1em;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }
        .btn-print:hover {
            background-color: #288ad6;
        }
    </style>
</head>
<body>
    <div class="invoice-container">
        <div class="header">
            <img src="../images/logo6.jpg" alt="Lewa Wildlife Conservancy Logo" class="logo">
            <div class="organization-name"><?php echo htmlspecialchars($organizationName); ?></div>
            <h1>Invoice</h1>
            <p>LEWA WORKSHOP</p>
            <p> Email: info@lewaworkshop.com</p>
        </div>

        <div class="invoice-details">
            <h2>Invoice Details</h2>
            <div><strong>Invoice ID:</strong> #<?php echo htmlspecialchars($invoice_data['invoice_id']); ?></div>
            <div><strong>Invoice Date:</strong> <?php echo date('F j, Y, g:i a', strtotime($invoice_data['invoice_date'])); ?></div>
            <div><strong>Job Card ID:</strong> #<?php echo htmlspecialchars($invoice_data['job_card_id']); ?></div>
            <div><strong>Service Advisor:</strong> <?php echo htmlspecialchars($invoice_data['service_advisor_name'] ?? 'N/A'); ?></div>
            <div><strong>Assigned Mechanic:</strong> <?php echo htmlspecialchars($invoice_data['mechanic_name'] ?? 'N/A'); ?></div>
        </div>

        <div class="vehicle-details">
            <h2>Vehicle Details</h2>
            <div><strong>Registration No.:</strong> <?php echo htmlspecialchars($invoice_data['registration_number']); ?></div>
            <div><strong>Make:</strong> <?php echo htmlspecialchars($invoice_data['make']); ?></div>
            <div><strong>Model:</strong> <?php echo htmlspecialchars($invoice_data['model']); ?></div>
            <div><strong>Year:</strong> <?php echo htmlspecialchars($invoice_data['year']); ?></div>
            <div><strong>Color:</strong> <?php echo htmlspecialchars($invoice_data['color']); ?></div>
            <div><strong>Mileage:</strong> <?php echo htmlspecialchars($invoice_data['v_milage']); ?> km</div>
            <div><strong>Driver:</strong> <?php echo htmlspecialchars($invoice_data['driver_name'] ?? 'N/A'); ?></div>
        </div>

        <div class="job-details">
            <h2>Job Description</h2>
            <p><?php echo nl2br(htmlspecialchars($invoice_data['job_description'])); ?></p>
        </div>

        <?php if (!empty($parts_used)): ?>
        <div class="parts-details">
            <h2>Parts Used</h2>
            <table class="parts-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Quantity</th>
                        <th>Unit Price</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($parts_used as $part): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($part['item_name']); ?></td>
                            <td><?php echo htmlspecialchars($part['quantity_used']); ?></td>
                            <td><?php echo htmlspecialchars(number_format($part['unit_price'], 2)); ?></td>
                            <td><?php echo htmlspecialchars(number_format($part['quantity_used'] * $part['unit_price'], 2)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <div class="summary">
            <div><strong>Labor Cost:</strong> KES <?php echo htmlspecialchars(number_format($invoice_data['labor_cost'], 2)); ?></div>
            <div><strong>Parts Cost:</strong> KES <?php echo htmlspecialchars(number_format($invoice_data['parts_cost'], 2)); ?></div>
            <!-- Removed the total amount due line as per your request to keep them separate -->
        </div>

        <div class="signatures">
            <div class="signature-block">
                <p>_________________________</p>
                <p>Mechanic Signature</p>
                <p><?php echo htmlspecialchars($invoice_data['mechanic_name'] ?? 'N/A'); ?></p>
            </div>
            <div class="signature-block">
                <p>_________________________</p>
                <p>Finance Signature</p>
                <p>Date: _______________</p>
            </div>
            <div class="signature-block">
                <p>_________________________</p>
                <p>Service Advisor Signature</p>
                <p><?php echo htmlspecialchars($invoice_data['service_advisor_name'] ?? 'N/A'); ?></p>
            </div>
        </div>

        <div class="footer">
            <p>Thank you for your business!</p>
            <p>&copy; <?php echo date('Y'); ?> Lewa Workshop. All rights reserved.</p>
        </div>
    </div>
    <button class="btn-print" onclick="window.print()">Print Invoice</button>
</body>
</html>
