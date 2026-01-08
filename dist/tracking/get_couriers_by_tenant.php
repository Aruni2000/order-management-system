<?php
// Start session
session_start();

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/connection/db_connection.php');

// Set content type to JSON
header('Content-Type: application/json');

// Check if tenant_id is provided
if (!isset($_GET['tenant_id']) || empty($_GET['tenant_id'])) {
    echo json_encode(['success' => false, 'message' => 'Tenant ID is required']);
    exit();
}

// Sanitize and validate tenant_id
$tenantId = intval($_GET['tenant_id']);

if ($tenantId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid tenant ID']);
    exit();
}

// Prepare SQL to fetch couriers for the selected tenant
$sql = "SELECT courier_id, courier_name 
        FROM couriers 
        WHERE tenant_id = ? AND status = 'active' 
        ORDER BY courier_name ASC";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    exit();
}

$stmt->bind_param("i", $tenantId);
$stmt->execute();
$result = $stmt->get_result();

$couriers = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $couriers[] = [
            'courier_id' => $row['courier_id'],
            'courier_name' => $row['courier_name'],
            'display_name' => $row['courier_name'] . ' (ID: ' . $row['courier_id'] . ')'
        ];
    }
}

$stmt->close();
$conn->close();

// Return JSON response
echo json_encode([
    'success' => true,
    'couriers' => $couriers,
    'count' => count($couriers)
]);
?>