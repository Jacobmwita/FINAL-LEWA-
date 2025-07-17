<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Start the session at the very beginning
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Ensure db_connect.php returns the PDO object
// Make sure your db_connect.php file *returns* the $pdo object, like this:
// try { $pdo = new PDO(...); return $pdo; } catch (PDOException $e) { ... }
$pdo = require_once dirname(__DIR__) . '/db_connect.php'; // Corrected path to db_connect.php

// Check if $pdo is a valid PDO object
if (!($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database connection object is invalid."]);
    exit();
}

require_once __DIR__ . '/../functions.php';

// Assuming user_id is in session from auth_check
$logged_in_user_id = $_SESSION['user_id'] ?? null;

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

switch ($method) {
    case 'GET':
        // Fetch all purchase orders with supplier name, and optionally their items
        $sql = "SELECT po.*, s.name AS supplier_name
                FROM purchase_orders po
                JOIN suppliers s ON po.supplier_id = s.id";
        $params = [];

        if (isset($_GET['id'])) {
            $sql .= " WHERE po.id = :id";
            $params[':id'] = sanitizeInput($_GET['id']);
        }
        $sql .= " ORDER BY po.date_created DESC";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $purchaseOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch items for each PO
            foreach ($purchaseOrders as &$po) {
                $item_stmt = $pdo->prepare("SELECT poi.*, p.name AS part_name, p.sku AS part_sku, p.unit
                                            FROM purchase_order_items poi
                                            JOIN parts p ON poi.part_id = p.id
                                            WHERE poi.po_id = :po_id");
                $item_stmt->execute([':po_id' => $po['id']]);
                $po['items'] = $item_stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            echo json_encode(["success" => true, "data" => $purchaseOrders]);
        } catch (PDOException $e) {
            error_log("PO API GET error: " . $e->getMessage());
            echo json_encode(["success" => false, "message" => "Error fetching Purchase Orders: " . $e->getMessage()]);
        }
        break;

    case 'POST':
        // Check for permission (uncomment and adjust as needed)
        // if (!($_SESSION['can_request_parts'] ?? 0)) {
        //     http_response_code(403); // Forbidden
        //     echo json_encode(["success" => false, "message" => "Permission denied to create purchase orders."]);
        //     exit();
        // }

        $supplierId = sanitizeInput($input['supplierId'] ?? '');
        $items = $input['items'] ?? []; // Array of {partId, quantity, unitPrice}
        $totalCost = (float)($input['totalCost'] ?? 0.0);
        $po_id = uniqid('PO_', true); // Generate unique ID for PO

        if (empty($supplierId) || empty($items)) {
            echo json_encode(["success" => false, "message" => "Supplier and items are required."]);
            exit();
        }

        try {
            $pdo->beginTransaction();

            // Insert into purchase_orders table
            $stmt_po = $pdo->prepare("INSERT INTO purchase_orders (id, supplier_id, date_created, status, total_cost, created_by) VALUES (:id, :supplier_id, NOW(), 'Pending', :total_cost, :created_by)");
            $stmt_po->execute([
                ':id' => $po_id,
                ':supplier_id' => $supplierId,
                ':total_cost' => $totalCost,
                ':created_by' => $logged_in_user_id // Assuming user_id is available
            ]);

            // Insert into purchase_order_items table
            $stmt_item = $pdo->prepare("INSERT INTO purchase_order_items (po_id, part_id, quantity, unit_price) VALUES (:po_id, :part_id, :quantity, :unit_price)");
            foreach ($items as $item) {
                $partId = sanitizeInput($item['partId']);
                $quantity = (int)$item['quantity'];
                $unitPrice = (float)$item['unitPrice'];

                // Basic validation for items
                if (empty($partId) || $quantity <= 0 || $unitPrice < 0) {
                     $pdo->rollBack();
                     echo json_encode(["success" => false, "message" => "Invalid part data provided for PO item."]);
                     exit();
                }

                $stmt_item->execute([
                    ':po_id' => $po_id,
                    ':part_id' => $partId,
                    ':quantity' => $quantity,
                    ':unit_price' => $unitPrice
                ]);
            }

            $pdo->commit();
            echo json_encode(["success" => true, "message" => "Purchase Order created successfully.", "id" => $po_id]);

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("PO API POST error: " . $e->getMessage());
            echo json_encode(["success" => false, "message" => "Error creating Purchase Order: " . $e->getMessage()]);
        }
        break;

    case 'PUT':
        // Check for permission (uncomment and adjust as needed)
        // if (!($_SESSION['can_manage_inventory'] ?? 0)) { // Assuming PO status update is part of managing inventory
        //     http_response_code(403); // Forbidden
        //     echo json_encode(["success" => false, "message" => "Permission denied to update purchase orders."]);
        //     exit();
        // }

        $id = sanitizeInput($input['id'] ?? '');
        $status = sanitizeInput($input['status'] ?? '');

        if (empty($id) || empty($status)) {
            echo json_encode(["success" => false, "message" => "PO ID and status are required for update."]);
            exit();
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE purchase_orders SET status = :status WHERE id = :id");
            $stmt->execute([':status' => $status, ':id' => $id]);

            // If status is 'Received', update part stock and log usage
            if ($status === 'Received') {
                $item_stmt = $pdo->prepare("SELECT part_id, quantity FROM purchase_order_items WHERE po_id = :po_id");
                $item_stmt->execute([':po_id' => $id]);
                $po_items = $item_stmt->fetchAll(PDO::FETCH_ASSOC);

                $update_part_stmt = $pdo->prepare("UPDATE parts SET stock = stock + :quantity WHERE id = :part_id");
                $log_usage_stmt = $pdo->prepare("INSERT INTO usage_logs (id, part_id, purchase_order_id, quantity_change, log_type, date_logged, logged_by) VALUES (:id, :part_id, :purchase_order_id, :quantity_change, 'received', NOW(), :logged_by)");

                foreach ($po_items as $item) {
                    $update_part_stmt->execute([
                        ':quantity' => $item['quantity'],
                        ':part_id' => $item['part_id']
                    ]);
                    $log_usage_stmt->execute([
                        ':id' => uniqid('log_', true),
                        ':part_id' => $item['part_id'],
                        ':purchase_order_id' => $id,
                        ':quantity_change' => $item['quantity'], // Positive for incoming stock
                        ':logged_by' => $logged_in_user_id
                    ]);
                }
            }

            $pdo->commit();
            echo json_encode(["success" => true, "message" => "Purchase Order status updated successfully."]);

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("PO API PUT error: " . $e->getMessage());
            echo json_encode(["success" => false, "message" => "Error updating Purchase Order: " . $e->getMessage()]);
        }
        break;

    case 'DELETE':
        // Check for permission (uncomment and adjust as needed)
        // if (!($_SESSION['can_manage_inventory'] ?? 0)) { // Assuming PO deletion is part of managing inventory
        //     http_response_code(403); // Forbidden
        //     echo json_encode(["success" => false, "message" => "Permission denied to delete purchase orders."]);
        //     exit();
        // }

        if (!isset($_GET['id']) || empty($_GET['id'])) {
            echo json_encode(["success" => false, "message" => "No ID provided for deletion."]);
            exit();
        }
        $id = sanitizeInput($_GET['id']);
        try {
            $pdo->beginTransaction();
            // Delete items first due to foreign key constraint
            $stmt_items = $pdo->prepare("DELETE FROM purchase_order_items WHERE po_id = :id");
            $stmt_items->execute([':id' => $id]);

            $stmt_po = $pdo->prepare("DELETE FROM purchase_orders WHERE id = :id");
            $stmt_po->execute([':id' => $id]);
            $pdo->commit();
            echo json_encode(["success" => true, "message" => "Purchase Order deleted successfully."]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("PO API DELETE error: " . $e->getMessage());
            echo json_encode(["success" => false, "message" => "Error deleting Purchase Order: " . $e->getMessage()]);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(["success" => false, "message" => "Method not allowed"]);
        break;
}
?>
