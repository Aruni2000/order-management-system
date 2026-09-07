<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("Order ID is required");
}

$order_id = $_GET['id'];

$order_query = "
    SELECT 
        o.*,
        COALESCE(NULLIF(o.full_name, ''), c.name, 'Unknown Customer') as customer_name,
        COALESCE(
            NULLIF(CONCAT_WS(', ', NULLIF(o.address_line1, ''), NULLIF(o.address_line2, '')), ''),
            CONCAT_WS(', ', c.address_line1, c.address_line2)
        ) AS customer_address,
        c.email AS customer_email,
        COALESCE(NULLIF(o.mobile, ''), c.phone) AS customer_phone,
        COALESCE(NULLIF(o.mobile_2, ''), c.phone_2) AS customer_phone_2,
        ct.city_name AS customer_city,
        u.name AS user_name,
        cr.courier_name AS courier_name
    FROM order_header o
    LEFT JOIN customers c ON o.customer_id = c.customer_id
    LEFT JOIN city_table ct ON COALESCE(o.city_id, c.city_id) = ct.city_id AND ct.is_active = 1
    LEFT JOIN users u ON o.user_id = u.id
    LEFT JOIN couriers cr ON o.courier_id = cr.courier_id
    WHERE o.order_id = ?";

$stmt = $conn->prepare($order_query);
if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}
$stmt->bind_param("i", $order_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Order not found");
}

$order = $result->fetch_assoc();

$currency = isset($order['currency']) ? strtolower($order['currency']) : 'lkr';
$currencySymbol = ($currency == 'usd') ? '$' : 'Rs.';

$itemSql = "SELECT oi.*, p.name as product_name,
            COALESCE(oi.description, p.description) as product_description,
            (oi.total_amount + COALESCE(oi.discount, 0)) as original_price,
            oi.total_amount as item_price,
            COALESCE(oi.discount, 0) as item_discount
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id = ?";

$stmt = $conn->prepare($itemSql);
if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}
$stmt->bind_param("i", $order_id);
$stmt->execute();
$itemsResult = $stmt->get_result();
$items = [];
while ($item = $itemsResult->fetch_assoc()) {
    $items[] = $item;
}

$delivery_fee = floatval($order['delivery_fee'] ?? 0);
$discount = floatval($order['discount'] ?? 0);
$total_amount = floatval($order['total_amount'] ?? 0);

$total_item_discounts = 0;
$subtotal_before_discounts = 0;
foreach ($items as $item) {
    $total_item_discounts += floatval($item['item_discount']);
    $subtotal_before_discounts += floatval($item['original_price']);
}

$has_any_discount = $total_item_discounts > 0 || $discount > 0;

$paymentHistory = [];
$payStmt = $conn->prepare("SELECT p.*, u.name as paid_by_name FROM payments p LEFT JOIN users u ON p.pay_by = u.id WHERE p.order_id = ? ORDER BY p.payment_date ASC");
$payStmt->bind_param("i", $order_id);
$payStmt->execute();
$payResult = $payStmt->get_result();
while ($payRow = $payResult->fetch_assoc()) {
    $paymentHistory[] = $payRow;
}

$order_tenant_id = isset($order['tenant_id']) ? (int)$order['tenant_id'] : 0;

$tenant_sql = "SELECT company_name, address, phone, email, logo_url FROM tenants WHERE tenant_id = ? AND status = 'active' LIMIT 1";
$stmt_tenant = $conn->prepare($tenant_sql);
$stmt_tenant->bind_param("i", $order_tenant_id);
$stmt_tenant->execute();
$tenant_result = $stmt_tenant->get_result();
$tenant_data = ($tenant_result && $tenant_result->num_rows > 0) ? $tenant_result->fetch_assoc() : [];

$company = [
    'company_name' => $tenant_data['company_name'] ?? 'Company Name',
    'address' => str_replace(['\\\\r\\\\n', '\\r\\n', '\\n'], "\n", $tenant_data['address'] ?? ''),
    'email' => $tenant_data['email'] ?? '',
    'hotline' => $tenant_data['phone'] ?? ''
];

if (!empty($tenant_data['logo_url'])) {
    if (strpos($tenant_data['logo_url'], 'http') === 0) {
        $logo_url = $tenant_data['logo_url'];
    } else if (strpos($tenant_data['logo_url'], '/OMS/') === 0) {
        $logo_url = $tenant_data['logo_url'];
    } else {
        $logo_url = '/OMS/dist/' . ltrim($tenant_data['logo_url'], '/');
    }
} else {
    $logo_url = '';
}

function getPaymentStatusBadge($status) {
    $status = strtolower($status ?? 'unpaid');
    switch ($status) {
        case 'paid': return "bg-success";
        case 'partial': return "bg-warning";
        case 'unpaid':
        default: return "bg-danger";
    }
}

function convertNumberToWords($number) {
    $hyphen      = ' ';
    $conjunction = ' and ';
    $separator   = ', ';
    $negative    = 'negative ';
    $decimal     = ' point ';
    $dictionary  = array(
        0 => 'Zero', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four',
        5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
        10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen',
        15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen', 19 => 'Nineteen',
        20 => 'Twenty', 30 => 'Thirty', 40 => 'Forty', 50 => 'Fifty',
        60 => 'Sixty', 70 => 'Seventy', 80 => 'Eighty', 90 => 'Ninety',
        100 => 'Hundred', 1000 => 'Thousand', 1000000 => 'Million',
        1000000000 => 'Billion', 1000000000000 => 'Trillion'
    );

    if (!is_numeric($number)) return false;
    if ($number < 0) return $negative . convertNumberToWords(abs($number));

    $string = $fraction = null;
    if (strpos($number, '.') !== false) {
        list($number, $fraction) = explode('.', $number);
    }

    switch (true) {
        case $number < 21:
            $string = $dictionary[$number];
            break;
        case $number < 100:
            $tens = ((int)($number / 10)) * 10;
            $units = $number % 10;
            $string = $dictionary[$tens];
            if ($units) $string .= $hyphen . $dictionary[$units];
            break;
        case $number < 1000:
            $hundreds = $number / 100;
            $remainder = $number % 100;
            $string = $dictionary[$hundreds] . ' ' . $dictionary[100];
            if ($remainder) $string .= $conjunction . convertNumberToWords($remainder);
            break;
        default:
            $baseUnit = pow(1000, floor(log($number, 1000)));
            $numBaseUnits = (int)($number / $baseUnit);
            $remainder = $number % $baseUnit;
            $string = convertNumberToWords($numBaseUnits) . ' ' . $dictionary[$baseUnit];
            if ($remainder) {
                $string .= $remainder < 100 ? $conjunction : $separator;
                $string .= convertNumberToWords($remainder);
            }
            break;
    }

    if (null !== $fraction && is_numeric($fraction) && (int)$fraction > 0) {
        $string .= $decimal;
        $words = array();
        foreach (str_split((string)$fraction) as $number) {
            $words[] = $dictionary[$number];
        }
        $string .= implode(' ', $words);
    }

    return $string;
}

$grand_total_words = convertNumberToWords(floor($total_amount));
if ($grand_total_words) {
    $grand_total_words = ucwords($grand_total_words) . ' Only';
} else {
    $grand_total_words = '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Order Receipt - #<?php echo htmlspecialchars($order_id); ?> | <?= htmlspecialchars($_SESSION['company_name'] ?? '') ?></title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f4f6f9;
            color: #333;
        }
        
        .invoice-container {
            max-width: 800px;
            margin: 20px auto;
            background-color: #fff;
            padding: 40px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        /* HEADER */
        .header-section {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0px;
        }
        
        .company-details {
            font-size: 14px;
            line-height: 1.5;
        }
        .company-details h1 {
            font-size: 24px;
            margin: 0 0 0px 0;
            font-weight: normal;
            color: #333;
        }
        
        .company-logo {
            margin-bottom: 0px;
        }
        .company-logo img {
            max-height: 80px;
            max-width: 200px;
            object-fit: contain;
        }
        
        .receipt-right-section {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            text-align: right;
        }
        .receipt-title {
            font-size: 28px;
            margin: 10px 10px 0 0;
            color: #555;
            letter-spacing: 2px;
            font-weight: bold;
        }
        .receipt-meta {
            font-size: 13px;
            line-height: 1.8;
            text-align: left;  
            margin: 50px 36px 0 0;
        }
        .badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 4px;
            color: #fff;
            font-size: 11px;
            font-weight: bold;
            letter-spacing: 1px;
            min-width: 50px;
            text-align: center;
        }
        .bg-success { background-color: #28a745; }
        .bg-warning { background-color: #ffc107; color: #333; }
        .bg-danger { background-color: #dc3545; }
        
        /* META SECTION */
        .meta-section {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
        }
        .meta-col {
            width: 48%;
        }
        .meta-title {
            color: #003399; /* Blue color from PDF */
            font-size: 13px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        .meta-content {
            font-size: 13px;
            line-height: 1.5;
        }
        .receipt-info {
            font-size: 13px;
            line-height: 1.5;
            margin-top: 20px;
        }
        .receipt-info strong {
            display: inline-block;
            width: 90px;
        }

        /* TABLE SECTION */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .items-table th {
            border: 1px solid #ccc;
            padding: 10px;
            font-size: 12px;
            color: #333;
            background-color: #f9f9f9;
        }
        .items-table th:first-child { text-align: left; }
        .items-table th:nth-child(2),
        .items-table th:nth-child(3),
        .items-table th:nth-child(4) { text-align: right; }
        
        .items-table td {
            border: 1px solid #ccc;
            padding: 8px 10px;
            font-size: 13px;
        }
        .items-table td:nth-child(2),
        .items-table td:nth-child(3),
        .items-table td:nth-child(4) { text-align: right; }
        
        /* SUMMARY SECTION */
        .summary-section {
            margin-bottom: 40px;
        }
        .totals-table {
            width: 100%;
            border-collapse: collapse;
        }
        .totals-table td {
            padding: 8px 10px;
            font-size: 12px;
        }
        .totals-table .label {
            text-align: right;
            font-weight: bold;
            text-transform: uppercase;
            color: #333;
        }
        .totals-table .value {
            text-align: right;
            width: 120px;
            border-bottom: 1px solid #ccc;
        }
        
        .totals-table tr.grand-total .label {
            font-size: 18px;
            font-weight: bold;
        }
        .totals-table tr.grand-total .value {
            font-size: 16px;
            font-weight: bold;
            border-bottom: 2px solid #333;
        }
        
        /* NOTES SECTION */
        .thankyou-section {
            font-size: 13px;
            line-height: 1.5;
            color: #059b20ff;
            font-weight: bold;
            margin-top: 20px;
        }
        .notes-title {
            font-weight: bold;
            margin-bottom: 5px;
        }
        .notes-content {
            color: #555;
            white-space: pre-line;
        }
        
        /* PRINT STYLES */
        .control-buttons {
            text-align: center;
            margin: 20px 0;
        }
        .btn-print {
            padding: 10px 20px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        .btn-print:hover {
            background-color: #0056b3;
        }
        @media print {
            body { background-color: #fff; }
            .invoice-container { margin: 0; padding: 10px; box-shadow: none; max-width: 100%; }
            .control-buttons { display: none; }
        }
    </style>
</head>
<body>
    <div class="control-buttons">
        <button class="btn-print" onclick="window.print()">Print Receipt</button>
    </div>
    
    <div class="invoice-container">
        
        <div class="header-section">
            <div class="company-details">
                <?php if (!empty($logo_url)): ?>
                <div class="company-logo">
                    <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="<?php echo htmlspecialchars($company['company_name']); ?> Logo">
                </div>
                <?php endif; ?>
                <h1><?php echo htmlspecialchars($company['company_name'] ?: '<Company Name>'); ?></h1>
                <div><?php echo !empty($company['address']) ? nl2br(htmlspecialchars($company['address'])) : '&lt;123 Street Address, City, State, Zip/Post&gt;'; ?></div>
                <div><?php echo htmlspecialchars($company['email'] ?: '&lt;Website, Email Address&gt;'); ?> | <?php echo htmlspecialchars($company['hotline'] ?: '&lt;Phone Number&gt;'); ?></div>
            </div>
            <div class="receipt-right-section">
                <h2 class="receipt-title">RECEIPT</h2>
                <div class="receipt-meta">
                    <strong>Order No:</strong> #<?php echo htmlspecialchars(str_pad($order_id, 5, '0', STR_PAD_LEFT)); ?><br>
                    <strong>Date:</strong> <?php echo date('d/m/y', strtotime($order['issue_date'])); ?><br>
                </div>
            </div>
        </div>
        <hr style="border: 0; border-top: 1px solid #000;">
        <div class="meta-section">
            <div class="meta-col" style="width: 100%;">
                <div class="meta-title">BILL TO</div>
                <div class="meta-content">
                    <?php echo htmlspecialchars($order['customer_name']); ?><br>
                    <?php echo !empty($order['customer_address']) ? nl2br(htmlspecialchars($order['customer_address'])) . '<br>' : ''; ?>
                    <?php echo !empty($order['customer_city']) ? htmlspecialchars($order['customer_city']) . '<br>' : ''; ?>
                    <?php 
                    $phones = array_filter([$order['customer_phone'], $order['customer_phone_2']]);
                    if (!empty($phones)) {
                        echo htmlspecialchars(implode(', ', $phones)) . '<br>';
                    }
                    ?>
                    <?php echo !empty($order['customer_email']) ? htmlspecialchars($order['customer_email']) : ''; ?>
                </div>
            </div>
        </div>
        
        <table class="items-table">
            <thead>
                <tr>
                    <th>PRODUCT</th>
                    <th>QTY</th>
                    <th>UNIT PRICE</th>
                    <th>TOTAL</th>
                </tr>
            </thead>
            <tbody>
                <?php
                foreach ($items as $item):
                    $qty = intval($item['quantity'] ?? 1);
                    $unit_price = floatval($item['unit_price'] ?? 0);
                    $item_total = floatval($item['item_price'] ?? $item['total_amount'] ?? 0);
                ?>
                <tr>
                    <td>
                        <?php echo htmlspecialchars($item['product_name']); ?>
                    </td>
                    <td><?php echo $qty; ?></td>
                    <td><?php echo number_format($unit_price, 2); ?></td>
                    <td><?php echo number_format($item_total, 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="summary-section">
            <table class="totals-table" style="width: 100%;">
                <tr>
                    <td class="label">SUBTOTAL</td>
                    <td class="value"><?php echo number_format($subtotal_before_discounts, 2); ?></td>
                </tr>
                <?php $total_discount = $total_item_discounts + $discount; ?>
                <?php if ($total_discount > 0): ?>
                <tr>
                    <td class="label">DISCOUNT</td>
                    <td class="value"><?php echo number_format($total_discount, 2); ?></td>
                </tr>
                <?php endif; ?>

                <tr>
                    <td class="label">DELIVERY CHARGE</td>
                    <td class="value"><?php echo number_format($delivery_fee, 2); ?></td>
                </tr>
                <tr class="grand-total">
                    <td class="label">TOTAL &nbsp; <?php echo $currencySymbol; ?></td>
                    <td class="value"><?php echo number_format($total_amount, 2); ?></td>
                </tr>
            </table>
        </div>
        
        <div class="thankyou-section">
            Thank you for your business! We appreciate your order and look forward to serving you again.
        </div>
        
    </div>
</body>
</html>
<?php $conn->close(); ?>
