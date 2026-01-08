<?php
session_start();
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/connection/db_connection.php');

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Get order_id from request
$order_id = isset($_GET['order_id']) ? trim($_GET['order_id']) : '';

if (empty($order_id)) {
    echo json_encode(['success' => false, 'message' => 'Order ID required']);
    exit();
}

// Get the tenant_id for this order
$order_query = "SELECT tenant_id FROM order_header WHERE order_id = ? LIMIT 1";
$stmt = $conn->prepare($order_query);
$stmt->bind_param("s", $order_id);
$stmt->execute();
$order_result = $stmt->get_result();

if ($order_result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Order not found']);
    exit();
}

$order_data = $order_result->fetch_assoc();
$order_tenant_id = $order_data['tenant_id'];
$stmt->close();

// Get couriers for this tenant only
$courier_query = "SELECT courier_id, courier_name, co_id 
                  FROM couriers 
                  WHERE status = 'active' 
                  AND tenant_id = ? 
                  ORDER BY courier_name";
$stmt = $conn->prepare($courier_query);
$stmt->bind_param("i", $order_tenant_id);
$stmt->execute();
$courier_result = $stmt->get_result();

$couriers = [];
while ($courier = $courier_result->fetch_assoc()) {
    $couriers[] = [
        'courier_id' => $courier['courier_id'],
        'courier_name' => $courier['courier_name'],
        'co_id' => $courier['co_id']
    ];
}
$stmt->close();

echo json_encode([
    'success' => true,
    'couriers' => $couriers,
    'order_tenant_id' => $order_tenant_id
]);
?>