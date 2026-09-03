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

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

$input = json_decode(file_get_contents('php://input'), true);
$grn_id = intval($input['grn_id'] ?? 0);

// CSRF validation for JSON-body requests
if (!isset($input['csrf_token']) || !isset($_SESSION['csrf_token']) || $input['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'message' => 'Security token mismatch.']);
    exit();
}

if ($grn_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid GRN ID.']);
    exit();
}

try {
    // Check GRN exists and is draft
    $checkStmt = $conn->prepare("SELECT grn_number, status FROM grn WHERE grn_id = ?");
    $checkStmt->bind_param("i", $grn_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'GRN not found.']);
        exit();
    }

    $grnData = $checkResult->fetch_assoc();
    $checkStmt->close();

    if ($grnData['status'] !== 'draft') {
        echo json_encode(['success' => false, 'message' => 'Only draft GRNs can be confirmed. Current status: ' . $grnData['status']]);
        exit();
    }

    // Get all items (prepared statement for consistency)
    $itemsStmt = $conn->prepare("SELECT product_id, quantity FROM grn_items WHERE grn_id = ?");
    $itemsStmt->bind_param("i", $grn_id);
    $itemsStmt->execute();
    $itemsResult = $itemsStmt->get_result();
    $itemsStmt->close();
    if (!$itemsResult || $itemsResult->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'No items found in this GRN.']);
        exit();
    }
    $item_count = $itemsResult->num_rows; // Save before iterating

    // Start transaction
    $conn->begin_transaction();

    // Update stock for each item
    $updateStock = $conn->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?");
    while ($item = $itemsResult->fetch_assoc()) {
        $qty = intval($item['quantity']);
        $pid = intval($item['product_id']);
        $updateStock->bind_param("ii", $qty, $pid);
        if (!$updateStock->execute()) {
            throw new Exception("Failed to update stock for product ID " . $pid);
        }
    }
    $updateStock->close();

    // Update GRN status
    $updateGrn = $conn->prepare("UPDATE grn SET status = 'confirmed' WHERE grn_id = ?");
    $updateGrn->bind_param("i", $grn_id);
    if (!$updateGrn->execute()) {
        throw new Exception("Failed to update GRN status.");
    }
    $updateGrn->close();

    // Log action
    if (isset($_SESSION['user_id'])) {
        $logStmt = $conn->prepare("INSERT INTO user_logs (user_id, action_type, inquiry_id, details) VALUES (?, ?, ?, ?)");
        $action_type = 'grn_confirm';
        $details = "GRN confirmed - Number: {$grnData['grn_number']}, Stock updated for {$item_count} items";
        $logStmt->bind_param("isis", $_SESSION['user_id'], $action_type, $grn_id, $details);
        $logStmt->execute();
        $logStmt->close();
    }

    $conn->commit();

    echo json_encode(['success' => true, 'message' => 'GRN confirmed successfully! Stock quantities have been updated.']);

} catch (Exception $e) {
    $conn->rollback();
    error_log("GRN confirm error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred while confirming the GRN.']);
} finally {
    if (isset($conn)) $conn->close();
}
