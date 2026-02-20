<?php
// -------------------------
// Basic Session + Auth
// -------------------------
if (!session_id()) {
    session_start();
}

if (!isset($_SESSION['logged_in']) && !isset($_SESSION['ClientUserID'])) {
    header("Location: /OMS/dist/pages/login.php");
    exit();
}
$logged_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

// DB connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// -------------------------
// Read Filters
// -------------------------
$date      = isset($_GET['date']) ? trim($_GET['date']) : "";
$time_from = trim($_GET['time_from'] ?? "");
$time_to   = trim($_GET['time_to'] ?? "");
$tenant_filter = isset($_GET['tenant_filter']) ? trim($_GET['tenant_filter']) : '';
$date_filter = isset($_GET['date_filter']) ? $_GET['date_filter'] : 'created_at';
$limit     = 500; // fixed limit

// -------------------------
// Sanitize Inputs
// -------------------------
$date      = $conn->real_escape_string($date);
$time_from = $conn->real_escape_string($time_from);
$time_to   = $conn->real_escape_string($time_to);

// Validate date_filter to prevent SQL injection
$allowed_filters = ['created_at', 'updated_at', 'issue_date'];
$date_filter = in_array($date_filter, $allowed_filters) ? $date_filter : 'created_at';

// -------------------------
// Build WHERE Conditions
// -------------------------
$where = [];
$where[] = "o.interface IN ('individual','leads')";
$where[] = "o.status = 'dispatch'";

// Tenant Filtering Logic
$is_main_admin = isset($_SESSION['is_main_admin']) ? (int)$_SESSION['is_main_admin'] : 0;
$role_id = isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : 0;
$session_tenant_id = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;

if ($is_main_admin === 1 && $role_id === 1) {
    // Main Admin
    if (!empty($tenant_filter)) {
        $where[] = "o.tenant_id = " . (int)$tenant_filter;
    }
} elseif ($role_id === 1 && $is_main_admin === 0) {
    // Tenant Admin
    $where[] = "o.tenant_id = $session_tenant_id";
} else {
    // Regular User
    $where[] = "o.tenant_id = $session_tenant_id";
    $where[] = "o.user_id = $logged_user_id";
}

// Default date
if ($date === "" || !isset($_GET['date'])) {
    $date = date("Y-m-d");
}

// Normalize time inputs (HH:MM format)
$time_from = preg_match('/^\d{1,2}:\d{2}$/', $time_from) ? $time_from : "";
$time_to   = preg_match('/^\d{1,2}:\d{2}$/', $time_to) ? $time_to : "";

// Default start and end
$startDateTime = $date . " 00:00:00";
$endDateTime   = $date . " 23:59:59";

// Apply time range filter
if ($time_from !== "" && $time_to !== "") {
    $startDateTime = $date . " $time_from:00";
    $endDateTime   = $date . " $time_to:59";
} elseif ($time_from !== "") {
    $startDateTime = $date . " $time_from:00";
    $endDateTime   = $date . " 23:59:59";
} elseif ($time_to !== "") {
    $startDateTime = $date . " 00:00:00";
    $endDateTime   = $date . " $time_to:59";
}

// Apply filter on selected date field
$where[] = "o.$date_filter BETWEEN '$startDateTime' AND '$endDateTime'";

// Always include only orders with tracking numbers
$where[] = "o.tracking_number IS NOT NULL AND o.tracking_number != ''";

$whereClause = implode(" AND ", $where);

// -------------------------
// Main Orders Query (without products)
// -------------------------
$sql = "
 SELECT  
    o.order_id,
    o.tenant_id,
    o.tracking_number,
    o.pay_status,
    o.pay_by,
    o.pay_date,
    COALESCE(NULLIF(o.full_name,''), NULLIF(c.name,''), 'Unknown Customer') AS name,
    COALESCE(NULLIF(o.mobile,''), NULLIF(c.phone,''), 'No phone') AS phone,
    NULLIF(c.phone_2,'') AS phone_2,
    NULLIF(o.address_line1,'') AS o_addr1,
    NULLIF(o.address_line2,'') AS o_addr2,
    NULLIF(c.address_line1,'') AS c_addr1,
    NULLIF(c.address_line2,'') AS c_addr2,
    ct.city_name,
    o.total_amount,
    o.currency,
    o.created_at,
    o.updated_at,
    cr.courier_name
 FROM order_header o
 LEFT JOIN customers c ON o.customer_id = c.customer_id
 LEFT JOIN couriers cr ON o.co_id = cr.co_id
 LEFT JOIN city_table ct ON c.city_id = ct.city_id
 WHERE $whereClause
 ORDER BY o.$date_filter DESC
 LIMIT $limit
";

$res = $conn->query($sql);
$orders = [];
$order_ids = [];

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $orders[] = $row;
        $order_ids[] = $row['order_id'];
    }
}

// -------------------------
// Fetch Products Separately with Aggregation
// -------------------------
$products_by_order = [];
if (!empty($order_ids)) {
    $order_ids_str = implode(',', array_map('intval', $order_ids));
    
    $products_sql = "
        SELECT 
            oi.order_id,
            oi.product_id,
            p.name as product_name,
            SUM(oi.quantity) as total_quantity
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id IN ($order_ids_str)
        GROUP BY oi.order_id, oi.product_id, p.name
        ORDER BY oi.order_id, oi.product_id
    ";
    
    $products_result = $conn->query($products_sql);
    if ($products_result) {
        while ($item = $products_result->fetch_assoc()) {
            if (!isset($products_by_order[$item['order_id']])) {
                $products_by_order[$item['order_id']] = [];
            }
            $products_by_order[$item['order_id']][] = $item;
        }
    }
}

// -------------------------
// Fetch All Brandings (Multi-Tenant Support)
// -------------------------
$brandings = [];
$brandingQuery = $conn->query("SELECT * FROM branding WHERE active = 1");
if ($brandingQuery) {
    while ($b = $brandingQuery->fetch_assoc()) {
        $brandings[$b['tenant_id']] = $b;
    }
}

// Function to get branding for a specific tenant
function getBrandingForTenant($tId, $brandings_list) {
    // If tenant specific branding exists
    if (!empty($tId) && isset($brandings_list[$tId])) {
        return $brandings_list[$tId];
    }
    // Fallback: Return first available branding or empty structure
    if (!empty($brandings_list)) {
        return reset($brandings_list);
    }
    return [
        'company_name' => '', 'address' => '', 'hotline' => '', 'email' => '', 'logo_url' => '', 'web_name' => ''
    ];
}

// -------------------------
// Helper Functions
// -------------------------
function currencySymbol($c)
{
    return strtolower($c) === "usd" ? "$" : "Rs.";
}

function barcodeImg($d)
{
    return "https://barcodeapi.org/api/code128/" . urlencode($d);
}

function formatProducts($order_id, $products_by_order)
{
    if (!isset($products_by_order[$order_id]) || empty($products_by_order[$order_id])) {
        return 'N/A';
    }
    
    $product_list = [];
    foreach ($products_by_order[$order_id] as $item) {
        $product_list[] = $item['product_id'] . ' - ' . $item['product_name'] . ' (' . $item['total_quantity'] . ')';
    }
    
    return implode(', ', $product_list);
}

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Bulk Print - <?php echo count($orders); ?> Labels</title>

<style>
@page { 
    margin: 0; 
    size: 100mm 100mm;
}

body { 
    margin: 0; 
    font-family: Arial, sans-serif; 
    font-size: 12px; 
}

.label-box {
    width: 100mm;
    height: 98mm;
    padding: 8px;
    border: 1px solid #ccc;
    box-sizing: border-box;
    page-break-after: always;
    page-break-inside: avoid;
}

.small { 
    font-size: 10px; 
    color: #555; 
}

hr {
    border: none;
    border-top: 1px solid #ddd;
    margin: 5px 0;
}

/* Print Styles */
@media print {
    body {
        margin: 0;
        padding: 0;
    }
    
    .label-box {
        border: none;
        margin: 0;
        padding: 8px;
    }
}

/* No orders message */
.no-orders {
    padding: 40px;
    text-align: center;
    font-size: 16px;
    color: #666;
}
</style>

<script>
// Auto-print when page loads
window.onload = function() {
    setTimeout(function() {
        window.print();
    }, 500);
};
</script>

</head>

<body>

<?php if (empty($orders)): ?>
    <div class="no-orders">
        <h2>No Orders Found</h2>
        <p>No orders found for the selected date and filters.</p>
        <p>Please go back and adjust your search criteria.</p>
    </div>
<?php else: ?>

    <?php foreach ($orders as $o): 
        // Resolve branding for this order
        $tId = isset($o['tenant_id']) ? $o['tenant_id'] : 0;
        $bData = getBrandingForTenant($tId, $brandings);
    ?>

    <div class="label-box">

        <!-- Header -->
        <table width="100%">
            <tr>
                <td width="60">
                    <?php if (!empty($bData['logo_url'])): ?>
                        <img src="<?php echo htmlspecialchars($bData['logo_url']); ?>" width="50" alt="Company Logo">
                    <?php endif; ?>
                </td>
                <td style="text-align:right;">
                    <b>Order: <?php echo htmlspecialchars($o['order_id']); ?></b><br>
                    <span class="small"><?php echo htmlspecialchars($o['created_at'] ?: date("Y-m-d")); ?></span><br>
                    <span class="small"><?php echo htmlspecialchars($o['courier_name'] ?: "-"); ?></span><br>
                    <?php if (!empty($o['pay_status']) && $o['pay_status'] === 'paid'): ?>
                        <span style="color: green; font-weight: bold; font-size: 11px; display: inline-block; margin-top: 3px; padding: 2px 6px; background-color: #d4edda; border-radius: 3px;">
                            ✔ PAID
                        </span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <!-- Billing From Details -->
        <div style="font-size:10px; margin-top:5px;">
            <?php echo htmlspecialchars($bData['company_name']); ?><br>
            <?php echo nl2br(htmlspecialchars($bData['address'])); ?><br>
            Hotline: <?php echo htmlspecialchars($bData['hotline']); ?><br>
            Email: <?php echo htmlspecialchars($bData['email']); ?>
        </div>

        <hr>

        <!-- Customer Details -->
        <b><?php echo htmlspecialchars($o['name']); ?></b><br>
        Phone: <?php echo htmlspecialchars($o['phone']); ?><br>
        <?php if (!empty($o['phone_2'])): ?>
            Phone 2: <?php echo htmlspecialchars($o['phone_2']); ?><br>
        <?php endif; ?>

        <?php
            $addr = $o['o_addr1'] ?: $o['c_addr1'];
            $addr2 = $o['o_addr2'] ?: $o['c_addr2'];
        ?>
        Address: <?php echo htmlspecialchars(trim($addr . " " . $addr2)); ?><br>
        <?php if (!empty($o['city_name'])): ?>
            City: <?php echo htmlspecialchars($o['city_name']); ?><br>
        <?php endif; ?>

        <hr>

        <!-- Products -->
        <b>Products:</b><br>
        <span class="small"><?php echo htmlspecialchars(formatProducts($o['order_id'], $products_by_order)); ?></span>

        <hr>

              <!-- Total Amount -->
          <?php if ($o['pay_status'] !== 'paid'): ?>
            <b>Total: <?php echo currencySymbol($o['currency']) . " " . number_format($o['total_amount'], 2); ?></b><br>
            <?php endif; ?>
        <br>

        <!-- Barcode -->
    <?php if (!empty($o['tracking_number'])): ?>
    <div style="text-align:center;">
        <img src="<?php echo barcodeImg($o['tracking_number']); ?>" 
             style="width:155px; height:auto;" 
             alt="<?php echo htmlspecialchars($o['tracking_number']); ?>">
    </div>
<?php endif; ?>


    </div>

    <?php endforeach; ?>

<?php endif; ?>

</body>
</html>

<?php $conn->close(); ?>