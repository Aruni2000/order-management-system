<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/connection/db_connection.php');

if ((int)($_SESSION['is_main_admin'] ?? 0) !== 1 || !in_array((int)($_SESSION['role_id'] ?? 0), [1, 3], true)) {
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$supplier_id = intval($input['supplier_id'] ?? 0);
$new_status = $input['new_status'] ?? '';

// CSRF validation for JSON-body requests
if (!isset($input['csrf_token']) || !isset($_SESSION['csrf_token']) || $input['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'message' => 'Security token mismatch.']);
    exit();
}

if ($supplier_id <= 0 || !in_array($new_status, ['active', 'inactive'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
    exit();
}

try {
    $stmt = $conn->prepare("UPDATE suppliers SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $new_status, $supplier_id);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo json_encode(['success' => true, 'message' => 'Supplier status updated successfully.']);
        } else {
            echo json_encode(['success' => true, 'message' => 'No changes made.']);
        }
    } else {
        throw new Exception("Database error: " . $stmt->error);
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Toggle supplier status error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred.']);
} finally {
    if (isset($conn)) $conn->close();
}
