<?php
// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/connection/db_connection.php');

// Set content type to JSON
header('Content-Type: application/json');

// Check if required parameters are provided
if (!isset($_GET['courier_id']) || !isset($_GET['count']) || !isset($_GET['order_id'])) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing required parameters'
    ]);
    exit;
}

$courier_id = intval($_GET['courier_id']);
$count = intval($_GET['count']);
$order_id = $_GET['order_id'];

// Validate parameters
if ($courier_id <= 0 || $count <= 0 || empty($order_id)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid parameters'
    ]);
    exit;
}

try {
    // First, get the tenant_id from the order
    $tenant_query = "SELECT tenant_id FROM order_header WHERE order_id = ?";
    $tenant_stmt = $conn->prepare($tenant_query);
    $tenant_stmt->bind_param("s", $order_id);
    $tenant_stmt->execute();
    $tenant_result = $tenant_stmt->get_result();
    
    if ($tenant_result->num_rows === 0) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Order not found'
        ]);
        exit;
    }
    
    $tenant_data = $tenant_result->fetch_assoc();
    $tenant_id = $tenant_data['tenant_id'];
    $tenant_stmt->close();
    
    // Get total available unused tracking numbers for this courier AND tenant
    $count_query = "SELECT COUNT(*) as total FROM tracking 
                    WHERE courier_id = ? AND status = 'unused' AND tenant_id = ?";
    $count_stmt = $conn->prepare($count_query);
    $count_stmt->bind_param("ii", $courier_id, $tenant_id);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_available = $count_result->fetch_assoc()['total'];
    $count_stmt->close();
    
    // Get unused tracking numbers for the specified courier AND tenant (limit to requested count)
    $tracking_query = "SELECT tracking_id FROM tracking 
        WHERE courier_id = ? AND status = 'unused' AND tenant_id = ? 
        ORDER BY tracking_id ASC 
        LIMIT ?";
    
    $stmt = $conn->prepare($tracking_query);
    $stmt->bind_param("iii", $courier_id, $tenant_id, $count);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $tracking_numbers = [];
    while ($row = $result->fetch_assoc()) {
        $tracking_numbers[] = $row['tracking_id'];
    }
    $stmt->close();
    
    // Return response
    echo json_encode([
        'status' => 'success',
        'tracking_numbers' => $tracking_numbers,
        'available_count' => $total_available,
        'requested_count' => $count,
        'sufficient' => count($tracking_numbers) >= $count,
        'tenant_id' => $tenant_id // Optional: for debugging
    ]);
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>