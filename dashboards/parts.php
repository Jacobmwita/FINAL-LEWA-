<?php
// lewa/api/parts.php

// Ensure session is started for CSRF token and permission checks
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include necessary configuration and functions
require_once __DIR__ . '/../config.php'; // Provides $pdo (PDO database connection)
require_once __DIR__ . '/../functions.php'; // Provides helper functions (e.g., sanitizeInput)

// Set content type for JSON response
header('Content-Type: application/json');

// Get the HTTP request method
$method = $_SERVER['REQUEST_METHOD'];

// Get user permissions from session. Default to 0 if not set.
$can_manage_inventory = $_SESSION['can_manage_inventory'] ?? 0;

// Handle requests based on HTTP method
switch ($method) {
    case 'GET':
        handleGetParts($pdo);
        break;
    case 'POST':
        handlePostPart($pdo, $can_manage_inventory);
        break;
    case 'PUT':
        handlePutPart($pdo, $can_manage_inventory);
        break;
    case 'DELETE':
        handleDeletePart($pdo, $can_manage_inventory);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        http_response_code(405);
        break;
}

/**
 * Handles GET requests to retrieve parts data.
 * Can fetch all parts or a single part by ID.
 * @param PDO $pdo The PDO database connection object.
 */
function handleGetParts(PDO $pdo) {
    // Check if an ID is provided for a specific part
    $partId = $_GET['id'] ?? null;

    try {
        if ($partId) {
            // Fetch a single part
            $stmt = $pdo->prepare("SELECT * FROM parts WHERE id = :id");
            $stmt->bindParam(':id', $partId);
            $stmt->execute();
            $part = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($part) {
                echo json_encode(['success' => true, 'data' => [$part]]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Part not found.']);
                http_response_code(404);
            }
        } else {
            // Fetch all parts
            $stmt = $pdo->query("SELECT * FROM parts ORDER BY name ASC");
            $parts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $parts]);
        }
    } catch (PDOException $e) {
        error_log("Error fetching parts: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        http_response_code(500);
    }
}

/**
 * Handles POST requests to add a new part.
 * Requires 'can_manage_inventory' permission.
 * @param PDO $pdo The PDO database connection object.
 * @param int $can_manage_inventory Permission flag.
 */
function handlePostPart(PDO $pdo, int $can_manage_inventory) {
    // Permission check
    if ($can_manage_inventory !== 1) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized: You do not have permission to add parts.']);
        http_response_code(403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    // CSRF token validation (if sent from client, typically for POST/PUT/DELETE)
    if (!isset($input['csrf_token']) || $input['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        http_response_code(403);
        return;
    }

    // Sanitize and validate inputs (add more validation as needed)
    $name = sanitizeInput($input['name'] ?? '');
    $sku = sanitizeInput($input['sku'] ?? '');
    $description = sanitizeInput($input['description'] ?? '');
    $stock = filter_var($input['stock'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $min_stock = filter_var($input['minStock'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $price = filter_var($input['price'] ?? 0.0, FILTER_VALIDATE_FLOAT);
    $unit = sanitizeInput($input['unit'] ?? '');
    $location = sanitizeInput($input['location'] ?? '');

    if (empty($name) || empty($sku) || $stock === false || $min_stock === false || $price === false) {
        echo json_encode(['success' => false, 'message' => 'Missing or invalid required part data.']);
        http_response_code(400);
        return;
    }

    try {
        // Check for duplicate SKU
        $stmt_check_sku = $pdo->prepare("SELECT COUNT(*) FROM parts WHERE sku = :sku");
        $stmt_check_sku->bindParam(':sku', $sku);
        $stmt_check_sku->execute();
        if ($stmt_check_sku->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'SKU already exists. Please use a unique SKU.']);
            http_response_code(409); // Conflict
            return;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO parts (name, sku, description, stock, min_stock, price, unit, location, created_at, updated_at)
             VALUES (:name, :sku, :description, :stock, :min_stock, :price, :unit, :location, NOW(), NOW())"
        );
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':sku', $sku);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':stock', $stock);
        $stmt->bindParam(':min_stock', $min_stock);
        $stmt->bindParam(':price', $price);
        $stmt->bindParam(':unit', $unit);
        $stmt->bindParam(':location', $location);

        $stmt->execute();
        echo json_encode(['success' => true, 'message' => 'Part added successfully!', 'partId' => $pdo->lastInsertId()]);
    } catch (PDOException $e) {
        error_log("Error adding part: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        http_response_code(500);
    }
}

/**
 * Handles PUT requests to update an existing part.
 * Requires 'can_manage_inventory' permission.
 * @param PDO $pdo The PDO database connection object.
 * @param int $can_manage_inventory Permission flag.
 */
function handlePutPart(PDO $pdo, int $can_manage_inventory) {
    // Permission check
    if ($can_manage_inventory !== 1) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized: You do not have permission to update parts.']);
        http_response_code(403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    // CSRF token validation
    if (!isset($input['csrf_token']) || $input['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        http_response_code(403);
        return;
    }

    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Part ID is required for update.']);
        http_response_code(400);
        return;
    }

    // Sanitize and validate inputs
    $name = sanitizeInput($input['name'] ?? '');
    $sku = sanitizeInput($input['sku'] ?? '');
    $description = sanitizeInput($input['description'] ?? '');
    $stock = filter_var($input['stock'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $min_stock = filter_var($input['minStock'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $price = filter_var($input['price'] ?? null, FILTER_VALIDATE_FLOAT);
    $unit = sanitizeInput($input['unit'] ?? '');
    $location = sanitizeInput($input['location'] ?? '');

    // Construct the SET clause dynamically for fields that are provided
    $setClauses = [];
    $params = ['id' => $id];

    if (!empty($name)) { $setClauses[] = "name = :name"; $params['name'] = $name; }
    if (!empty($sku)) {
        // Check for duplicate SKU excluding the current part
        $stmt_check_sku = $pdo->prepare("SELECT COUNT(*) FROM parts WHERE sku = :sku AND id != :id");
        $stmt_check_sku->bindParam(':sku', $sku);
        $stmt_check_sku->bindParam(':id', $id);
        $stmt_check_sku->execute();
        if ($stmt_check_sku->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'SKU already exists for another part. Please use a unique SKU.']);
            http_response_code(409); // Conflict
            return;
        }
        $setClauses[] = "sku = :sku"; $params['sku'] = $sku;
    }
    if (!empty($description)) { $setClauses[] = "description = :description"; $params['description'] = $description; }
    if ($stock !== null && $stock !== false) { $setClauses[] = "stock = :stock"; $params['stock'] = $stock; }
    if ($min_stock !== null && $min_stock !== false) { $setClauses[] = "min_stock = :min_stock"; $params['min_stock'] = $min_stock; }
    if ($price !== null && $price !== false) { $setClauses[] = "price = :price"; $params['price'] = $price; }
    if (!empty($unit)) { $setClauses[] = "unit = :unit"; $params['unit'] = $unit; }
    if (!empty($location)) { $setClauses[] = "location = :location"; $params['location'] = $location; }

    if (empty($setClauses)) {
        echo json_encode(['success' => false, 'message' => 'No data provided for update.']);
        http_response_code(400);
        return;
    }

    $setClauses[] = "updated_at = NOW()"; // Always update timestamp

    $query = "UPDATE parts SET " . implode(', ', $setClauses) . " WHERE id = :id";

    try {
        $stmt = $pdo->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Part updated successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Part not found or no changes made.']);
            http_response_code(404);
        }
    } catch (PDOException $e) {
        error_log("Error updating part (ID: $id): " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        http_response_code(500);
    }
}

/**
 * Handles DELETE requests to delete a part.
 * Requires 'can_manage_inventory' permission.
 * @param PDO $pdo The PDO database connection object.
 * @param int $can_manage_inventory Permission flag.
 */
function handleDeletePart(PDO $pdo, int $can_manage_inventory) {
    // Permission check
    if ($can_manage_inventory !== 1) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized: You do not have permission to delete parts.']);
        http_response_code(403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    // CSRF token validation
    if (!isset($input['csrf_token']) || $input['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        http_response_code(403);
        return;
    }

    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Part ID is required for deletion.']);
        http_response_code(400);
        return;
    }

    try {
        // Also delete any associated usage logs for this part to maintain data integrity
        $pdo->beginTransaction();

        $stmt_delete_logs = $pdo->prepare("DELETE FROM usage_logs WHERE part_id = :part_id");
        $stmt_delete_logs->bindParam(':part_id', $id);
        $stmt_delete_logs->execute();

        // Delete the part itself
        $stmt_delete_part = $pdo->prepare("DELETE FROM parts WHERE id = :id");
        $stmt_delete_part->bindParam(':id', $id);
        $stmt_delete_part->execute();

        if ($stmt_delete_part->rowCount() > 0) {
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Part and associated usage logs deleted successfully.']);
        } else {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Part not found.']);
            http_response_code(404);
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Error deleting part (ID: $id): " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        http_response_code(500);
    }
}
?>
