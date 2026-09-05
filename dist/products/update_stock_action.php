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

// Check if user is main admin (Admin only access)
$is_main_admin = isset($_SESSION['is_main_admin']) ? (int)$_SESSION['is_main_admin'] : 0;
if ($is_main_admin != 1) {
    echo json_encode(['success' => false, 'message' => 'Access denied. Only administrators can update stock.']);
    exit();
}

// Check if inventory management is enabled
if (!isset($_SESSION['allow_inventory']) || $_SESSION['allow_inventory'] != 1) {
    echo json_encode(['success' => false, 'message' => 'Inventory management is disabled.']);
    exit();
}

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
    $batch_id = isset($input['batch_id']) ? (int)$input['batch_id'] : 0;
    $reason = isset($input['reason']) ? trim((string)$input['reason']) : '';
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
    if ($batch_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'A batch must be selected']);
        exit();
    }
    if ($reason === '') {
        echo json_encode(['success' => false, 'message' => 'A reason for the stock adjustment is required']);
        exit();
    }
    
    // Check if product exists
    $session_tenant_id = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
    $is_main_admin = isset($_SESSION['is_main_admin']) ? (int)$_SESSION['is_main_admin'] : 0;
    
    if ($is_main_admin) {
        $checkSql = "SELECT id, name, stock_quantity FROM products WHERE id = ?";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("i", $product_id);
    } else {
        $checkSql = "SELECT id, name, stock_quantity FROM products WHERE id = ? AND tenant_id = ?";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("ii", $product_id, $session_tenant_id);
    }
    
    if (!$checkStmt) {
        echo json_encode(['success' => false, 'message' => 'Database prepare error: ' . $conn->error]);
        exit();
    }
    
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit();
    }
    
    $product = $result->fetch_assoc();
    $old_stock = $product['stock_quantity'];
    $checkStmt->close();

    // Check batch exists, belongs to product, and supports the operation
    if ($is_main_admin) {
        $batchSql = "SELECT batch_id, batch_number, remaining_qty, status FROM batches WHERE batch_id = ? AND product_id = ?";
        $batchStmt = $conn->prepare($batchSql);
        $batchStmt->bind_param("ii", $batch_id, $product_id);
    } else {
        $batchSql = "SELECT batch_id, batch_number, remaining_qty, status FROM batches WHERE batch_id = ? AND product_id = ? AND tenant_id = ?";
        $batchStmt = $conn->prepare($batchSql);
        $batchStmt->bind_param("iii", $batch_id, $product_id, $session_tenant_id);
    }
    if (!$batchStmt) {
        echo json_encode(['success' => false, 'message' => 'Database prepare error: ' . $conn->error]);
        exit();
    }
    
    $batchStmt->execute();
    $batchResult = $batchStmt->get_result();

    if ($batchResult->num_rows === 0) {
        $batchStmt->close();
        echo json_encode(['success' => false, 'message' => 'Batch not found for this product']);
        exit();
    }

    $batch = $batchResult->fetch_assoc();
    $batchStmt->close();

    if ($batch['status'] !== 'confirmed') {
        echo json_encode(['success' => false, 'message' => 'Selected batch is not confirmed']);
        exit();
    }

    if ($operation === 'decrease' && (int)$batch['remaining_qty'] < $adjustment) {
        echo json_encode(['success' => false, 'message' => 'Batch remaining stock is insufficient (available: ' . $batch['remaining_qty'] . ')']);
        exit();
    }

    $old_batch_qty = (int)$batch['remaining_qty'];
    $batch_number = $batch['batch_number'];

    // Calculate new product stock
    if ($operation === 'increase') {
        $new_stock = $old_stock + $adjustment;
        $new_batch_qty = $old_batch_qty + $adjustment;
        $description = "Increased by " . $adjustment;
    } else {
        $new_stock = max(0, $old_stock - $adjustment);
        $new_batch_qty = max(0, $old_batch_qty - $adjustment);
        $description = "Decreased by " . $adjustment;
    }
    
    // Begin transaction
    $conn->autocommit(FALSE);
    
    try {
        // Update batch remaining quantity
        $updateBatchSql = "UPDATE batches SET remaining_qty = ? WHERE batch_id = ?";
        $updateBatchStmt = $conn->prepare($updateBatchSql);
        
        if (!$updateBatchStmt) {
            throw new Exception('Batch update prepare error: ' . $conn->error);
        }
        
        $updateBatchStmt->bind_param("ii", $new_batch_qty, $batch_id);
        
        if (!$updateBatchStmt->execute()) {
            throw new Exception('Failed to update batch quantity: ' . $updateBatchStmt->error);
        }
        $updateBatchStmt->close();

        // Update product stock (with tenant isolation)
        if ($is_main_admin) {
            $updateSql = "UPDATE products SET stock_quantity = ? WHERE id = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bind_param("ii", $new_stock, $product_id);
        } else {
            $updateSql = "UPDATE products SET stock_quantity = ? WHERE id = ? AND tenant_id = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bind_param("iii", $new_stock, $product_id, $session_tenant_id);
        }
        
        if (!$updateStmt) {
            throw new Exception('Database prepare error: ' . $conn->error);
        }
        
        if (!$updateStmt->execute()) {
            throw new Exception('Failed to update stock: ' . $updateStmt->error);
        }
        $updateStmt->close();
        
        // Log the action
        $action_type = 'product_stock_updated';
        $details = "Updated Stock for Product '{$product['name']}' " . $description
            . " (from {$old_stock} to {$new_stock}), Batch #" . ($batch_number ?: $batch_id)
            . " (from {$old_batch_qty} to {$new_batch_qty}), Reason: {$reason}";
        
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
            'batch_id' => $batch_id,
            'old_stock' => $old_stock,
            'new_stock' => $new_stock,
            'old_batch_qty' => $old_batch_qty,
            'new_batch_qty' => $new_batch_qty,
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
