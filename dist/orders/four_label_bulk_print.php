<?php
/**
 * Four Label per A4 Page Bulk Print (Approximately 95mm x 140mm Labels)
 * Prints 4 labels per A4 portrait page (2 columns x 2 rows)
 * Each label: 95mm x 140mm with table-based receipt layout
 * Includes customer phone_2 field and payment status
 * Multi-tenant support for company branding per order
 */

// Start session management
session_start();

// Authentication check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /OMS/dist/pages/login.php");
    exit();
}
$logged_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

/**
 * GET FILTER PARAMETERS FROM URL
 */
$date = isset($_GET['date']) ? trim($_GET['date']) : date('Y-m-d');
$time_from = isset($_GET['time_from']) ? trim($_GET['time_from']) : '';
$time_to = isset($_GET['time_to']) ? trim($_GET['time_to']) : '';
$status_filter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : 'all';
$tenant_filter = isset($_GET['tenant_filter']) ? trim($_GET['tenant_filter']) : '';

// Tracking filter parameters - DEFAULT to 'with_tracking'
$tracking_filter = isset($_GET['tracking_filter']) ? trim($_GET['tracking_filter']) : 'with_tracking';
$tracking_number = isset($_GET['tracking_number']) ? trim($_GET['tracking_number']) : '';

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

/**
 * BUILD QUERY TO FETCH ORDERS
 * ADDED: pay_status, pay_by, pay_date fields
 */
$sql = "SELECT o.order_id, o.tenant_id, o.customer_id, o.full_name, o.mobile, o.address_line1, o.address_line2,
               o.status, o.updated_at, o.interface, o.tracking_number, o.total_amount, o.currency,
               o.delivery_fee, o.discount, o.issue_date,
               o.pay_status, o.pay_by, o.pay_date,
               c.name as customer_name, c.phone as customer_phone, 
               c.phone_2 as customer_phone_2,
               c.email as customer_email, c.city_id,
               
               c.address_line1 as customer_address_line1,
               c.address_line2 as customer_address_line2,
               
               cr.courier_name as delivery_service,
               
               ct.city_name,
               
               CONCAT_WS(', ', 
                   NULLIF(c.address_line1, ''), 
                   NULLIF(c.address_line2, ''), 
                   ct.city_name
               ) as customer_address,
               
               COALESCE(NULLIF(o.full_name, ''), c.name, 'Unknown Customer') as display_name,
               
               COALESCE(NULLIF(o.mobile, ''), c.phone, 'No phone') as display_mobile,
               
               COALESCE(
                   NULLIF(CONCAT_WS(', ', NULLIF(o.address_line1, ''), NULLIF(o.address_line2, '')), ''),
                   NULLIF(CONCAT_WS(', ', 
                       NULLIF(c.address_line1, ''), 
                       NULLIF(c.address_line2, ''), 
                       ct.city_name
                   ), ''),
                   'Address not available'
               ) as display_address
               
        FROM order_header o 
        LEFT JOIN customers c ON o.customer_id = c.customer_id
        LEFT JOIN couriers cr ON o.co_id = cr.co_id
        LEFT JOIN city_table ct ON o.city_id = ct.city_id AND ct.is_active = 1
        WHERE o.interface IN ('individual', 'leads')";

// Build search conditions
$searchConditions = [];

if (!empty($date)) {
    $dateTerm = $conn->real_escape_string($date);
    $searchConditions[] = "DATE(o.updated_at) = '$dateTerm'";
}

if (!empty($time_from)) {
    $timeFromTerm = $conn->real_escape_string($time_from);
    $searchConditions[] = "TIME(o.updated_at) >= '$timeFromTerm'";
}

if (!empty($time_to)) {
    $timeToTerm = $conn->real_escape_string($time_to);
    $searchConditions[] = "TIME(o.updated_at) <= '$timeToTerm'";
}

if (!empty($status_filter) && $status_filter !== 'all') {
    $statusTerm = $conn->real_escape_string($status_filter);
    $searchConditions[] = "o.status = '$statusTerm'";
}

// Tenant Filtering Logic
$is_main_admin = isset($_SESSION['is_main_admin']) ? (int)$_SESSION['is_main_admin'] : 0;
$role_id = isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : 0;
$session_tenant_id = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;

if ($is_main_admin === 1) {
    if (!empty($tenant_filter)) {
        $searchConditions[] = "o.tenant_id = " . (int)$tenant_filter;
    }
} else {
    $searchConditions[] = "o.tenant_id = $session_tenant_id";
}

// Tracking filter conditions
if (!empty($tracking_filter) && $tracking_filter !== 'all') {
    switch ($tracking_filter) {
        case 'with_tracking':
            $searchConditions[] = "o.tracking_number IS NOT NULL AND o.tracking_number != '' AND TRIM(o.tracking_number) != ''";
            break;
        case 'without_tracking':
            $searchConditions[] = "(o.tracking_number IS NULL OR o.tracking_number = '' OR TRIM(o.tracking_number) = '')";
            break;
        case 'specific_tracking':
            if (!empty($tracking_number)) {
                $trackingTerm = $conn->real_escape_string($tracking_number);
                $searchConditions[] = "o.tracking_number LIKE '%$trackingTerm%'";
            }
            break;
    }
}

// Apply search conditions
if (!empty($searchConditions)) {
    $sql .= " AND " . implode(' AND ', $searchConditions);
}

$sql .= " ORDER BY o.updated_at DESC, o.order_id DESC LIMIT $limit OFFSET $offset";

// Execute query
$result = $conn->query($sql);
if (!$result) {
    die("Query failed: " . $conn->error);
}

/**
 * FETCH ORDER ITEMS
 */
$orders = [];
$order_ids = [];

while ($order = $result->fetch_assoc()) {
    $orders[] = $order;
    $order_ids[] = $order['order_id'];
}

// Get all items for all orders
$items_by_order = [];
if (!empty($order_ids)) {
    $order_ids_str = implode(',', array_map('intval', $order_ids));
    $items_query = "SELECT oi.order_id, oi.product_id, p.name as product_name, 
                    SUM(oi.quantity) as total_quantity
                    FROM order_items oi
                    JOIN products p ON oi.product_id = p.id
                    WHERE oi.order_id IN ($order_ids_str)
                    GROUP BY oi.order_id, oi.product_id, p.name
                    ORDER BY oi.order_id, p.name";
    
    $items_result = $conn->query($items_query);
    if ($items_result) {
        while ($item = $items_result->fetch_assoc()) {
            $items_by_order[$item['order_id']][] = $item;
        }
    }
}

// Fetch all tenants for multi-tenant support
$tenants = [];
$tenant_result = $conn->query("SELECT tenant_id, company_name, address AS tenant_address, phone, email AS tenant_email, logo_url FROM tenants WHERE status = 'active'");
if ($tenant_result) {
    while ($t = $tenant_result->fetch_assoc()) {
        $tenants[$t['tenant_id']] = $t;
    }
}

function getTenantData($tId, $tenants_list) {
    if (!empty($tId) && isset($tenants_list[$tId])) {
        return $tenants_list[$tId];
    }
    // Tenant not found (inactive/missing): return neutral placeholder
    // instead of falling back to the first active tenant's branding
    return [
        'company_name' => 'Company Name', 'tenant_address' => 'Address not set', 'tenant_email' => '', 'phone' => '', 'logo_url' => ''
    ];
}

/**
 * HELPER FUNCTIONS
 */
function getCurrencySymbol($currency) {
    return (strtolower($currency) == 'usd') ? '$' : 'Rs.';
}

function getBarcodeUrl($data) {
    return "../include/barcode.php?code=" . urlencode($data);
}

function getQRCodeUrl($data) {
    return "https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=" . urlencode($data);
}

function calculateSubtotal($total, $delivery, $discount) {
    return floatval($total) - floatval($delivery) + floatval($discount);
}

function hasTracking($tracking_number) {
    return !empty($tracking_number) && trim($tracking_number) !== '';
}

function getTrackingFilterText($tracking_filter, $tracking_number = '') {
    switch ($tracking_filter) {
        case 'with_tracking':
            return 'Orders WITH tracking numbers';
        case 'without_tracking':
            return 'Orders WITHOUT tracking numbers';
        case 'specific_tracking':
            return !empty($tracking_number) ? "Tracking contains: '{$tracking_number}'" : 'Specific tracking (no number provided)';
        default:
            return 'All orders (no tracking filter)';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Four Labels per A4 Page Bulk Print (<?php echo count($orders); ?> orders) | <?= htmlspecialchars($_SESSION['company_name'] ?? '') ?></title>
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #000;
            background: #fff;
            padding: 0;
            margin: 0;
        }

        .receipt-container {
            width: 95mm;
            height: 140mm;
            max-width: 95mm;
            background: white;
            position: relative;
        }

        .main-table {
            width: 100%;
            height: 100%;
            border-collapse: collapse;
            border: 2px solid #000;
            font-size: 11px;
        }

        .main-table td {
            border: 1px solid #000;
            padding: 2mm;
            vertical-align: top;
            font-size: 12px;
        }

        .header-section {
            height: 40mm;
            padding: 2mm;
            text-align: center;
            vertical-align: top;
            width: 48%;
        }

        .company-name {
            font-size: 12px;
            margin-bottom: 1mm;
            font-weight: bold;
        }

        .company-info {
            font-size: 10px;
            margin-bottom: 1mm;
        }

        .company-logo img {
            max-height: 55px;
            max-width: 150px;
        }

        .order-id-cell {
            font-weight: bold;
            font-size: 10px;
            text-align: center;
            background-color: #f0f0f0;
            width: 52%;
            vertical-align: top;
            padding: 2mm;
            border: 1px solid #ddd;
        }

        .delivery-service-cell {
            font-weight: bold;
            background-color: #f8f8f8;
            font-size: 8px;
            padding: 2mm;
        }

        .product-header {
            font-weight: bold;
            font-size: 10px;
            padding: 1mm;
        }

        .customer-header {
            font-weight: bold;
            font-size: 10px;
            padding: 1mm;
        }

        .customer-info {
            font-size: 8px;
            line-height: 1.3;
            padding: 2mm;
            width: 100%;
        }

        .totals-header {
            font-weight: bold;
            font-size: 10px;
            padding: 1mm;
        }

        .totals-cell {
            font-size: 8px;
            line-height: 1.3;
            padding: 2mm;
            width: 10%;
        }

        .total-payable {
            font-weight: bold;
            font-size: 9px;
            text-align: center;
            background-color: #f0f0f0;
            padding: 2mm;
        }

        .amount {
            text-align: right;
            font-weight: bold;
        }

        .barcode-section {
            text-align: center;
            margin: 10px 0;
            margin-bottom: 5px;
        }

        .barcode-image {
            max-width: 185px;
            max-height: 20mm;
            height: auto;
            width: auto;
            margin: 5px auto;
            display: block;
        }

        .barcode-text {
            font-size: 7px;
            font-family: monospace;
            margin-top: 2px;
            color: #666;
            text-align: center;
        }

        .no-tracking-barcode {
            text-align: center;
        }

        .payment-badge {
            display: inline-block;
            color: #155724;
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 8px;
            font-weight: bold;
            margin-top: 2mm;
        }

        .labels-container {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-start;
            gap: 3mm;
            padding: 3mm;
        }

        .label-wrapper {
            width: 95mm;
            height: 140mm;
            break-inside: avoid;
            page-break-inside: avoid;
            margin: 0;
            display: inline-block;
            vertical-align: top;
        }

        .no-orders {
            text-align: center;
            padding: 50px;
            font-size: 16px;
            color: #666;
        }

        .no-orders h3 {
            margin-bottom: 10px;
        }

        .no-orders p {
            margin-bottom: 8px;
        }

        @media print {
            body {
                margin: 0;
                padding: 0;
            }
            
            .labels-container {
                display: grid;
                grid-template-columns: 95mm 95mm;
                grid-template-rows: 140mm 140mm;
                grid-auto-rows: 140mm;
                gap: 2mm;
                padding: 0;
                width: 100%;
                height: auto;
                justify-content: center;
            }
            
            .label-wrapper {
                display: inline-block;
                width: 95mm;
                height: 140mm;
                margin: 0;
            }
            
            .receipt-container {
                width: 100%;
                height: 100%;
                max-width: none;
                box-sizing: border-box;
            }
            
            .main-table {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            
            .barcode-section {
                margin: 5px 0;
            }
            
            .barcode-image {
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
            
            @page {
                size: A4 portrait;
                margin: 7.5mm 8mm;
            }
        }
    </style>
</head>
<body>
    <!-- Labels Container -->
    <div class="labels-container">
        <?php if (empty($orders)): ?>
            <div class="no-orders">
                <h3>No Trackable Orders Found</h3>
                <p>No trackable orders found matching the selected filters.</p>
                <p><em>Four Label Bulk Print shows only orders with tracking numbers assigned.</em></p>
                <?php if (isset($_GET['tracking_filter']) && $_GET['tracking_filter'] !== 'with_tracking'): ?>
                    <p><em>Current filter: <?php echo htmlspecialchars(getTrackingFilterText($tracking_filter, $tracking_number)); ?></em></p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            
          <?php foreach ($orders as $index => $order): ?>
                <?php
                // Resolve tenant/company data
                $tId = isset($order['tenant_id']) ? $order['tenant_id'] : 0;
                $bData = getTenantData($tId, $tenants);
                $company = [                    'name'     => $bData['company_name'] ?? 'Company Name',
                    'address'  => $bData['tenant_address'] ?? 'Address not set',
                    'email'    => $bData['tenant_email'] ?? '',
                    'phone'    => $bData['phone'] ?? '','logo_url' => $bData['logo_url'] ?? ''
                ];
                
                // Prepare order data 
                $order_id = $order['order_id'];
                $currency_symbol = getCurrencySymbol($order['currency'] ?? 'lkr');
                
                // Payment status check
                $is_paid = !empty($order['pay_status']) && $order['pay_status'] === 'paid';
                
                // Tracking number handling
                $tracking_number_raw = !empty($order['tracking_number']) ? trim($order['tracking_number']) : '';
                $has_tracking = hasTracking($tracking_number_raw);
                
                if ($has_tracking) {
                    $barcode_data = $tracking_number_raw;
                    $barcode_url = getBarcodeUrl($barcode_data);
                    $qr_url = getQRCodeUrl("Tracking: " . $tracking_number_raw . " | Order: " . $order_id);
                    $tracking_display = $tracking_number_raw;
                } else {
                    $barcode_data = str_pad($order_id, 10, '0', STR_PAD_LEFT);
                    $barcode_url = getBarcodeUrl($barcode_data);
                    $qr_url = getQRCodeUrl("Order: " . $order_id . " | No Tracking");
                    $tracking_display = 'No Tracking';
                }
                
                // Calculate totals
                $total_amount = floatval($order['total_amount']);
                $delivery_fee = floatval($order['delivery_fee']);
                $discount = floatval($order['discount']);
                $subtotal = calculateSubtotal($total_amount, $delivery_fee, $discount);

                // Total payable logic
                $total_payable = $is_paid ? 0 : $total_amount;
                
                // Get items for this order
                $order_items = isset($items_by_order[$order_id]) ? $items_by_order[$order_id] : [];
                
                // Get phone numbers - ADDED phone_2 support
                $phone_1 = htmlspecialchars($order['display_mobile']);
                $phone_2 = !empty($order['customer_phone_2']) ? htmlspecialchars($order['customer_phone_2']) : '';
                ?>
                
                <div class="label-wrapper">
                    <div class="receipt-container">
                        <!-- Main Table Structure -->
                        <table class="main-table">
                            <!-- Header Section -->
                            <tr>
                              <td class="header-section" colspan="2">
                                <div class="company-logo">
                                    <?php if (!empty($company['logo_url'])): ?>
                                        <!-- Display logo from database -->
                                        <img src="<?php echo htmlspecialchars($company['logo_url']); ?>" 
                                            alt="Company Logo" 
                                            onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                        <!-- Fallback text if image fails to load -->
                                        <div style="display:none; font-weight:bold; font-size:14px; color:#333;">
                                            <?php echo htmlspecialchars($company['name']); ?>
                                        </div>
                                    <?php else: ?>
                                        <!-- Fallback: Show company name if no logo URL -->
                                        <div style="font-weight:bold; font-size:14px; color:#333;">
                                            <?php echo htmlspecialchars($company['name']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="company-name"><?php echo htmlspecialchars($company['name']); ?></div>
                                <div class="company-info"><?php echo htmlspecialchars($company['address']); ?></div>
                                <div class="company-info"><?php echo htmlspecialchars($company['phone']); ?> 
                                <?php if (!empty($company['email'])) :?> | <?php echo htmlspecialchars($company['email']); ?><?php endif; ?></div>
                            </td>
                                <td class="order-id-cell">
                                    <div style="font-weight: bold; ">
                                        Order ID: <?php echo str_pad($order_id, 5, '0', STR_PAD_LEFT); ?>
                                    </div>
                                    
                                    <?php if ($is_paid): ?>
                                        <div class="payment-badge">
                                            ✔ PAID
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="barcode-section" style="margin-top: 2mm;">
                                        <?php if ($has_tracking): ?>
                                            <img src="<?php echo $barcode_url; ?>" alt="Tracking Barcode" class="barcode-image" onerror="this.style.display='none'">
                                            <div style="font-size: 16px; margin-top: 2px; color: #000; font-weight: bold; font-family: sans-serif; text-align:center;">
                                                <?php echo htmlspecialchars($order['tracking_number']); ?>
                                            </div>

                                        <?php else: ?>
                                            <div class="no-tracking-barcode">
                                                <div style="border: 1px dashed #dc2626; padding: 4px; text-align: center; font-size: 8px; color: #dc2626; background: #fef2f2;">
                                                    NO BARCODE<br>
                                                    <span style="font-size: 6px;">No tracking assigned</span>
                                                </div>
                                                <div class="barcode-text" style="color: #dc2626;">No Tracking</div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>

                            <!-- Delivery Service Row - Updated UI -->
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
                                                <span><?php echo htmlspecialchars(substr($tracking_display, 0, 20)); ?></span>
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
                            <!-- Products Section -->
                            <tr>
                                <td class="product-header" colspan="3">
                                    <strong>Products :</strong>
                                    <div style="margin-top: 1mm; font-size: 11px; line-height: 1.2;">
                                        <?php if (!empty($order_items)): ?>
                                            <?php 
                                            $product_list = [];
                                            foreach ($order_items as $item) {
                                                $product_name = htmlspecialchars(substr($item['product_name'], 0, 25));
                                                if (strlen($item['product_name']) > 25) $product_name .= '...';
                                                $product_list[] = $product_name . " (" . $item['total_quantity'] . ")";
                                            }
                                            echo implode(', ', $product_list);
                                            ?>
                                        <?php else: ?>
                                            No items found
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>

                        <!-- Customer Details Section (No border line between header and info) -->
                       <tr>
                        <td class="customer-header" colspan="3" style="border-bottom: none;">
                            <strong>Customer Details</strong>
                        </td>
                    </tr>

                    <tr>
                        <td class="customer-info" colspan="3" style="padding: 2mm; font-size: 11px; line-height: 1.4; border-top: none;"> 
                            <strong>Name:</strong> <?php echo htmlspecialchars($order['display_name']); ?><br>
                            <strong>Phone 1:</strong> <?php echo $phone_1; ?>
                            <?php if ($phone_2): ?>
                            | <strong>Phone 2:</strong> <?php echo $phone_2; ?>
                            <?php endif; ?><br>
                            <strong>Address:</strong> <?php echo htmlspecialchars($order['display_address']); ?><br>
                            <strong>City:</strong> <?php echo !empty($order['city_name']) ? htmlspecialchars($order['city_name']) : 'N/A'; ?>
                        </td>
                    </tr>

                        <?php if (!$is_paid): ?>
                        <!-- Summary Section - Only show when NOT paid -->
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
                                <?php echo $currency_symbol . ' ' . number_format($subtotal, 2); ?><br>
                                <?php echo $currency_symbol . ' ' . number_format($delivery_fee, 2); ?><br>
                                <?php echo $currency_symbol . ' ' . number_format($discount, 2); ?>
                            </td>
                        </tr>

                        <!-- Total Payable -->
                        <tr>
                            <td class="total-payable">TOTAL PAYABLE</td>
                            <td class="total-payable amount" colspan="2">
                                <?php echo $currency_symbol . ' ' . number_format($total_payable, 2); ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        </table>
                    </div>
                </div>
                
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script>
        // Auto print when page loads (with small delay for images to load)
        window.addEventListener('load', function() {
            setTimeout(function() {
                window.print();
            }, 1000);
        });

        // Handle print completion
        window.addEventListener('afterprint', function() {
            console.log('Print completed');
        });

        // Log loaded orders for debugging
        console.log('Four Label bulk print loaded: <?php echo count($orders); ?> orders');
        console.log('FOUR LABELS PER A4 PAGE - 95mm x 140mm format');
        console.log('Customer phone_2 included in labels');
    </script>
</body>
</html>

<?php
$conn->close();
?>