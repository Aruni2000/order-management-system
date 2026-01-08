<?php
/**
 * Get Couriers for Bulk Dispatch
 * Returns couriers available for the selected orders' tenant(s)
 * Ensures all selected orders belong to the same tenant
 */

session_start();

// Set JSON header
header('Content-Type: application/json');

// Authentication check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/connection/db_connection.php');

// Get order IDs from request
$order_ids_json = isset($_GET['order_ids']) ? $_GET['order_ids'] : '[]';
$order_ids = json_decode($order_ids_json, true);

// Validate input
if (empty($order_ids) || !is_array($order_ids)) {
    echo json_encode([
        'success' => false,
        'message' => 'No order IDs provided'
    ]);
    exit();
}

// Sanitize order IDs for SQL
$sanitized_order_ids = array_map(function($id) use ($conn) {
    return "'" . $conn->real_escape_string(trim($id)) . "'";
}, $order_ids);

$order_ids_string = implode(',', $sanitized_order_ids);

// Get unique tenant IDs from selected orders
$tenant_query = "SELECT DISTINCT tenant_id 
                 FROM order_header 
                 WHERE order_id IN ($order_ids_string)";

$tenant_result = $conn->query($tenant_query);

if (!$tenant_result) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $conn->error
    ]);
    exit();
}

if ($tenant_result->num_rows === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'No tenant found for selected orders'
    ]);
    exit();
}

// Collect all tenant IDs
$tenant_ids = [];
while ($row = $tenant_result->fetch_assoc()) {
    $tenant_ids[] = (int)$row['tenant_id'];
}

// IMPORTANT: Check if all orders belong to the same tenant
if (count($tenant_ids) > 1) {
    echo json_encode([
        'success' => false,
        'message' => 'Selected orders belong to different tenants. Please select orders from the same tenant for bulk dispatch.',
        'tenant_count' => count($tenant_ids),
        'tenant_ids' => $tenant_ids
    ]);
    exit();
}

$tenant_id = $tenant_ids[0];

// Get active couriers for this specific tenant
$courier_query = "SELECT courier_id, courier_name, co_id 
                  FROM couriers 
                  WHERE status = 'active' 
                  AND tenant_id = ? 
                  ORDER BY courier_name ASC";

$stmt = $conn->prepare($courier_query);
$stmt->bind_param("i", $tenant_id);
$stmt->execute();
$courier_result = $stmt->get_result();

$couriers = [];
if ($courier_result && $courier_result->num_rows > 0) {
    while ($courier = $courier_result->fetch_assoc()) {
        $couriers[] = [
            'courier_id' => (int)$courier['courier_id'],
            'courier_name' => $courier['courier_name'],
            'co_id' => $courier['co_id']
        ];
    }
}

$stmt->close();
$conn->close();

// Return success response
echo json_encode([
    'success' => true,
    'tenant_id' => $tenant_id,
    'couriers' => $couriers,
    'courier_count' => count($couriers),
    'order_count' => count($order_ids),
    'message' => count($couriers) > 0 
        ? count($couriers) . ' courier(s) available for tenant ID: ' . $tenant_id
        : 'No couriers available for this tenant'
]);
?>