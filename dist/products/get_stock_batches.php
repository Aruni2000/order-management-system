<?php
// Start session and check if user is logged in
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

header('Content-Type: application/json');

$product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
$session_tenant_id = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
$is_main_admin = isset($_SESSION['is_main_admin']) ? (int)$_SESSION['is_main_admin'] : 0;

if ($product_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid product ID.']);
    $conn->close();
    exit();
}

// Fetch confirmed batches for the product with remaining stock (with tenant isolation)
$batches = [];
if ($is_main_admin && $_SESSION['role_id'] == 1) {
    $sql = "SELECT b.batch_id, b.batch_number, b.selling_price, b.buying_price,
                   b.remaining_qty, b.received_date
            FROM batches b
            WHERE b.product_id = ? AND b.status = 'confirmed' AND b.remaining_qty > 0
            ORDER BY b.received_date ASC, b.batch_id ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $product_id);
} else {
    $sql = "SELECT b.batch_id, b.batch_number, b.selling_price, b.buying_price,
                   b.remaining_qty, b.received_date
            FROM batches b
            WHERE b.product_id = ? AND b.tenant_id = ? AND b.status = 'confirmed' AND b.remaining_qty > 0
            ORDER BY b.received_date ASC, b.batch_id ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $product_id, $session_tenant_id);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $batches[] = [
        'batch_id' => (int)$row['batch_id'],
        'batch_number' => $row['batch_number'],
        'selling_price' => floatval($row['selling_price']),
        'buying_price' => floatval($row['buying_price']),
        'remaining_qty' => (int)$row['remaining_qty'],
        'received_date' => $row['received_date']
    ];
}
$stmt->close();
$conn->close();

echo json_encode(['success' => true, 'product_id' => $product_id, 'batches' => $batches]);
exit();