<?php
/**
 * UPDATE CUSTOMER CONDITION HANDLER
 * Handles AJAX requests to update the 'condition' field in order_header
 * File: update_condition.php
 */

// Start session and check authentication
session_start();

// Set content type for JSON response
header('Content-Type: application/json');

// Authentication check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode([
        'success' => false,
        'message' => 'Authentication required'
    ]);
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
    exit();
}

try {
    // Get and validate input parameters
    $order_id = isset($_POST['order_id']) ? trim($_POST['order_id']) : '';
    $condition = isset($_POST['condition']) ? intval($_POST['condition']) : null;
    $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';
    
    // Input validation
    if (empty($order_id)) {
        throw new Exception('Order ID is required');
    }
    
    if ($condition === null || !in_array($condition, [0, 1, 2, 3, 4])) {
        throw new Exception('Invalid condition status');
    }
    
    // Start database transaction
    $conn->begin_transaction();
    
    try {
        // Check if order exists
        $checkSql = "SELECT order_id, `condition` FROM order_header WHERE order_id = ?";
        $checkStmt = $conn->prepare($checkSql);
        
        if (!$checkStmt) {
            throw new Exception('Database prepare error: ' . $conn->error);
        }
        
        $checkStmt->bind_param("s", $order_id);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception('Order not found');
        }
        
        $orderData = $result->fetch_assoc();
        $oldCondition = $orderData['condition'];
        $checkStmt->close();
        
        // Update the condition
        $updateSql = "UPDATE order_header SET `condition` = ?, updated_at = updated_at WHERE order_id = ?";
        $updateStmt = $conn->prepare($updateSql);
        
        if (!$updateStmt) {
            throw new Exception('Database prepare error: ' . $conn->error);
        }
        
        $updateStmt->bind_param("is", $condition, $order_id);
        
        if (!$updateStmt->execute()) {
            throw new Exception('Failed to update condition: ' . $updateStmt->error);
        }
        
        $updateStmt->close();
        
        // Map condition codes to names for logging
        $conditionMap = [0 => 'Excellent', 1 => 'Good', 2 => 'Average', 3 => 'Bad', 4 => 'New'];
        $conditionName = $conditionMap[$condition];
        $oldConditionName = $conditionMap[$oldCondition] ?? 'Unknown';
        
        // Get current user ID
        $currentUserId = $_SESSION['user_id'] ?? 0;
        if ($currentUserId == 0) {
            throw new Exception('Session expired. Please log in again.');
        }
        
        // Create log message
        $log_message = "Manually updated customer condition for order({$order_id}) from {$oldConditionName} to {$conditionName}";
        if (!empty($reason)) {
            $log_message .= ". Reason: {$reason}";
        }
        
        // Insert user log entry
        $logSql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) 
                   VALUES (?, 'condition_update', ?, ?, CURRENT_TIMESTAMP)";
        $logStmt = $conn->prepare($logSql);
        
        if (!$logStmt) {
            throw new Exception('Failed to prepare log statement: ' . $conn->error);
        }
        
        $logStmt->bind_param("iss", $currentUserId, $order_id, $log_message);
        
        if (!$logStmt->execute()) {
            throw new Exception('Failed to insert user log: ' . $logStmt->error);
        }
        
        $logStmt->close();
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Condition updated successfully',
            'new_condition' => $condition,
            'condition_name' => $conditionName
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
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
