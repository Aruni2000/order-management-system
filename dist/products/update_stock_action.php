<?php
// Start session and check if user is logged in
session_start();

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check if user_id is available in session
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'User ID not found in session']);
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Set content type to JSON
header('Content-Type: application/json');

// Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate input
    if (!isset($input['product_id']) || !isset($input['operation']) || !isset($input['adjustment_value'])) {
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
        exit();
    }
    
    $product_id = (int)$input['product_id'];
    $operation = $input['operation'];
    $adjustment = (int)$input['adjustment_value'];
    $user_id = $_SESSION['user_id'];
    
    // Validate values
    if ($product_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
        exit();
    }
    if ($adjustment <= 0) {
        echo json_encode(['success' => false, 'message' => 'Adjustment value must be greater than zero']);
        exit();
    }
    if (!in_array($operation, ['increase', 'decrease'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid operation type']);
        exit();
    }
    
    // Check if product exists
    $checkSql = "SELECT id, name, stock_quantity FROM products WHERE id = ?";
    $checkStmt = $conn->prepare($checkSql);
    
    if (!$checkStmt) {
        echo json_encode(['success' => false, 'message' => 'Database prepare error: ' . $conn->error]);
        exit();
    }
    
    $checkStmt->bind_param("i", $product_id);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit();
    }
    
    $product = $result->fetch_assoc();
    $old_stock = $product['stock_quantity'];
    $checkStmt->close();
    
    // Calculate new stock
    if ($operation === 'increase') {
        $new_stock = $old_stock + $adjustment;
        $description = "Increased by " . $adjustment;
    } else {
        $new_stock = max(0, $old_stock - $adjustment);
        $description = "Decreased by " . $adjustment;
    }
    
    // Begin transaction
    $conn->autocommit(FALSE);
    
    try {
        // Update stock
        $updateSql = "UPDATE products SET stock_quantity = ? WHERE id = ?";
        $updateStmt = $conn->prepare($updateSql);
        
        if (!$updateStmt) {
            throw new Exception('Database prepare error: ' . $conn->error);
        }
        
        $updateStmt->bind_param("ii", $new_stock, $product_id);
        
        if (!$updateStmt->execute()) {
            throw new Exception('Failed to update stock: ' . $updateStmt->error);
        }
        $updateStmt->close();
        
        // Log the action
        $action_type = 'product_stock_updated';
        $details = "Updated Stock for Product '{$product['name']}' " . $description . " (from {$old_stock} to {$new_stock})";
        
        $logSql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, ?, ?, ?, NOW())";
        $logStmt = $conn->prepare($logSql);
        
        if (!$logStmt) {
            throw new Exception('Log prepare error: ' . $conn->error);
        }
        
        $logStmt->bind_param("isis", $user_id, $action_type, $product_id, $details);
        
        if (!$logStmt->execute()) {
            throw new Exception('Failed to insert user log: ' . $logStmt->error);
        }
        $logStmt->close();
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Stock ' . $operation . 'd successfully',
            'product_id' => $product_id,
            'old_stock' => $old_stock,
            'new_stock' => $new_stock,
            'adjustment' => $adjustment
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Stock update error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'An unexpected error occurred'
    ]);
} finally {
    if (isset($conn)) {
        $conn->autocommit(TRUE);
        $conn->close();
    }
}
?>
