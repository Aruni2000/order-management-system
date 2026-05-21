<?php
/**
 * UPDATE LEAD ASSIGNED USER HANDLER (OMS)
 * Handles AJAX requests to update the 'user_id' field in order_header
 * File: update_lead_user.php
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

// Admin accessibility check - Only admin (role_id 1) can reassign leads
// In OMS, we also check for is_main_admin if needed, but following order_management pattern
if (!isset($_SESSION['role_id']) || intval($_SESSION['role_id']) !== 1) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized: Only admins can reassign leads'
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
    $new_user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : null;
    
    // Input validation
    if (empty($order_id)) {
        throw new Exception('Order ID is required');
    }
    
    if ($new_user_id === null || $new_user_id <= 0) {
        throw new Exception('Invalid user selection');
    }
    
    // Start database transaction
    $conn->begin_transaction();
    
    try {
        // Check if lead exists, get current user, and verify status
        $checkSql = "SELECT i.order_id, i.user_id, i.status, u.name as old_user_name 
                     FROM order_header i 
                     LEFT JOIN users u ON i.user_id = u.id 
                     WHERE i.order_id = ? AND i.interface = 'leads'";
        $checkStmt = $conn->prepare($checkSql);
        
        if (!$checkStmt) {
            throw new Exception('Database prepare error: ' . $conn->error);
        }
        
        $checkStmt->bind_param("s", $order_id);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception('Lead not found');
        }
        
        $leadData = $result->fetch_assoc();
        
        // RESTRICTION: Only allow reassignment if status is 'pending'
        if (strtolower($leadData['status']) !== 'pending') {
            throw new Exception('Lead reassignment is only allowed for leads in "pending" status. Current status: ' . $leadData['status']);
        }
        
        $oldUserId = $leadData['user_id'];
        $oldUserName = $leadData['old_user_name'] ?? 'Unassigned';
        $checkStmt->close();
        
        // Check if the new user exists and is active
        $userCheckSql = "SELECT name FROM users WHERE id = ? AND status = 'active'";
        $userCheckStmt = $conn->prepare($userCheckSql);
        $userCheckStmt->bind_param("i", $new_user_id);
        $userCheckStmt->execute();
        $userResult = $userCheckStmt->get_result();
        
        if ($userResult->num_rows === 0) {
            throw new Exception('Selected user is invalid or inactive');
        }
        
        $newUserName = $userResult->fetch_assoc()['name'];
        $userCheckStmt->close();
        
        // Update the user_id
        $updateSql = "UPDATE order_header SET user_id = ?, updated_at = CURRENT_TIMESTAMP WHERE order_id = ? AND interface = 'leads'";
        $updateStmt = $conn->prepare($updateSql);
        
        if (!$updateStmt) {
            throw new Exception('Database prepare error: ' . $conn->error);
        }
        
        $updateStmt->bind_param("is", $new_user_id, $order_id);
        
        if (!$updateStmt->execute()) {
            throw new Exception('Failed to update lead assignment: ' . $updateStmt->error);
        }
        
        $updateStmt->close();
        
        // Get current logged-in user ID
        $loggedInUserId = $_SESSION['user_id'] ?? 1;
        
        $log_details = "Lead ID: ({$order_id}) has been reassigned from {$oldUserName} (ID: {$oldUserId}) to {$newUserName} (ID: {$new_user_id})";

        // Insert user log entry
        $logSql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) 
                   VALUES (?, 'lead_reassignment', ?, ?, CURRENT_TIMESTAMP)";
        $logStmt = $conn->prepare($logSql);
        
        if (!$logStmt) {
            throw new Exception('Failed to prepare log statement: ' . $conn->error);
        }
        
        $logStmt->bind_param("iss", $loggedInUserId, $order_id, $log_details);
        
        if (!$logStmt->execute()) {
            throw new Exception('Failed to insert user log: ' . $logStmt->error);
        }
        
        $logStmt->close();
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Lead reassigned successfully',
            'new_user_id' => $new_user_id,
            'new_user_name' => $newUserName
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
