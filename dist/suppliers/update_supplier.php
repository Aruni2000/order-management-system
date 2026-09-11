<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}
if ((int)($_SESSION['is_main_admin'] ?? 0) !== 1 || !in_array((int)($_SESSION['role_id'] ?? 0), [1, 3], true)) {
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/connection/db_connection.php');

if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'message' => 'Security token mismatch.']);
    exit();
}

$response = ['success' => false, 'message' => '', 'errors' => []];

function sanitizeInput($input) { return trim(htmlspecialchars($input, ENT_QUOTES, 'UTF-8')); }

try {
    $supplier_id = intval($_POST['supplier_id'] ?? 0);
    if ($supplier_id <= 0) {
        $response['message'] = 'Invalid supplier ID.';
        echo json_encode($response);
        exit();
    }

    $name = sanitizeInput($_POST['name'] ?? '');
    $contact_person = sanitizeInput($_POST['contact_person'] ?? '');
    $phone = sanitizeInput($_POST['phone'] ?? '');
    $email = sanitizeInput($_POST['email'] ?? '');
    $address = sanitizeInput($_POST['address'] ?? '');
    $status = sanitizeInput($_POST['status'] ?? 'active');
    $statusStmt = $conn->prepare("SELECT status FROM suppliers WHERE id = ?");
    $statusStmt->bind_param("i", $supplier_id);
    $statusStmt->execute();
    $existingSupplier = $statusStmt->get_result()->fetch_assoc();
    $statusStmt->close();
    if ($existingSupplier) {
        $status = sanitizeInput($existingSupplier['status']);
    }

    if (empty($name)) {
        $response['errors']['name'] = 'Supplier name is required';
        $response['message'] = 'Please correct the errors below.';
        echo json_encode($response);
        exit();
    }

    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $response['errors']['email'] = 'Invalid email format';
        $response['message'] = 'Please correct the errors below.';
        echo json_encode($response);
        exit();
    }

    $updateQuery = "UPDATE suppliers SET name = ?, contact_person = ?, phone = ?, email = ?, address = ?, status = ? WHERE id = ?";
    $stmt = $conn->prepare($updateQuery);
    if (!$stmt) throw new Exception("Database prepare error: " . $conn->error);
    $stmt->bind_param("ssssssi", $name, $contact_person, $phone, $email, $address, $status, $supplier_id);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            $response['success'] = true;
            $response['message'] = "Supplier '{$name}' has been updated successfully!";
        } else {
            $response['success'] = true;
            $response['message'] = "No changes were made to the supplier.";
        }
        $stmt->close();
    } else {
        throw new Exception("Database execution error: " . $stmt->error);
    }
} catch (Exception $e) {
    error_log("Supplier update error: " . $e->getMessage());
    $response['message'] = 'An error occurred while updating the supplier.';
} finally {
    if (isset($conn)) $conn->close();
}

echo json_encode($response);
exit();
