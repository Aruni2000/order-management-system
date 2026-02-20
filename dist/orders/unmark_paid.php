<?php
/**
 * Unmark Payment - Set order back to unpaid
 * Handles removing payment status and related data
 * Updated to work with order_header and order_items table structures
 */

session_start();
header('Content-Type: application/json');

// Enable error logging for debugging
error_reporting(E_ALL);
ini_set('log_errors', 1);

try {
    // Authentication check
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        throw new Exception('Authentication required');
    }

    // Include database connection
    $db_path = $_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php';
    if (!file_exists($db_path)) {
        throw new Exception('Database connection file not found');
    }
    include($db_path);

    if (!isset($conn)) {
        throw new Exception('Database connection not established');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    if (!isset($_POST['action']) || $_POST['action'] !== 'unmark_paid') {
        throw new Exception('Invalid action');
    }

    $orderId = isset($_POST['order_id']) ? trim($_POST['order_id']) : '';
    if (empty($orderId)) {
        throw new Exception('Order ID is required');
    }

    // First check if order exists and get its current status
    $checkOrderSql = "SELECT order_id, pay_status, status FROM order_header WHERE order_id = ?";
    $checkOrderStmt = $conn->prepare($checkOrderSql);
    if (!$checkOrderStmt) {
        throw new Exception('Failed to prepare order check statement: ' . $conn->error);
    }

    $checkOrderStmt->bind_param("s", $orderId);
    $checkOrderStmt->execute();
    $orderResult = $checkOrderStmt->get_result();

    if ($orderResult->num_rows === 0) {
        throw new Exception('Order not found. Please check the order ID and try again.');
    }

    $orderData = $orderResult->fetch_assoc();

    // Check if order is not paid
    if ($orderData['pay_status'] !== 'paid') {
        throw new Exception('This order is not marked as paid. Cannot unmark payment.');
    }

    // Check if order is in valid status for unmarking payment
    if (!in_array($orderData['status'], ['pending', 'dispatch', 'done'])) {
        throw new Exception('Order is not in a valid status for payment unmarking. Current status: ' . $orderData['status']);
    }

    // Start database transaction
    $conn->begin_transaction();

    try {
        // Check if order items exist for this order
        $checkItemsSql = "SELECT COUNT(*) as item_count FROM order_items WHERE order_id = ?";
        $checkItemsStmt = $conn->prepare($checkItemsSql);
        if (!$checkItemsStmt) {
            throw new Exception('Failed to prepare items check statement: ' . $conn->error);
        }

        $checkItemsStmt->bind_param("s", $orderId);
        $checkItemsStmt->execute();
        $itemsResult = $checkItemsStmt->get_result();
        $itemsRow = $itemsResult->fetch_assoc();

        if ($itemsRow['item_count'] == 0) {
            throw new Exception('No order items found for this order');
        }

        // Get current user ID from session
        $currentUserId = null;
        if (isset($_SESSION['user_id'])) {
            $currentUserId = $_SESSION['user_id'];
        } elseif (isset($_SESSION['id'])) {
            $currentUserId = $_SESSION['id'];
        } else {
            $currentUserId = 1; // Default fallback
        }

        $updatedAt = date('Y-m-d H:i:s');

        // Update order_header with payment removal
        $updateHeaderSql = "UPDATE order_header SET
                           pay_status = 'unpaid',
                           pay_by = NULL,
                           pay_date = NULL,
                           slip = NULL,
                           updated_at = ?
                           WHERE order_id = ? AND pay_status = 'paid'";

        $updateHeaderStmt = $conn->prepare($updateHeaderSql);
        if (!$updateHeaderStmt) {
            throw new Exception('Failed to prepare header update statement: ' . $updateHeaderStmt->error);
        }

        $updateHeaderStmt->bind_param("ss", $updatedAt, $orderId);

        if (!$updateHeaderStmt->execute()) {
            throw new Exception('Failed to update order header: ' . $updateHeaderStmt->error);
        }

        // Check if any rows were affected in order_header
        if ($updateHeaderStmt->affected_rows === 0) {
            throw new Exception('Order has already been unmarked as paid or does not exist.');
        }

        // Update order_items with payment status
        $updateItemsSql = "UPDATE order_items SET
                          pay_status = 'unpaid',
                          updated_at = CURRENT_TIMESTAMP
                          WHERE order_id = ? AND pay_status = 'paid'";

        $updateItemsStmt = $conn->prepare($updateItemsSql);
        if (!$updateItemsStmt) {
            throw new Exception('Failed to prepare items update statement: ' . $conn->error);
        }

        $updateItemsStmt->bind_param("s", $orderId);

        if (!$updateItemsStmt->execute()) {
            throw new Exception('Failed to update order items: ' . $updateItemsStmt->error);
        }

        // Get the number of items updated
        $itemsUpdated = $updateItemsStmt->affected_rows;

        // Delete payment record from payments table
        $deletePaymentSql = "DELETE FROM payments WHERE order_id = ?";
        $deletePaymentStmt = $conn->prepare($deletePaymentSql);
        if (!$deletePaymentStmt) {
            throw new Exception('Failed to prepare payment delete statement: ' . $conn->error);
        }

        $deletePaymentStmt->bind_param("s", $orderId);

        if (!$deletePaymentStmt->execute()) {
            throw new Exception('Failed to delete payment record: ' . $deletePaymentStmt->error);
        }

        // Insert simplified user log entry
        $logMessage = $orderData['status'] . " paid order(" . $orderId . ") unmarked paid";

        $logSql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at)
                   VALUES (?, 'payment_unmarked', ?, ?, CURRENT_TIMESTAMP)";

        $logStmt = $conn->prepare($logSql);
        if (!$logStmt) {
            throw new Exception('Failed to prepare log statement: ' . $conn->error);
        }

        $logStmt->bind_param("iss", $currentUserId, $orderId, $logMessage);

        if (!$logStmt->execute()) {
            throw new Exception('Failed to insert user log: ' . $logStmt->error);
        }

        $logId = $conn->insert_id;

        // Commit transaction
        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Order and items unmarked as paid successfully',
            'order_id' => $orderId,
            'pay_date' => null,
            'items_updated' => $itemsUpdated,
            'log_id' => $logId,
            'log_message' => $logMessage
        ]);

    } catch (Exception $e) {
        // Rollback transaction
        $conn->rollback();

        throw $e;
    }

} catch (Exception $e) {
    error_log("Unmark Paid Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

if (isset($conn)) {
    $conn->close();
}
?>