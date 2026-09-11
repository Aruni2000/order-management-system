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

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'message' => 'Security token mismatch.']);
    exit();
}

$response = ['success' => false, 'message' => '', 'errors' => []];

function sanitizeInput($input) {
    return trim(htmlspecialchars($input, ENT_QUOTES, 'UTF-8'));
}

try {
    $name = sanitizeInput($_POST['name'] ?? '');
    $contact_person = sanitizeInput($_POST['contact_person'] ?? '');
    $phone = sanitizeInput($_POST['phone'] ?? '');
    $email = sanitizeInput($_POST['email'] ?? '');
    $address = sanitizeInput($_POST['address'] ?? '');
    $status = sanitizeInput($_POST['status'] ?? 'active');

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

    $insertQuery = "INSERT INTO suppliers (name, contact_person, phone, email, address, status) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($insertQuery);
    if (!$stmt) throw new Exception("Database prepare error: " . $conn->error);
    $stmt->bind_param("ssssss", $name, $contact_person, $phone, $email, $address, $status);

    if ($stmt->execute()) {
        $supplier_id = $conn->insert_id;

        // Log action
        if (isset($_SESSION['user_id'])) {
            $logQuery = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details) VALUES (?, ?, ?, ?)";
            $logStmt = $conn->prepare($logQuery);
            if ($logStmt) {
                $action_type = 'supplier_create';
                $details = "New supplier created - Name: {$name}, Phone: {$phone}, Email: {$email}";
                $logStmt->bind_param("isis", $_SESSION['user_id'], $action_type, $supplier_id, $details);
                $logStmt->execute();
                $logStmt->close();
            }
        }

        $response['success'] = true;
        $response['message'] = "Supplier '{$name}' has been successfully added!";
        $response['supplier_id'] = $supplier_id;
        $stmt->close();
    } else {
        throw new Exception("Database execution error: " . $stmt->error);
    }
} catch (Exception $e) {
    error_log("Supplier creation error: " . $e->getMessage());
    $response['message'] = 'An error occurred while adding the supplier.';
} finally {
    if (isset($conn)) $conn->close();
}

echo json_encode($response);
exit();
