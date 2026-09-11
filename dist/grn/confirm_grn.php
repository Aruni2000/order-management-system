<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}
if (!isset($_SESSION['is_main_admin']) || $_SESSION['is_main_admin'] != 1 || !in_array((int)($_SESSION['role_id'] ?? 0), [1, 3], true)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Only main admin can confirm GRNs.']);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/connection/db_connection.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/include/stock_ledger.php');

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
    $checkStmt = $conn->prepare("SELECT grn_number, status, tenant_id FROM grn WHERE grn_id = ?");
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

    $conn->begin_transaction();

    $itemsStmt = $conn->prepare("SELECT product_id, quantity, id AS grn_item_id FROM grn_items WHERE grn_id = ?");
    $itemsStmt->bind_param("i", $grn_id);
    $itemsStmt->execute();
    $itemsResult = $itemsStmt->get_result();
    $itemsStmt->close();
    if (!$itemsResult || $itemsResult->num_rows === 0) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'No items found in this GRN.']);
        exit();
    }
    $item_count = $itemsResult->num_rows; // Save before iterating

    $guardStmt = $conn->prepare("UPDATE grn SET status = 'confirmed' WHERE grn_id = ? AND status = 'draft'");
    $guardStmt->bind_param("i", $grn_id);
    $guardStmt->execute();
    if ($guardStmt->affected_rows === 0) {
        $guardStmt->close();
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Only draft GRNs can be confirmed. It may have been confirmed or cancelled by another session.']);
        exit();
    }
    $guardStmt->close();

    // Update stock for each item (only if allow_inventory is enabled)
    if (isset($_SESSION['allow_inventory']) && $_SESSION['allow_inventory'] == 1) {
        $updateStock = $conn->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ? AND tenant_id = ?");
        $grn_tenant_id = (int)($grnData['tenant_id'] ?? 0);
        while ($item = $itemsResult->fetch_assoc()) {
            $qty = intval($item['quantity']);
            $pid = intval($item['product_id']);
            $updateStock->bind_param("iii", $qty, $pid, $grn_tenant_id);
            if (!$updateStock->execute()) {
                throw new Exception("Failed to update stock for product ID " . $pid);
            }
            if ($updateStock->affected_rows === 0) {
                throw new Exception("Product does not belong to the GRN company (ID " . $pid . ")");
            }
            $batchIdStmt = $conn->prepare("SELECT batch_id FROM batches WHERE grn_item_id = ? LIMIT 1");
            $batchIdStmt->bind_param("i", $item['grn_item_id']);
            $batchIdStmt->execute();
            $batchRow = $batchIdStmt->get_result()->fetch_assoc();
            $batchIdStmt->close();
            $batchId = $batchRow ? (int)$batchRow['batch_id'] : null;

            log_stock_movement($conn, $grn_tenant_id, $pid, $batchId, 'grn_in', $qty, 'grn', (int)$grn_id, isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null, 'GRN ' . $grnData['grn_number'] . ' confirmed');
        }
        $updateStock->close();
    }

    // Activate batch records for this GRN (draft -> confirmed) and set remaining_qty
    $activateBatch = $conn->prepare("UPDATE batches SET status = 'confirmed', remaining_qty = received_qty, received_date = (SELECT received_date FROM grn WHERE grn_id = ?) WHERE grn_id = ? AND status = 'draft'");
    $activateBatch->bind_param("ii", $grn_id, $grn_id);
    if (!$activateBatch->execute()) {
        throw new Exception("Failed to activate batch records.");
    }
    $activateBatch->close();


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
