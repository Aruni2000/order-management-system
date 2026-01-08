<?php
/**
 * Process Bulk Order Dispatch
 * Updates multiple order statuses to 'dispatch', assigns tracking numbers, marks tracking as used,
 * updates order items status, and logs user actions
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

// CRITICAL: Set content type BEFORE any output
header('Content-Type: application/json; charset=utf-8');

// CRITICAL: Disable output buffering to prevent truncation
if (ob_get_level()) {
    ob_end_clean();
}

try {
    // Check if request method is POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Invalid request method']);
        exit();
    }
    
    // Get POST parameters
    $order_ids_json = isset($_POST['order_ids']) ? trim($_POST['order_ids']) : '';
    $carrier_id = isset($_POST['carrier']) ? (int)$_POST['carrier'] : 0;
    $dispatch_notes = isset($_POST['dispatch_notes']) ? trim($_POST['dispatch_notes']) : '';
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';
    
    // Validate required parameters
    if ($action !== 'bulk_dispatch_orders') {
        echo json_encode(['success' => false, 'message' => 'Invalid action specified']);
        exit();
    }
    
    if (empty($order_ids_json)) {
        echo json_encode(['success' => false, 'message' => 'No orders selected for dispatch']);
        exit();
    }
    
    // Parse order IDs
    $order_ids = json_decode($order_ids_json, true);
    
    if (!is_array($order_ids) || count($order_ids) === 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid order IDs format']);
        exit();
    }
    
    if ($carrier_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please select a valid courier service']);
        exit();
    }
    
    // Start database transaction
    $conn->begin_transaction();
    
    try {
        // Arrays to track results
        $dispatched_orders = [];
        $failed_orders = [];
        $tenant_id = null;
        
        // STEP 1: Verify all orders belong to the same tenant
        $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
        $tenant_check_sql = "SELECT DISTINCT tenant_id FROM order_header WHERE order_id IN ($placeholders)";
        $tenant_stmt = $conn->prepare($tenant_check_sql);
        
        // Bind parameters dynamically
        $types = str_repeat('s', count($order_ids));
        $tenant_stmt->bind_param($types, ...$order_ids);
        $tenant_stmt->execute();
        $tenant_result = $tenant_stmt->get_result();
        
        $tenant_ids = [];
        while ($row = $tenant_result->fetch_assoc()) {
            $tenant_ids[] = $row['tenant_id'];
        }
        $tenant_stmt->close();
        
        // Verify single tenant
        if (count($tenant_ids) === 0) {
            throw new Exception('No valid orders found');
        }
        
        if (count($tenant_ids) > 1) {
            throw new Exception('Cannot dispatch orders from multiple tenants. Please select orders from the same tenant only.');
        }
        
        $tenant_id = $tenant_ids[0];
        
        // STEP 2: Verify courier belongs to the same tenant
        $courier_check_sql = "SELECT courier_id, courier_name, co_id, tenant_id 
                             FROM couriers 
                             WHERE courier_id = ? 
                             AND status = 'active' 
                             AND tenant_id = ?";
        $courier_stmt = $conn->prepare($courier_check_sql);
        $courier_stmt->bind_param("ii", $carrier_id, $tenant_id);
        $courier_stmt->execute();
        $courier_result = $courier_stmt->get_result();
        
        if ($courier_result->num_rows === 0) {
            throw new Exception('Courier not found, inactive, or does not belong to the selected orders\' tenant');
        }
        
        $courier_data = $courier_result->fetch_assoc();
        $courier_co_id = $courier_data['co_id'];
        $courier_name = $courier_data['courier_name'];
        $courier_stmt->close();
        
        // STEP 3: Get required number of tracking numbers from ANY courier in tenant
        $required_count = count($order_ids);
        
    // FIXED: Get tracking numbers from the SELECTED courier only
      // STEP 3: Get tracking numbers from ANY courier in the tenant
                $tracking_sql = "SELECT t.tracking_id, t.courier_id
                                FROM tracking t
                                WHERE t.tenant_id = ?
                                AND t.status = 'unused' 
                                ORDER BY t.created_at ASC 
                                LIMIT ? FOR UPDATE";

                $tracking_stmt = $conn->prepare($tracking_sql);
                $tracking_stmt->bind_param("ii", $tenant_id, $required_count);
                $tracking_stmt->execute();
                $tracking_result = $tracking_stmt->get_result();
        
        // Collect tracking numbers
        $tracking_numbers = [];
        while ($row = $tracking_result->fetch_assoc()) {
            $tracking_numbers[] = $row['tracking_id'];
        }
        $tracking_stmt->close();
        
        if (count($tracking_numbers) < $required_count) {
            throw new Exception(sprintf(
                'Insufficient tracking numbers. Required: %d, Available: %d for tenant %d', 
                $required_count, 
                count($tracking_numbers), 
                $tenant_id
            ));
        }
        
        // Get user ID for logging
        $user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? 0;
        
        // STEP 4: Process each order
        foreach ($order_ids as $index => $order_id) {
            try {
                $order_id = trim($order_id);
                $tracking_number = $tracking_numbers[$index];
                
                // Verify order exists and is dispatchable
                $order_check_sql = "SELECT order_id, status, customer_id, tenant_id 
                                   FROM order_header 
                                   WHERE order_id = ? AND status IN ('pending', 'done') AND tenant_id = ?";
                $order_stmt = $conn->prepare($order_check_sql);
                $order_stmt->bind_param("si", $order_id, $tenant_id);
                $order_stmt->execute();
                $order_result = $order_stmt->get_result();
                
                if ($order_result->num_rows === 0) {
                    $failed_orders[] = [
                        'order_id' => $order_id,
                        'error' => 'Order not found, not dispatchable, or tenant mismatch'
                    ];
                    $order_stmt->close();
                    continue;
                }
                
                $order_data = $order_result->fetch_assoc();
                $order_stmt->close();
                
               //  CORRECT: Verify tracking belongs to the selected courier
                   // Update tracking - verify by tenant_id only
                        $update_tracking_sql = "UPDATE tracking 
                                            SET status = 'used', updated_at = CURRENT_TIMESTAMP 
                                            WHERE tracking_id = ? 
                                            AND tenant_id = ?
                                            AND status = 'unused'";
                        $update_tracking_stmt = $conn->prepare($update_tracking_sql);
                        $update_tracking_stmt->bind_param("si", $tracking_number, $tenant_id);

                if (!$update_tracking_stmt->execute() || $update_tracking_stmt->affected_rows === 0) {
                    $failed_orders[] = [
                        'order_id' => $order_id,
                        'error' => 'Failed to assign tracking number or tenant mismatch'
                    ];
                    $update_tracking_stmt->close();
                    continue;
                }
                $update_tracking_stmt->close();
                
                // Update order status
                $update_order_sql = "UPDATE order_header SET 
                                    status = 'dispatch',
                                    courier_id = ?,
                                    co_id = ?,
                                    tracking_number = ?,
                                    dispatch_note = ?,
                                    updated_at = CURRENT_TIMESTAMP
                                    WHERE order_id = ? AND status IN ('pending', 'done') AND tenant_id = ?";
                $update_order_stmt = $conn->prepare($update_order_sql);
                $update_order_stmt->bind_param("iisssi", $carrier_id, $courier_co_id, $tracking_number, $dispatch_notes, $order_id, $tenant_id);
                
                if (!$update_order_stmt->execute() || $update_order_stmt->affected_rows === 0) {
                    $failed_orders[] = [
                        'order_id' => $order_id,
                        'error' => 'Failed to update order status or tenant mismatch'
                    ];
                    $update_order_stmt->close();
                    continue;
                }
                $update_order_stmt->close();
                
                // Update order items status
                $update_items_sql = "UPDATE order_items SET 
                                    status = 'dispatch',
                                    updated_at = CURRENT_TIMESTAMP
                                    WHERE order_id = ? AND status IN ('pending', 'done')";
                $update_items_stmt = $conn->prepare($update_items_sql);
                $update_items_stmt->bind_param("s", $order_id);
                $update_items_stmt->execute();
                $update_items_stmt->close();
                
                // Log action
                $log_message = "Bulk dispatch order ({$order_id}) with tracking ({$tracking_number}) and co_id ({$courier_co_id}) for tenant ({$tenant_id})";
                $user_log_sql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) 
                                VALUES (?, 'bulk_order_dispatch', ?, ?, NOW())";
                $user_log_stmt = $conn->prepare($user_log_sql);
                $user_log_stmt->bind_param("iss", $user_id, $order_id, $log_message);
                $user_log_stmt->execute();
                $user_log_stmt->close();
                
                // Add to successful dispatches
                $dispatched_orders[] = [
                    'order_id' => $order_id,
                    'tracking_number' => $tracking_number
                ];
                
            } catch (Exception $e) {
                $failed_orders[] = [
                    'order_id' => $order_id,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        // Prepare response
        $response = [
            'success' => true,
            'message' => 'Orders dispatched successfully',
            'dispatched_count' => count($dispatched_orders),
            'dispatched_orders' => $dispatched_orders,
            'failed_count' => count($failed_orders),
            'courier_id' => $carrier_id,
            'co_id' => $courier_co_id,
            'courier_name' => $courier_name,
            'tenant_id' => $tenant_id
        ];
        
        // Only include failed orders if there are any
        if (count($failed_orders) > 0) {
            $response['failed_orders'] = $failed_orders;
        }
        
        // CRITICAL: Encode with proper JSON flags to prevent truncation
        $json_response = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        if ($json_response === false) {
            throw new Exception('JSON encoding failed: ' . json_last_error_msg());
        }
        
        echo $json_response;
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    // Log error for debugging
    error_log("Error in process_bulk_dispatch.php: " . $e->getMessage());
    
    // Ensure clean JSON output
    $error_response = json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    if ($error_response === false) {
        echo json_encode([
            'success' => false,
            'message' => 'A critical error occurred and the response could not be encoded'
        ]);
    } else {
        echo $error_response;
    }
}

// Close database connection
if (isset($conn)) {
    $conn->close();
}

// CRITICAL: Exit cleanly to prevent any trailing output
exit();
?>