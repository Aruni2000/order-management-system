<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

$product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
$tenant_id = isset($_GET['tenant_id']) ? intval($_GET['tenant_id']) : (isset($_SESSION['tenant_id']) ? intval($_SESSION['tenant_id']) : 0);

if ($product_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid product ID.']);
    exit();
}

$allow_inventory = isset($_SESSION['allow_inventory']) && $_SESSION['allow_inventory'] == 1;

// Return distinct selling prices grouped by price, with aggregate remaining stock per price.
// Pricing is driven entirely by confirmed batches (lkr_price column was dropped from products).
// When allow_inventory is disabled, do not restrict to remaining_qty > 0 so prices remain accessible.
$prices = [];
$stockCondition = $allow_inventory ? "AND b.remaining_qty > 0" : "";
$sql = "SELECT b.selling_price, SUM(b.remaining_qty) AS total_stock
        FROM batches b
        WHERE b.product_id = ? AND b.status = 'confirmed' $stockCondition
        GROUP BY b.selling_price
        ORDER BY b.selling_price ASC, MIN(b.received_date) ASC";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $conn->error]);
    exit();
}
$stmt->bind_param("i", $product_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $selling_price = floatval($row['selling_price']);
    $stock = $allow_inventory ? (int)$row['total_stock'] : 999999;
    $label = $allow_inventory
        ? ('Rs. ' . number_format($selling_price, 2) . ' (Stock: ' . (int)$row['total_stock'] . ')')
        : ('Rs. ' . number_format($selling_price, 2));

    $prices[] = [
        'selling_price'   => $selling_price,
        'stock'           => $stock,
        'formatted_label' => $label
    ];
}
$stmt->close();
$conn->close();

echo json_encode([
    'success'         => true,
    'product_id'      => $product_id,
    'allow_inventory' => $allow_inventory,
    'prices'          => $prices
]);
exit();
