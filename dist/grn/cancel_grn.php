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
        echo json_encode(['success' => false, 'message' => 'Only draft GRNs can be cancelled. Current status: ' . $grnData['status']]);
        exit();
    }

    // Start transaction so status update + audit log are atomic
    $conn->begin_transaction();

    // Update GRN status to cancelled (no stock reversal needed since stock was never added for draft)
    $updateGrn = $conn->prepare("UPDATE grn SET status = 'cancelled' WHERE grn_id = ?");
    $updateGrn->bind_param("i", $grn_id);
    if (!$updateGrn->execute()) {
        throw new Exception("Failed to cancel GRN.");
    }
    $updateGrn->close();

    // Log action
    if (isset($_SESSION['user_id'])) {
        $logStmt = $conn->prepare("INSERT INTO user_logs (user_id, action_type, inquiry_id, details) VALUES (?, ?, ?, ?)");
        $action_type = 'grn_cancel';
        $details = "GRN cancelled - Number: {$grnData['grn_number']}";
        $logStmt->bind_param("isis", $_SESSION['user_id'], $action_type, $grn_id, $details);
        $logStmt->execute();
        $logStmt->close();
    }

    $conn->commit();

    echo json_encode(['success' => true, 'message' => 'GRN has been cancelled successfully.']);

} catch (Exception $e) {
    $conn->rollback();
    error_log("GRN cancel error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred while cancelling the GRN.']);
} finally {
    if (isset($conn)) $conn->close();
}
