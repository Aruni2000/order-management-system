<?php
/**
 * Cancel Order Processing Script
 * Updates order status, order items, and logs user action
 */

session_start();
header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/stock_ledger.php');

try {
    // Check if POST request
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    
    // Get parameters
    $order_id = $_POST['order_id'] ?? '';
    $cancellation_reason = $_POST['cancellation_reason'] ?? '';
    
    // Basic validation
    if (empty($order_id)) {
        throw new Exception('Order ID is required');
    }
    
    // Start database transaction
    $conn->begin_transaction();
    
    try {
       // Check if order exists and can be cancelled
       $is_main_admin = isset($_SESSION['is_main_admin']) ? (int)$_SESSION['is_main_admin'] : 0;
       $session_tenant_id = isset($_SESSION['tenant_id']) ? intval($_SESSION['tenant_id']) : 0;

       if ($is_main_admin) {
           $check_sql = "SELECT order_id, status, customer_id, total_amount, tenant_id 
                         FROM order_header 
                         WHERE order_id = ? AND interface IN ('individual', 'leads')";
           $stmt = $conn->prepare($check_sql);
           $stmt->bind_param("s", $order_id);
       } else {
           $check_sql = "SELECT order_id, status, customer_id, total_amount, tenant_id 
                         FROM order_header 
                         WHERE order_id = ? AND tenant_id = ? AND interface IN ('individual', 'leads')";
           $stmt = $conn->prepare($check_sql);
           $stmt->bind_param("si", $order_id, $session_tenant_id);
       }
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception('Order not found');
        }
        
        $order = $result->fetch_assoc();
        $customer_id = $order['customer_id'];
        $total_amount = $order['total_amount'];
        $previous_status = $order['status'];
        
        if ($order['status'] === 'cancel') {
            throw new Exception('Order is already cancelled');
        }
        
        if ($order['status'] === 'done') {
            throw new Exception('Cannot cancel completed orders');
        }
        
        if ($order['status'] === 'return_handover') {
            throw new Exception('Cannot cancel orders that have already been returned and handed over');
        }
        
        $stmt->close();
        
        // Update order header to cancelled (with tenant isolation)
        if ($is_main_admin) {
            $update_order_sql = "UPDATE order_header SET 
                                status = 'cancel', 
                                cancellation_reason = ?,
                                updated_at = CURRENT_TIMESTAMP
                                WHERE order_id = ?";
            $order_stmt = $conn->prepare($update_order_sql);
            $order_stmt->bind_param("ss", $cancellation_reason, $order_id);
        } else {
            $update_order_sql = "UPDATE order_header SET 
                                status = 'cancel', 
                                cancellation_reason = ?,
                                updated_at = CURRENT_TIMESTAMP
                                WHERE order_id = ? AND tenant_id = ?";
            $order_stmt = $conn->prepare($update_order_sql);
            $order_stmt->bind_param("ssi", $cancellation_reason, $order_id, $session_tenant_id);
        }
        
        if (!$order_stmt->execute()) {
            throw new Exception('Failed to update order: ' . $order_stmt->error);
        }
        
        if ($order_stmt->affected_rows === 0) {
            throw new Exception('No rows updated in order header');
        }
        
        $order_stmt->close();
        
        // Restore inventory
        if (isset($_SESSION['allow_inventory']) && $_SESSION['allow_inventory'] == 1) {
            $restore_tenant_id = isset($order['tenant_id']) ? (int)$order['tenant_id'] : $session_tenant_id;

            $getItemsSql = "SELECT product_id, quantity, item_id FROM order_items WHERE order_id = ? AND status != 'canceled'";
            $items_stmt = $conn->prepare($getItemsSql);
            $items_stmt->bind_param("s", $order_id);
            $items_stmt->execute();
            $items_result = $items_stmt->get_result();

            // Prepare batch-restore statement
            $update_batch_sql = "UPDATE batches SET remaining_qty = remaining_qty + ? WHERE batch_id = ?";
            $batch_stmt = null;
            // Only prepare if order_item_batches references could exist
            $is_batch_aware = false;
            $checkHasBatchesSql = "SELECT 1 FROM order_item_batches WHERE order_id = ? LIMIT 1";
            $checkHasBatchesStmt = $conn->prepare($checkHasBatchesSql);
            $checkHasBatchesStmt->bind_param("s", $order_id);
            $checkHasBatchesStmt->execute();
            $is_batch_aware = $checkHasBatchesStmt->get_result()->num_rows > 0;
            $checkHasBatchesStmt->close();

            if ($is_batch_aware) {
                $batch_stmt = $conn->prepare($update_batch_sql);
            }

            $update_stock_sql = "UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ? AND tenant_id = ?";
            $stock_stmt = $conn->prepare($update_stock_sql);

            while ($item = $items_result->fetch_assoc()) {
                $product_id = $item['product_id'];
                $qty = $item['quantity'];
                $item_id = $item['item_id'];

                $restored_qty = 0;
                if ($is_batch_aware) {
                    // Restore each specific batch that fulfilled this order item
                    $oibSql = "SELECT batch_id, quantity FROM order_item_batches WHERE order_item_id = ?";
                    $oibStmt = $conn->prepare($oibSql);
                    $oibStmt->bind_param("i", $item_id);
                    $oibStmt->execute();
                    $oibResult = $oibStmt->get_result();
                    while ($oib = $oibResult->fetch_assoc()) {
                        $batch_stmt->bind_param("ii", $oib['quantity'], $oib['batch_id']);
                        if (!$batch_stmt->execute()) {
                            throw new Exception('Failed to restore batch stock.');
                        }
                        $restored_qty += $oib['quantity'];
                        log_stock_movement($conn, $restore_tenant_id, (int)$product_id, (int)$oib['batch_id'], 'order_cancel_return', (int)$oib['quantity'], 'order', (int)$order_id, isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null, 'Order cancelled - batch restore');
                    }
                    $oibStmt->close();
                }

                $stock_stmt->bind_param("iii", $qty, $product_id, $restore_tenant_id);
                if (!$stock_stmt->execute()) {
                    throw new Exception('Failed to restore stock for product ID: ' . $product_id);
                }
                $nonBatchQty = (int)$qty - (int)$restored_qty;
                if ($nonBatchQty > 0) {
                    log_stock_movement($conn, $restore_tenant_id, (int)$product_id, null, 'order_cancel_return', $nonBatchQty, 'order', (int)$order_id, isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null, 'Order cancelled - non-batch restore');
                }
            }
            $items_stmt->close();
            if ($batch_stmt) $batch_stmt->close();
            $stock_stmt->close();
        }
        
        // Update order_items status to 'canceled'
        $update_items_sql = "UPDATE order_items SET 
                            status = 'canceled',
                            updated_at = CURRENT_TIMESTAMP
                            WHERE order_id = ? AND status != 'canceled'";
        $items_stmt = $conn->prepare($update_items_sql);
        $items_stmt->bind_param("s", $order_id);
        
        if (!$items_stmt->execute()) {
            throw new Exception('Failed to update order items: ' . $items_stmt->error);
        }
        
        // Get the count of updated items for logging
        $items_cancelled = $items_stmt->affected_rows;
        $items_stmt->close();
        
        // Get user ID for logging
        $user_id = $_SESSION['user_id'] ?? 0;
        
        // Create simple log description
        $log_description = $previous_status . " order(" . $order_id . ") cancelled | reason: " . substr($cancellation_reason, 0, 30);
        if (strlen($cancellation_reason) > 30) {
            $log_description .= "...";
        }
        
        // Log user action in user_logs table
        $user_log_sql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) 
                        VALUES (?, 'order_cancel', ?, ?, NOW())";
        $log_stmt = $conn->prepare($user_log_sql);
        $log_stmt->bind_param("iis", $user_id, $order_id, $log_description);
        
        if (!$log_stmt->execute()) {
            throw new Exception('Failed to log user action: ' . $log_stmt->error);
        }
        
        $log_stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        // Return success response
        echo json_encode([
            'success' => true,
            'message' => 'Order cancelled successfully',
            'order_id' => $order_id,
            'items_cancelled' => $items_cancelled,
            'cancellation_reason' => $cancellation_reason
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        throw $e; // Re-throw to be caught by outer try-catch
    }
    
} catch (Exception $e) {
    // Log error for debugging
    error_log("Error in cancel_order.php: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// Close database connection
if (isset($conn)) {
    $conn->close();
}
?>