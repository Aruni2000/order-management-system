<?php
// Start session at the very beginning
session_start();

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

// Include the database connection file
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');


// Access Control Variables
$is_main_admin = isset($_SESSION['is_main_admin']) ? (int)$_SESSION['is_main_admin'] : 0;
$role_id = isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : 0;
$session_tenant_id = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
$logged_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

// =============================================================
//  BRANDING DATA - MOVED BELOW TO USE ORDER TENANT ID
// =============================================================
// Placeholder: Branding logic is now executed after fetching the order to support multi-tenancy.
$company_name = "";
$company_address = "";
$company_email = "";
$company_hotline = "";
$company_logo = "";


// =============================================================
//  ORDER VALIDATION
// =============================================================
if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("Order ID is required");
}

$order_id = $_GET['id'];


// =============================================================
//  ORDER HEADER QUERY - REVISED FOR CUSTOMER DATA PRECEDENCE
//  Prioritizes order_header fields (o) over customer table fields (c)
// =============================================================
$order_query = "
    SELECT 
        o.*, 
        
        -- 1. Full Name: Prefer o.full_name, fall back to c.name
        COALESCE(NULLIF(o.full_name, ''), c.name, 'Unknown Customer') as display_name,

        -- 2. Mobile: Prefer o.mobile, fall back to c.phone
        COALESCE(NULLIF(o.mobile, ''), c.phone, 'No phone') as display_mobile,
        c.phone_2 as display_mobile_2, -- Secondary phone is usually only on customer table

        -- 3. City ID: Prefer o.city_id, fall back to c.city_id
        COALESCE(o.city_id, c.city_id) as final_city_id,
        
        -- 4. Address: Prefer o.address_line1/2, fall back to c.address_line1/2
        COALESCE(
            NULLIF(CONCAT_WS(', ', NULLIF(o.address_line1, ''), NULLIF(o.address_line2, '')), ''), 
            NULLIF(CONCAT_WS(', ', NULLIF(c.address_line1, ''), NULLIF(c.address_line2, '')), ''),
            'Address not available'
        ) as display_address,
        
        -- Other details from Order Header and Joins
        o.delivery_fee, o.discount, o.total_amount, o.issue_date, o.tracking_number,
        o.pay_status, o.pay_by, o.pay_date,
        
        cr.courier_name as delivery_service,
        ct.city_name -- City name from the joined city_table

    FROM order_header o 
    LEFT JOIN customers c ON o.customer_id = c.customer_id
    LEFT JOIN couriers cr ON o.co_id = cr.co_id
    
    -- Join city_table using the preferred city_id (o.city_id or c.city_id)
    LEFT JOIN city_table ct ON 
        ct.city_id = COALESCE(o.city_id, c.city_id) 
        AND ct.is_active = 1
        
    WHERE o.order_id = ?";

// Add Access Control Clauses
$params = [$order_id];
$types = "i";

if ($is_main_admin === 1 && $role_id === 1) {
    // Main Admin: No extra restrictions
} elseif ($role_id === 1 && $is_main_admin === 0) {
    // Tenant Admin: Restrict to tenant
    $order_query .= " AND o.tenant_id = ?";
    $params[] = $session_tenant_id;
    $types .= "i";
} else {
    // Regular User: Restrict to tenant AND assigned user
    $order_query .= " AND o.tenant_id = ? AND o.user_id = ?";
    $params[] = $session_tenant_id;
    $params[] = $logged_user_id;
    $types .= "ii";
}

$stmt = $conn->prepare($order_query);

if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Order not found or access denied.");
}

$order = $result->fetch_assoc();

// =============================================================
//  BRANDING DATA - FETCH BASED ON TENANT ID
// =============================================================
$order_tenant_id = isset($order['tenant_id']) ? (int)$order['tenant_id'] : 0;
$branding_sql = "SELECT * FROM branding WHERE tenant_id = $order_tenant_id AND active = 1 LIMIT 1";
$branding_result = $conn->query($branding_sql);

// Fallback to global active branding if tenant specific not found
if (!$branding_result || $branding_result->num_rows === 0) {
    $branding_sql = "SELECT * FROM branding WHERE active = 1 LIMIT 1";
    $branding_result = $conn->query($branding_sql);
}

$branding = $branding_result->fetch_assoc();

// Branding variables with safe fallbacks
$company_name = !empty($branding['company_name']) ? $branding['company_name'] : "";
$company_address = !empty($branding['address']) ? $branding['address'] : "";
$company_email = !empty($branding['email']) ? $branding['email'] : "";
$company_hotline = !empty($branding['hotline']) ? $branding['hotline'] : "";

//  GET LOGO FROM DATABASE (NOT HARDCODED)
if (!empty($branding['logo_url'])) {
    // Check if it's a full URL (starts with http/https)
    if (strpos($branding['logo_url'], 'http') === 0) {
        $company_logo = $branding['logo_url'];
    } 
    // Check if it already has the full path
    else if (strpos($branding['logo_url'], '/OMS/') === 0) {
        $company_logo = $branding['logo_url'];
    }
    // Otherwise, it's a relative path from dist folder
    else {
        $company_logo = '/OMS/dist/' . ltrim($branding['logo_url'], '/');
    }
} else {
    // Fallback if no logo in database
    $company_logo = '';
}


// =============================================================
//  ORDER ITEMS QUERY
// =============================================================
$items_query = "SELECT oi.product_id, p.name as product_name, 
                SUM(oi.quantity) as total_quantity
                FROM order_items oi
                JOIN products p ON oi.product_id = p.id
                WHERE oi.order_id = ?
                GROUP BY oi.product_id, p.name
                ORDER BY p.name";

$stmt_items = $conn->prepare($items_query);
$stmt_items->bind_param("i", $order_id);
$stmt_items->execute();
$items_result = $stmt_items->get_result();

$items = [];
while ($item = $items_result->fetch_assoc()) {
    $items[] = $item;
}


// =============================================================
//  TOTALS + CALCULATIONS
// =============================================================
$currency = isset($order['currency']) ? strtolower($order['currency']) : 'lkr';
$currencySymbol = ($currency == 'usd') ? '$' : 'Rs.';

// Calculations based on fetched data
$subtotal = floatval($order['total_amount']) - floatval($order['delivery_fee']) + floatval($order['discount']);
$delivery_fee = floatval($order['delivery_fee']);
$discount = floatval($order['discount']);
$total_payable = floatval($order['total_amount']);

$tracking_number = !empty($order['tracking_number']) ? $order['tracking_number'] : '';
$has_tracking = !empty($tracking_number);

$is_paid = !empty($order['pay_status']) && $order['pay_status'] === 'paid';

function getBarcodeUrl($data) {
    return "https://barcodeapi.org/api/code128/{$data}";
}

function getQRCodeUrl($data) {
    return "https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=" . urlencode($data);
}

$barcode_url = $has_tracking ? getBarcodeUrl($tracking_number) : '';
$qr_url = $has_tracking ? getQRCodeUrl("Tracking: " . $tracking_number . " | Order: " . $order_id) : '';

$totalPayable = 0;

if (isset($order['pay_status']) && $order['pay_status'] !== 'paid') {
    $totalPayable = $total_payable;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Print - <?php echo $order_id; ?></title>
    <link rel="stylesheet" href="../assets/css/print.css" id="main-style-link" />

</head>

<body>
    
    <div class="receipt-container">

        <table class="main-table">

            <tr>
                <td class="header-section" colspan="2">
                    <div class="company-logo">
                        <?php if (!empty($company_logo)): ?>
                            <img src="<?php echo htmlspecialchars($company_logo); ?>" alt="Company Logo">
                        <?php else: ?>
                            <div style="font-weight: bold; font-size: 14px; color: #333;">
                                <?php echo htmlspecialchars($company_name); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="company-name">
                        <?php echo htmlspecialchars($company_name); ?>
                    </div>

                    <div class="company-info">
                        Address: <?php echo htmlspecialchars($company_address); ?>
                    </div>

                    <div class="company-info">
                        Hotline: <?php echo htmlspecialchars($company_hotline); ?>
                        <?php if (!empty($company_email)): ?>
                            | Email: <?php echo htmlspecialchars($company_email); ?>
                        <?php endif; ?>
                    </div>
                </td>

                <td class="order-id-cell">
                    <div style="font-weight:bold;">
                        Order ID: <?php echo str_pad($order_id, 5, '0', STR_PAD_LEFT); ?>
                    </div>

                    <?php if ($is_paid): ?>
                        <div class="payment-badge">
                            ✔ PAID
                        </div>
                    <?php endif; ?>

                    <?php if ($has_tracking): ?>
                        <div class="barcode-section" style="margin-top:2mm;">
                            <img src="<?php echo $barcode_url; ?>" 
                                 alt="Tracking Barcode" 
                                 class="barcode-image"
                                 onerror="this.style.display='none'">
                        </div>
                    <?php else: ?>
                        <div style="color:#dc2626; font-weight:bold; margin-top:2mm;">No Tracking Assigned</div>
                        <div style="border:2px dashed #dc2626; padding:8px; text-align:center; margin-top:2mm;">
                            NO BARCODE<br><span style="font-size:8px;">Tracking not available</span>
                        </div>
                    <?php endif; ?>
                </td>
            </tr>


            <tr>
                <td class="delivery-service-cell" colspan="3" style="padding: 2mm; border: 1px solid #ddd;">
                    <div style="margin-bottom: 2mm;">
                        <strong>Delivery Service:</strong> 
                        <?php echo !empty($order['delivery_service']) ? htmlspecialchars($order['delivery_service']) : 'Standard Delivery'; ?>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 10px;">
                        <div>
                            <strong>Tracking:</strong> 
                            <?php if ($has_tracking): ?>
                                <span style="color: #2563eb;"><?php echo htmlspecialchars(substr($tracking_number, 0, 20)); ?></span>
                            <?php else: ?>
                                <span style="color: #dc2626;">No Tracking</span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <strong>Date:</strong> 
                            <?php echo !empty($order['issue_date']) ? date('Y-m-d', strtotime($order['issue_date'])) : date('Y-m-d'); ?>
                        </div>
                    </div>
                </td>
            </tr>


            <tr>
                <td class="product-header" colspan="3">
                    <strong>Products :</strong>
                    <div style="margin-top:1mm; font-size:11px; line-height:1.2;">
                        <?php 
                        $product_list = [];
                        foreach ($items as $item) {
                            $pname = substr($item['product_name'], 0, 25);
                            if (strlen($item['product_name']) > 25) $pname .= "...";
                            $product_list[] = $item['product_id']." - ".$pname." (".$item['total_quantity'].")";
                        }
                        echo implode(", ", $product_list);
                        ?>
                    </div>
                </td>
            </tr>


            <tr>
                <td class="customer-header" colspan="3" style="border-bottom: none;">
                    <strong>Customer Details</strong>
                </td>
            </tr>

            <tr>
                <td class="customer-info" colspan="3" style="padding: 2mm; font-size: 11px; line-height: 1.4; border-top: none;">
                    <strong>Name:</strong> <?php echo htmlspecialchars($order['display_name']); ?><br>
                    <strong>Phone 1:</strong> <?php echo htmlspecialchars($order['display_mobile']); ?>
                    <?php if (!empty($order['display_mobile_2'])): ?>
                    | <strong>Phone 2:</strong> <?php echo htmlspecialchars($order['display_mobile_2']); ?>
                    <?php endif; ?><br>
                    <strong>Address:</strong> <?php echo htmlspecialchars($order['display_address']); ?><br>
                    <strong>City:</strong> <?php echo !empty($order['city_name']) ? htmlspecialchars($order['city_name']) : 'N/A'; ?>
                    <?php if (!empty($order['final_city_id'])): ?>
                    
                    <?php endif; ?>
                </td>
            </tr>


            <?php if (!$is_paid): ?>
            <tr>
                <td class="totals-header">Summary</td>
                <td class="totals-header" colspan="2">Amount</td>
            </tr>

            <tr>
                <td class="totals-cell" style="font-size: 9px;">
                    Subtotal<br>
                    Delivery<br>
                    Discount
                </td>
                <td class="totals-cell amount" colspan="2" style="font-size: 9px;">
                    <?php echo $currencySymbol . ' ' . number_format($subtotal, 2); ?><br>
                    <?php echo $currencySymbol . ' ' . number_format($delivery_fee, 2); ?><br>
                    <?php echo $currencySymbol . ' ' . number_format($discount, 2); ?>
                </td>
            </tr>

            <tr>
                <td class="total-payable">TOTAL PAYABLE</td>
                <td class="total-payable amount" colspan="2">
                    <?php echo $currencySymbol . ' ' . number_format($totalPayable, 2); ?>
                </td>
            </tr>
            <?php endif; ?>

        </table>

    </div>

    <script>
        window.onload = function() {
            window.print();
        }
    </script>

</body>
</html>

<?php $conn->close(); ?>