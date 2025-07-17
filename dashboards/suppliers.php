<?php
$pdo = require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../functions.php';

// $logged_in_user_id = $_SESSION['user_id'] ?? null; // Not directly used in simple supplier CRUD

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

switch ($method) {
    case 'GET':
        $sql = "SELECT * FROM suppliers";
        $params = [];
        if (isset($_GET['id'])) {
            $sql .= " WHERE id = :id";
            $params[':id'] = sanitizeInput($_GET['id']);
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    case 'POST':
        // if (!($_SESSION['can_manage_inventory'] ?? 0)) { /* permission check */ }

        $name = sanitizeInput($input['name'] ?? '');
        $contactPerson = sanitizeInput($input['contactPerson'] ?? '');
        $phone = sanitizeInput($input['phone'] ?? '');
        $email = sanitizeInput($input['email'] ?? '');
        $id = uniqid('sup_', true);

        if (empty($name)) {
            echo json_encode(["success" => false, "message" => "Supplier Name is required."]);
            exit();
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO suppliers (id, name, contact_person, phone, email) VALUES (:id, :name, :contact_person, :phone, :email)");
            $stmt->execute([
                ':id' => $id,
                ':name' => $name,
                ':contact_person' => $contactPerson,
                ':phone' => $phone,
                ':email' => $email
            ]);
            echo json_encode(["success" => true, "message" => "Supplier added successfully.", "id" => $id]);
        } catch (PDOException $e) {
            error_log("Suppliers API POST error: " . $e->getMessage());
            echo json_encode(["success" => false, "message" => "Error adding supplier: " . $e->getMessage()]);
        }
        break;

    case 'PUT':
        // if (!($_SESSION['can_manage_inventory'] ?? 0)) { /* permission check */ }

        $id = sanitizeInput($input['id'] ?? '');
        $name = sanitizeInput($input['name'] ?? '');
        $contactPerson = sanitizeInput($input['contactPerson'] ?? '');
        $phone = sanitizeInput($input['phone'] ?? '');
        $email = sanitizeInput($input['email'] ?? '');

        if (empty($id) || empty($name)) {
            echo json_encode(["success" => false, "message" => "Supplier ID and Name are required for update."]);
            exit();
        }

        try {
            $stmt = $pdo->prepare("UPDATE suppliers SET name=:name, contact_person=:contact_person, phone=:phone, email=:email WHERE id=:id");
            $stmt->execute([
                ':name' => $name,
                ':contact_person' => $contactPerson,
                ':phone' => $phone,
                ':email' => $email,
                ':id' => $id
            ]);
            echo json_encode(["success" => true, "message" => "Supplier updated successfully."]);
        } catch (PDOException $e) {
            error_log("Suppliers API PUT error: " . $e->getMessage());
            echo json_encode(["success" => false, "message" => "Error updating supplier: " . $e->getMessage()]);
        }
        break;

    case 'DELETE':
        // if (!($_SESSION['can_manage_inventory'] ?? 0)) { /* permission check */ }

        if (!isset($_GET['id']) || empty($_GET['id'])) {
            echo json_encode(["success" => false, "message" => "No ID provided for deletion."]);
            exit();
        }
        $id = sanitizeInput($_GET['id']);
        try {
            $pdo->beginTransaction();
            // Important: Delete related purchase orders first due to foreign key constraints if CASCADE DELETE is not set
      

            $stmt = $pdo->prepare("DELETE FROM suppliers WHERE id=:id");
            $stmt->execute([':id' => $id]);
            $pdo->commit(); // Commit if successful

            echo json_encode(["success" => true, "message" => "Supplier deleted successfully."]);
        } catch (PDOException $e) {
            $pdo->rollBack(); // Rollback on error
            error_log("Suppliers API DELETE error: " . $e->getMessage());
            echo json_encode(["success" => false, "message" => "Error deleting supplier: " . $e->getMessage()]);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(["success" => false, "message" => "Method not allowed"]);
        break;
}
?>
