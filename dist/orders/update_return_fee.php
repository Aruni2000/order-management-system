<?php
// Start session at the very beginning
session_start();

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // Clear any existing output buffers
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /order_management/dist/pages/login.php");
    exit();
}

// Include the database connection file
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/connection/db_connection.php');

// Set content type to JSON
header('Content-Type: application/json');

// Validate inputs
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Accept co_id as input
    $co_id = isset($_POST['co_id']) ? intval($_POST['co_id']) : 0;
    $return_fee_value = isset($_POST['return_fee_value']) ? floatval($_POST['return_fee_value']) : 0.00;

    if ($co_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid courier ID.']);
        exit;
    }

    // First, check if courier exists and get courier details
    $checkSql = "SELECT co_id, courier_id, courier_name, return_fee_value FROM couriers WHERE co_id = ?";
    $checkStmt = $conn->prepare($checkSql);
    
    if ($checkStmt === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
        exit;
    }
    
    $checkStmt->bind_param("i", $co_id);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Courier not found.']);
        $checkStmt->close();
        $conn->close();
        exit;
    }
    
    $courier = $result->fetch_assoc();
    $courier_id = $courier['courier_id'];
    $courier_name = $courier['courier_name'];
    $old_return_fee = $courier['return_fee_value'];
    $checkStmt->close();
    
    // Check if value actually changed
    if (abs($old_return_fee - $return_fee_value) < 0.01) {
        echo json_encode([
            'success' => false, 
            'message' => 'No changes made. The return fee is already set to this value.'
        ]);
        $conn->close();
        exit;
    }

    // Update query using co_id
    $sql = "UPDATE couriers 
            SET return_fee_value = ?, updated_at = CURRENT_TIMESTAMP 
            WHERE co_id = ?";
    $stmt = $conn->prepare($sql);
    
    if ($stmt === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
        exit;
    }

    $stmt->bind_param("di", $return_fee_value, $co_id);

    if ($stmt->execute()) {
        // Log the change
        $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
        $log_details = "Return fee updated for {$courier_name} (ID: {$courier_id}, co_id: {$co_id}). Changed from {$old_return_fee} to {$return_fee_value}";
        $action_type = 'RETURN_FEE_UPDATE';
        
        $logSql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details) VALUES (?, ?, ?, ?)";
        $logStmt = $conn->prepare($logSql);
        if ($logStmt) {
            $logStmt->bind_param("isis", $user_id, $action_type, $courier_id, $log_details);
            $logStmt->execute();
            $logStmt->close();
        }
        
        echo json_encode([
            'success' => true, 
            'message' => 'Return fee updated successfully!',
            'data' => [
                'co_id' => $co_id,
                'courier_id' => $courier_id,
                'courier_name' => $courier_name,
                'old_value' => $old_return_fee,
                'new_value' => $return_fee_value
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error updating return fee: ' . $stmt->error]);
    }

    $stmt->close();
    $conn->close();
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}
?>