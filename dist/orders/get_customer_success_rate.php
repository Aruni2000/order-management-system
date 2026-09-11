<?php
// ============================================================
// CUSTOMER SUCCESS RATE - HOW IT IS CALCULATED
// ============================================================
// Simple idea: of a customer's total orders, what percentage
// "failed" (cancelled or returned)? That percentage decides the
// rating. Same logic as cs_condition().
//
// 1. COUNT TOTAL ORDERS - all orders for the customer (any status)
//    - 0 orders -> New (rating 4)
// 2. COUNT FAILED ORDERS - status is one of:
//      cancel, return, return complete, return_handover,
//      return pending, return transfer, removed
// 3. FAILURE RATE = (failed / total) x 100  -> % of orders that failed
// 4. RATING from the failure %:
//      0 failed orders  -> Excellent (0)
//      0%    - 25%      -> Excellent (0)
//      >25%  - 50%      -> Good      (1)
//      >50%  - 75%      -> Average   (2)
//      >75%             -> Bad       (3)
//
// Example: 10 orders, 2 cancelled/returned
//   rate = (2 / 10) x 100 = 20%   -> Excellent
// ============================================================
session_start();

header('Content-Type: application/json');

// Authentication check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['found' => false, 'error' => 'Authentication required']);
    exit();
}

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// UPDATED: Get tenant_id from GET parameter (for admin switching) or session
$tenant_id = isset($_GET['tenant_id']) ? intval($_GET['tenant_id']) : ($_SESSION['tenant_id'] ?? 0);

if ($tenant_id === 0) {
    echo json_encode(['found' => false, 'error' => 'Invalid tenant']);
    exit();
}

// Function to calculate customer success rate
// EXACT COPY of cs_condition() logic - per-tenant
function cs_condition($conn, $customer_id, $tenant_id) {
    if (!$customer_id) return 0;

    // Total orders
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total FROM order_header WHERE customer_id = ? AND tenant_id = ?"
    );
    $stmt->bind_param("ii", $customer_id, $tenant_id);
    $stmt->execute();
    $totalOrders = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $stmt->close();
    error_log("DEBUG: cs_condition - customer_id: $customer_id, tenant_id: $tenant_id, totalOrders: $totalOrders");

    if ($totalOrders == 0) return 4; // New

    // Failed orders (return + cancel)
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS failed
         FROM order_header
         WHERE customer_id = ?
         AND tenant_id = ?
         AND status IN ('cancel', 'return', 'return complete', 'return_handover', 'return pending', 'return transfer','removed')"
    );
    $stmt->bind_param("ii", $customer_id, $tenant_id);
    $stmt->execute();
    $failedOrders = $stmt->get_result()->fetch_assoc()['failed'] ?? 0;
    $stmt->close();

    // If no failed orders to Excellent
    if ($failedOrders == 0) return 0;

    $rate = ($failedOrders / $totalOrders) * 100;
    
    if (($rate >= 0) && ($rate <= 25)) return 0; // Excellent
    if (($rate > 25) && ($rate <= 50)) return 1;  // Good
    if (($rate > 50) && ($rate <= 75)) return 2;  // Average
    if (($rate > 75)) return 3;                  // Bad
}

// Success rate labels and badge classes - same as pending_order_list.php
$conditionLabels = [
    0 => ['label' => 'Excellent', 'css_class' => 'rate-excellent'],
    1 => ['label' => 'Good', 'css_class' => 'rate-good'],
    2 => ['label' => 'Average', 'css_class' => 'rate-average'],
    3 => ['label' => 'Bad', 'css_class' => 'rate-bad'],
    4 => ['label' => 'New', 'css_class' => 'rate-new'],
];

$phone = isset($_GET['phone']) ? trim($_GET['phone']) : '';

// Normalize the phone number (strip non-digits) to tolerate formatting differences
$phoneDigits = preg_replace('/\D+/', '', $phone);

if ($phoneDigits === '') {
    echo json_encode(['found' => false]);
    exit();
}

// Resolve the customer EXACTLY like process_order.php STEP 1:
// match phone in BOTH phone and phone_2 columns, Active only, same tenant, first match
$customer_id = 0;
$checkPhoneSql = "SELECT customer_id, name, email, phone, phone_2 
                  FROM customers 
                  WHERE (phone = ? OR phone_2 = ?) 
                  AND tenant_id = ?
                  AND status = 'Active'
                  LIMIT 1";
$stmt = $conn->prepare($checkPhoneSql);
if ($stmt) {
    $stmt->bind_param("ssi", $phone, $phone, $tenant_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $existing_customer = $result->fetch_assoc();
            $customer_id = (int)$existing_customer['customer_id'];
        }
    }
    $stmt->close();
}

// Phone not registered yet -> the order would create a new customer with zero
// order history, so cs_condition() would return 4 (New)
if (empty($customer_id)) {
    echo json_encode([
        'found' => true,
        'condition' => 4,
        'label' => $conditionLabels[4]['label'],
        'css_class' => $conditionLabels[4]['css_class']
    ]);
    exit();
}

// Same calculation as process_order.php: identical function, single customer_id
$condition = cs_condition($conn, $customer_id, $tenant_id);

$info = $conditionLabels[$condition] ?? $conditionLabels[4];

echo json_encode([
    'found' => true,
    'condition' => $condition,
    'label' => $info['label'],
    'css_class' => $info['css_class']
]);

$conn->close();
?>
