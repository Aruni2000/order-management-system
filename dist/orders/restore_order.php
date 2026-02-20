<?php
/**
 * Restore Order Processing Script
 * Reverts order status from 'cancel' to 'pending'
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

try {
    // Check if POST request
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    
    // Get parameters
    $order_id = $_POST['order_id'] ?? '';
    
    // Basic validation
    if (empty($order_id)) {
        throw new Exception('Order ID is required');
    }
    
    // Start database transaction
    $conn->begin_transaction();
    
    try {
        // Check if order exists and is in 'cancel' status
        $check_sql = "SELECT order_id, status, tracking_number FROM order_header WHERE order_id = ? AND interface IN ('individual', 'leads')";
        $stmt = $conn->prepare($check_sql);
        $stmt->bind_param("s", $order_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception('Order not found');
        }
        
        $order = $result->fetch_assoc();
        
        if ($order['status'] !== 'cancel') {
            throw new Exception('Only cancelled orders can be restored');
        }
        
        $stmt->close();
        
        // if has tracking number, move back to dispatch
        $restore_status = 'pending';
        if (!empty($order['tracking_number'])) {
            $restore_status = 'dispatch';
        }
        
        // Update order header to restore status
        $update_order_sql = "UPDATE order_header SET 
                            status = ?, 
                            cancellation_reason = NULL,
                            updated_at = CURRENT_TIMESTAMP
                            WHERE order_id = ?";
        $order_stmt = $conn->prepare($update_order_sql);
        $order_stmt->bind_param("ss", $restore_status, $order_id);
        
        if (!$order_stmt->execute()) {
            throw new Exception('Failed to update order: ' . $order_stmt->error);
        }
        
        $order_stmt->close();
        
        // Update order_items status back to restore status
        $update_items_sql = "UPDATE order_items SET 
                            status = ?,
                            updated_at = CURRENT_TIMESTAMP
                            WHERE order_id = ? AND status = 'canceled'";
        $items_stmt = $conn->prepare($update_items_sql);
        $items_stmt->bind_param("ss", $restore_status, $order_id);
        
        if (!$items_stmt->execute()) {
            throw new Exception('Failed to update order items: ' . $items_stmt->error);
        }
        
        $items_reverted = $items_stmt->affected_rows;
        $items_stmt->close();
        
        // Log user action
        $user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? 0;
        $log_description = "Order(" . $order_id . ") restored (reverted to " . $restore_status . ")";
        
        $user_log_sql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) 
                        VALUES (?, 'order_restore', ?, ?, NOW())";
        $log_stmt = $conn->prepare($user_log_sql);
        $log_stmt->bind_param("iis", $user_id, $order_id, $log_description);
        
        if (!$log_stmt->execute()) {
            throw new Exception('Failed to log user action: ' . $log_stmt->error);
        }
        
        $log_stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Order restored successfully',
            'order_id' => $order_id,
            'items_reverted' => $items_reverted
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

if (isset($conn)) {
    $conn->close();
}
?>