<?php
// lewa/api/usage_logs.php - Handles GET for Usage Logs and POST for new logs (e.g., when parts are used in a job card)
$pdo = require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../functions.php';

// Assuming user_id is in session from auth_check
$logged_in_user_id = $_SESSION['user_id'] ?? null;

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

switch ($method) {
    case 'GET':
        // Fetch usage logs, joining with parts and potentially job cards/users for more context
        $sql = "SELECT ul.*, p.name AS part_name, p.sku AS part_sku,
                       jc.issue_description AS job_issue,
                       u.full_name AS logged_by_user_name
                FROM usage_logs ul
                JOIN parts p ON ul.part_id = p.id
                LEFT JOIN job_cards jc ON ul.job_card_id = jc.job_card_id
                LEFT JOIN users u ON ul.logged_by = u.user_id
                ORDER BY ul.date_logged DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        echo json_encode(["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    case 'POST':
        // This endpoint could be used by mechanics (via their dashboard)
        // or automatically by the system when a job card consumes parts.
        // Permission check for logging usage (e.g., can_update_job_status or specific mechanic role)
        // if (!($_SESSION['can_request_parts'] ?? 0) && !($_SESSION['user_type'] == 'mechanic' ?? 0)) {
        //     http_response_code(403); // Forbidden
        //     echo json_encode(["success" => false, "message" => "Permission denied to log part usage."]);
        //     exit();
        // }

        $partId = sanitizeInput($input['partId'] ?? '');
        $jobCardId = sanitizeInput($input['jobCardId'] ?? null); // Optional, for parts used in a job
        $quantityUsed = (int)($input['quantityUsed'] ?? 0); // Always positive for usage
        $logType = sanitizeInput($input['logType'] ?? 'used'); // 'used' or 'received' (receipts are mostly via PO API)
        $id = uniqid('log_', true);

        if (empty($partId) || $quantityUsed <= 0) {
            echo json_encode(["success" => false, "message" => "Part ID and positive quantity are required."]);
            exit();
        }

        try {
            $pdo->beginTransaction();

            // Insert into usage_logs
            $stmt_log = $pdo->prepare("INSERT INTO usage_logs (id, part_id, job_card_id, quantity_change, log_type, date_logged, logged_by) VALUES (:id, :part_id, :job_card_id, :quantity_change, :log_type, NOW(), :logged_by)");
            $stmt_log->execute([
                ':id' => $id,
                ':part_id' => $partId,
                ':job_card_id' => $jobCardId,
                ':quantity_change' => -$quantityUsed, // Negative for parts used
                ':log_type' => $logType,
                ':logged_by' => $logged_in_user_id
            ]);

            // Decrease stock from parts inventory
            $stmt_update_part = $pdo->prepare("UPDATE parts SET stock = stock - :quantity WHERE id = :part_id AND stock >= :quantity");
            $stmt_update_part->execute([
                ':quantity' => $quantityUsed,
                ':part_id' => $partId
            ]);

            if ($stmt_update_part->rowCount() === 0) {
                // If stock update failed (e.g., not enough stock, or part not found)
                throw new PDOException("Insufficient stock for part ID: " . $partId . " or part not found.");
            }

            $pdo->commit();
            echo json_encode(["success" => true, "message" => "Part usage logged and stock updated successfully.", "id" => $id]);

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Usage Logs API POST error: " . $e->getMessage());
            http_response_code(400); // Bad Request for insufficient stock
            echo json_encode(["success" => false, "message" => "Error logging usage or updating stock: " . $e->getMessage()]);
        }
        break;

    // No PUT or DELETE typically for historical usage logs

    default:
        http_response_code(405);
        echo json_encode(["success" => false, "message" => "Method not allowed"]);
        break;
}
?>
