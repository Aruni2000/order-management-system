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

if ((int)($_SESSION['role_id'] ?? 0) == 2) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to modify categories.']);
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/connection/db_connection.php');

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
    if (!isset($input['category_id']) || !isset($input['new_status'])) {
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
        exit();
    }
    
    $category_id = (int)$input['category_id'];
    $new_status = trim($input['new_status']);
    $user_id = $_SESSION['user_id'];
    
    // Validate category ID
    if ($category_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid category ID']);
        exit();
    }
    
    // Validate status value
    if (!in_array($new_status, ['active', 'inactive'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid status value']);
        exit();
    }
    
    // Tenant isolation: main admin can toggle any tenant's category, others only their own
    $is_main_admin = (int)($_SESSION['is_main_admin'] ?? 0);
    $role_id = (int)($_SESSION['role_id'] ?? 0);
    $session_tenant_id = (int)($_SESSION['tenant_id'] ?? 0);

    // Check if category exists
    if ($is_main_admin === 1 && $role_id === 1) {
        $checkSql = "SELECT id, name, status FROM categories WHERE id = ?";
        $checkStmt = $conn->prepare($checkSql);
    } else {
        $checkSql = "SELECT id, name, status FROM categories WHERE id = ? AND tenant_id = ?";
        $checkStmt = $conn->prepare($checkSql);
    }
    
    if (!$checkStmt) {
        echo json_encode(['success' => false, 'message' => 'Database prepare error: ' . $conn->error]);
        exit();
    }
    
    if ($is_main_admin === 1 && $role_id === 1) {
        $checkStmt->bind_param("i", $category_id);
    } else {
        $checkStmt->bind_param("ii", $category_id, $session_tenant_id);
    }
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Category not found']);
        exit();
    }
    
    $category = $result->fetch_assoc();
    $checkStmt->close();
    
    // Check if status is already the same
    if ($category['status'] === $new_status) {
        echo json_encode([
            'success' => true, 
            'message' => 'Category status is already ' . $new_status,
            'current_status' => $new_status
        ]);
        exit();
    }
    
    // Begin transaction for update
    $conn->autocommit(FALSE);
    
    try {
        // Update category status (keep tenant constraint for non-main admin as a safeguard)
        if ($is_main_admin === 1 && $role_id === 1) {
            $updateSql = "UPDATE categories SET status = ? WHERE id = ?";
            $updateStmt = $conn->prepare($updateSql);
        } else {
            $updateSql = "UPDATE categories SET status = ? WHERE id = ? AND tenant_id = ?";
            $updateStmt = $conn->prepare($updateSql);
        }
        
        if (!$updateStmt) {
            throw new Exception('Database prepare error: ' . $conn->error);
        }
        
        if ($is_main_admin === 1 && $role_id === 1) {
            $updateStmt->bind_param("si", $new_status, $category_id);
        } else {
            $updateStmt->bind_param("sii", $new_status, $category_id, $session_tenant_id);
        }
        
        if (!$updateStmt->execute()) {
            throw new Exception('Failed to update category status: ' . $updateStmt->error);
        }
        
        $updateStmt->close();
        
        // Optional: User logging (if user_logs table exists and we want to track this)
        // For now, mirroring product toggle logic
        $action_type = $new_status === 'active' ? 'category_activated' : 'category_deactivated';
        $details = ($new_status === 'active' ? 'Activated' : 'Deactivated') . " Category '{$category['name']}'";
        
        $logSql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, ?, ?, ?, NOW())";
        $logStmt = $conn->prepare($logSql);
        
        if ($logStmt) {
            $logStmt->bind_param("isis", $user_id, $action_type, $category_id, $details);
            $logStmt->execute();
            $logStmt->close();
        }
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Category status updated successfully',
            'category_id' => $category_id,
            'category_name' => $category['name'],
            'old_status' => $category['status'],
            'new_status' => $new_status
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction
        $conn->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Toggle category status error: " . $e->getMessage());
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