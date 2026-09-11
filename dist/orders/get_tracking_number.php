<?php
/**
 * Get next available tracking number for selected courier
 * Returns JSON response with tracking number availability
 */

// Start session and check authentication
session_start();

// Authentication check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Set content type to JSON
header('Content-Type: application/json');

try {
    // Get courier_id and order_id from GET parameters
    $courier_id = isset($_GET['courier_id']) ? (int)$_GET['courier_id'] : 0;
    $order_id = isset($_GET['order_id']) ? trim($_GET['order_id']) : '';
    
    if ($courier_id <= 0) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid courier ID provided'
        ]);
        exit();
    }
    
    if (empty($order_id)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Order ID is required'
        ]);
        exit();
    }
    
    // **STEP 1: Get the tenant_id from the order**
    $order_query = "SELECT tenant_id FROM order_header WHERE order_id = ? LIMIT 1";
    $order_stmt = $conn->prepare($order_query);
    $order_stmt->bind_param("s", $order_id);
    $order_stmt->execute();
    $order_result = $order_stmt->get_result();
    
    if ($order_result->num_rows === 0) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Order not found'
        ]);
        exit();
    }
    
    $order_data = $order_result->fetch_assoc();
    $order_tenant_id = $order_data['tenant_id'];
    $order_stmt->close();
    
    // **STEP 2: Verify courier exists, is active, and belongs to the same tenant**
    $courier_check_sql = "SELECT courier_id, courier_name, tenant_id 
                          FROM couriers 
                          WHERE courier_id = ? 
                          AND status = 'active' 
                          AND tenant_id = ?";
    $courier_stmt = $conn->prepare($courier_check_sql);
    $courier_stmt->bind_param("ii", $courier_id, $order_tenant_id);
    $courier_stmt->execute();
    $courier_result = $courier_stmt->get_result();
    
    if ($courier_result->num_rows === 0) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Courier not found, inactive, or does not belong to the order\'s tenant'
        ]);
        exit();
    }
    
    $courier_data = $courier_result->fetch_assoc();
    $courier_stmt->close();
    
    // **STEP 3: Get count of unused tracking numbers for this courier AND tenant**
    $count_sql = "SELECT COUNT(*) as available_count 
                  FROM tracking 
                  WHERE courier_id = ? 
                  AND tenant_id = ? 
                  AND status = 'unused'";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param("ii", $courier_id, $order_tenant_id);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $count_data = $count_result->fetch_assoc();
    $available_count = $count_data['available_count'];
    $count_stmt->close();
    
    if ($available_count <= 0) {
        echo json_encode([
            'status' => 'error',
            'message' => 'No unused tracking numbers available for ' . $courier_data['courier_name'],
            'courier_name' => $courier_data['courier_name'],
            'available_count' => 0
        ]);
        exit();
    }
    
    // **STEP 4: Get the next available tracking number for this courier AND tenant**
    $tracking_sql = "SELECT tracking_id 
                     FROM tracking 
                     WHERE courier_id = ? 
                     AND tenant_id = ? 
                     AND status = 'unused' 
                     ORDER BY created_at ASC 
                     LIMIT 1";
    $tracking_stmt = $conn->prepare($tracking_sql);
    $tracking_stmt->bind_param("ii", $courier_id, $order_tenant_id);
    $tracking_stmt->execute();
    $tracking_result = $tracking_stmt->get_result();
    
    if ($tracking_result->num_rows === 0) {
        echo json_encode([
            'status' => 'error',
            'message' => 'No tracking numbers found despite count query showing availability',
            'available_count' => $available_count
        ]);
        exit();
    }
    
    $tracking_data = $tracking_result->fetch_assoc();
    $tracking_stmt->close();
    
    // Return success response
    echo json_encode([
        'status' => 'success',
        'tracking_number' => $tracking_data['tracking_id'],
        'courier_name' => $courier_data['courier_name'],
        'courier_id' => $courier_id,
        'tenant_id' => $order_tenant_id,
        'available_count' => $available_count
    ]);
    
} catch (Exception $e) {
    // Log error for debugging
    error_log("Error in get_tracking_number.php: " . $e->getMessage());
    
    echo json_encode([
        'status' => 'error',
        'message' => 'An error occurred while fetching tracking number. Please try again.',
        'debug' => $e->getMessage() // Remove this in production
    ]);
}

// Close database connection
if (isset($conn)) {
    $conn->close();
}
?>