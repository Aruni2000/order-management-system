<?php
/**
 * Process order dispatch
 * Updates order status to 'dispatch', assigns tracking number, marks tracking as used,
 * updates order items status, and logs user action
 */

// Start session and check authentication
session_start();

// Authentication check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/connection/db_connection.php');

// Set content type to JSON
header('Content-Type: application/json');

try {
    // Check if request method is POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Invalid request method']);
        exit();
    }
    
    // Get POST parameters
    $order_id = isset($_POST['order_id']) ? trim($_POST['order_id']) : '';
    $carrier_id = isset($_POST['carrier']) ? (int)$_POST['carrier'] : 0;
    $dispatch_notes = isset($_POST['dispatch_notes']) ? trim($_POST['dispatch_notes']) : '';
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';
    
    // Validate required parameters
    if ($action !== 'dispatch_order') {
        echo json_encode(['success' => false, 'message' => 'Invalid action specified']);
        exit();
    }
    
    if (empty($order_id)) {
        echo json_encode(['success' => false, 'message' => 'Invalid order ID provided']);
        exit();
    }
    
    if ($carrier_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please select a valid courier service']);
        exit();
    }
    
    // Start database transaction
    $conn->begin_transaction();
    
    try {
        // Verify order exists and is pending or done (both can be dispatched)
        // IMPORTANT: Also get tenant_id from the order
        $order_check_sql = "SELECT order_id, status, customer_id, total_amount, tenant_id 
                           FROM order_header 
                           WHERE order_id = ? AND status IN ('pending', 'done')";
        $order_stmt = $conn->prepare($order_check_sql);
        $order_stmt->bind_param("s", $order_id);
        $order_stmt->execute();
        $order_result = $order_stmt->get_result();
        
        if ($order_result->num_rows === 0) {
            throw new Exception('Order not found or not available for dispatch (must be pending or done status)');
        }
        
        $order_data = $order_result->fetch_assoc();
        $customer_id = $order_data['customer_id'];
        $current_status = $order_data['status'];
        $order_tenant_id = $order_data['tenant_id']; // Get order's tenant_id
        $order_stmt->close();
        
        // Verify courier exists, is active, AND belongs to the same tenant as the order
        // ALSO GET co_id to insert into order_header
        $courier_check_sql = "SELECT courier_id, courier_name, co_id, tenant_id 
                             FROM couriers 
                             WHERE courier_id = ? 
                             AND status = 'active' 
                             AND tenant_id = ?";
        $courier_stmt = $conn->prepare($courier_check_sql);
        $courier_stmt->bind_param("ii", $carrier_id, $order_tenant_id);
        $courier_stmt->execute();
        $courier_result = $courier_stmt->get_result();
        
        if ($courier_result->num_rows === 0) {
            throw new Exception('Courier not found, inactive, or does not belong to the order\'s tenant');
        }
        
        $courier_data = $courier_result->fetch_assoc();
        $courier_tenant_id = $courier_data['tenant_id'];
        $courier_co_id = $courier_data['co_id'];
        $courier_stmt->close();
        
        // ✅ FIXED: Get tracking number from tenant-wide pool (ANY courier in tenant)
        $tracking_sql = "SELECT tracking_id 
                        FROM tracking
                        WHERE tenant_id = ?
                        AND status = 'unused' 
                        ORDER BY created_at ASC 
                        LIMIT 1 FOR UPDATE";
        $tracking_stmt = $conn->prepare($tracking_sql);
        $tracking_stmt->bind_param("i", $order_tenant_id);
        $tracking_stmt->execute();
        $tracking_result = $tracking_stmt->get_result();
        
        if ($tracking_result->num_rows === 0) {
            throw new Exception('No unused tracking numbers available for tenant ' . $order_tenant_id);
        }
        
        $tracking_data = $tracking_result->fetch_assoc();
        $tracking_number = $tracking_data['tracking_id'];
        $tracking_stmt->close();
        
        // ✅ FIXED: Update tracking number - verify by tenant_id only
        $update_tracking_sql = "UPDATE tracking 
                               SET status = 'used', updated_at = CURRENT_TIMESTAMP 
                               WHERE tracking_id = ? 
                               AND tenant_id = ?
                               AND status = 'unused'";
        $update_tracking_stmt = $conn->prepare($update_tracking_sql);
        $update_tracking_stmt->bind_param("si", $tracking_number, $order_tenant_id);
        
        if (!$update_tracking_stmt->execute()) {
            throw new Exception('Failed to update tracking number status');
        }
        
        if ($update_tracking_stmt->affected_rows === 0) {
            throw new Exception('Tracking number was already used by another process or tenant mismatch');
        }
        $update_tracking_stmt->close();
        
        // Update order status to 'dispatch' and set tracking information + co_id
        $update_order_sql = "UPDATE order_header SET 
                            status = 'dispatch',
                            courier_id = ?,
                            co_id = ?,
                            tracking_number = ?,
                            dispatch_note = ?,
                            updated_at = CURRENT_TIMESTAMP
                            WHERE order_id = ? AND status IN ('pending', 'done')";
        $update_order_stmt = $conn->prepare($update_order_sql);
        $update_order_stmt->bind_param("iisss", $carrier_id, $courier_co_id, $tracking_number, $dispatch_notes, $order_id);
        
        if (!$update_order_stmt->execute()) {
            throw new Exception('Failed to update order status');
        }
        
        if ($update_order_stmt->affected_rows === 0) {
            throw new Exception('Order was already processed by another user or status changed');
        }
        $update_order_stmt->close();
        
        // Update order_items status to 'dispatch'
        $update_items_sql = "UPDATE order_items SET 
                            status = 'dispatch',
                            updated_at = CURRENT_TIMESTAMP
                            WHERE order_id = ? AND status IN ('pending', 'done')";
        $update_items_stmt = $conn->prepare($update_items_sql);
        $update_items_stmt->bind_param("s", $order_id);
        
        if (!$update_items_stmt->execute()) {
            throw new Exception('Failed to update order items status');
        }
        
        $items_updated = $update_items_stmt->affected_rows;
        $update_items_stmt->close();
        
        // Get user ID for logging
        $user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? 0;
        
        $log_message = "Add a dispatch unpaid order({$order_id}) with system tracking({$tracking_number}) and co_id({$courier_co_id}) for tenant({$order_tenant_id})";
        
        $user_log_sql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) 
                        VALUES (?, 'order_dispatch', ?, ?, NOW())";
        $user_log_stmt = $conn->prepare($user_log_sql);
        $user_log_stmt->bind_param("iss", $user_id, $order_id, $log_message);
        
        if (!$user_log_stmt->execute()) {
            throw new Exception('Failed to log user action');
        }
        $user_log_stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Order dispatched successfully',
            'order_id' => $order_id,
            'previous_status' => $current_status,
            'tracking_number' => $tracking_number,
            'courier_name' => $courier_data['courier_name'],
            'co_id' => $courier_co_id,
            'dispatch_notes' => $dispatch_notes,
            'items_updated' => $items_updated,
            'tenant_id' => $order_tenant_id
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Error in process_dispatch.php: " . $e->getMessage());
    
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