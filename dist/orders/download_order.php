<?php
// Start session at the very beginning
session_start();

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // Clear any existing output buffers
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /OMS/dist/pages/login.php");
    exit();
}
// Include the database connection file
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Check if order ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("Order ID is required");
}

$order_id = $_GET['id'];
$show_payment_details = isset($_GET['show_payment']) && $_GET['show_payment'] === 'true';

// Access Control Variables
$is_main_admin = isset($_SESSION['is_main_admin']) ? (int)$_SESSION['is_main_admin'] : 0;
$role_id = isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : 0;
$session_tenant_id = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
$logged_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

// ==========================================
// ✅ FIXED QUERY: Get data from order_header FIRST, fallback to customers table
// Priority: order_header fields > customers table fields
// ==========================================
$order_query = "SELECT 
                oh.*, 
                oh.pay_status AS order_pay_status,
                
                -- Customer data (always from order_header)
                oh.customer_id,
                oh.full_name AS customer_name,
                oh.mobile AS customer_phone,
                oh.mobile_2 AS customer_phone_2,
                oh.email AS customer_email,
                oh.address_line1 AS customer_address_line1,
                oh.address_line2 AS customer_address_line2,
                oh.city_id,
                
                -- City name lookup
                city.city_name AS customer_city,
                
                -- Payment information
                p.payment_id, 
                p.amount_paid, 
                p.payment_method, 
                p.payment_date, 
                p.pay_by,
                r.name AS paid_by_name, 
                u.name AS user_name,
                
                -- Order details
                oh.delivery_fee, 
                oh.pay_by AS order_pay_by, 
                oh.pay_date AS order_pay_date, 
                oh.slip AS payment_slip
                
            FROM order_header oh
            LEFT JOIN city_table city ON oh.city_id = city.city_id
            LEFT JOIN payments p ON oh.order_id = p.order_id
            LEFT JOIN users r ON p.pay_by = r.id
            LEFT JOIN users u ON oh.user_id = u.id
            WHERE oh.order_id = ?";

// Add Access Control Clauses
$params = [$order_id];
$types = "i";

if ($is_main_admin === 1 && $role_id === 1) {
    // Main Admin: No extra restrictions
} elseif ($role_id === 1 && $is_main_admin === 0) {
    // Tenant Admin: Restrict to tenant
    $order_query .= " AND oh.tenant_id = ?";
    $params[] = $session_tenant_id;
    $types .= "i";
} else {
    // Regular User: Restrict to tenant AND assigned user
    $order_query .= " AND oh.tenant_id = ? AND oh.user_id = ?";
    $params[] = $session_tenant_id;
    $params[] = $logged_user_id;
    $types .= "ii";
}

$stmt = $conn->prepare($order_query);

// Add error checking for prepare statement
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

// ==========================================
// ✅ FORMAT CUSTOMER ADDRESS
// Combine address lines for display
// ==========================================
$customer_address = '';
if (!empty($order['customer_address_line1'])) {
    $customer_address .= $order['customer_address_line1'];
}
if (!empty($order['customer_address_line2'])) {
    $customer_address .= !empty($customer_address) ? ', ' . $order['customer_address_line2'] : $order['customer_address_line2'];
}

// Get currency from order
$currency = isset($order['currency']) ? strtolower($order['currency']) : 'lkr';
$currencySymbol = ($currency == 'usd') ? '$' : 'Rs.';

// Ensure delivery fee is properly set
$delivery_fee = isset($order['delivery_fee']) && !is_null($order['delivery_fee']) ? floatval($order['delivery_fee']) : 0.00;

// Modified item query to include item-level discounts and original prices
$itemSql = "SELECT ii.*, ii.pay_status, p.name as product_name, 
            COALESCE(ii.description, p.description) as product_description,
            (ii.total_amount + ii.discount) as original_price, 
            ii.total_amount as item_price,
            COALESCE(ii.discount, 0) as item_discount
            FROM order_items ii
            JOIN products p ON ii.product_id = p.id
            WHERE ii.order_id = ?";

$stmt = $conn->prepare($itemSql);

// Add error checking for prepare statement
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

// Determine overall order payment status
if (isset($order['order_pay_status']) && !empty($order['order_pay_status'])) {
    $orderPayStatus = strtolower($order['order_pay_status']);
} else {
    $allItemsPaid = true;
    $anyItemPaid = false;

    foreach ($items as $item) {
        if (strtolower($item['pay_status']) == 'paid') {
            $anyItemPaid = true;
        } else {
            $allItemsPaid = false;
        }
    }

    if ($allItemsPaid && count($items) > 0) {
        $orderPayStatus = 'paid';
    } elseif ($anyItemPaid) {
        $orderPayStatus = 'partial';
    } else {
        $orderPayStatus = 'unpaid';
    }
}

// Fetch company info from tenants table
$order_tenant_id = isset($order['tenant_id']) ? (int)$order['tenant_id'] : 0;

// Get tenant info (company_name, address, phone/email, logo_url)
$tenant_query = "SELECT company_name, address, phone, email, logo_url FROM tenants WHERE tenant_id = ? AND status = 'active' LIMIT 1";
$stmt = $conn->prepare($tenant_query);
$stmt->bind_param("i", $order_tenant_id);
$stmt->execute();
$tenant_result = $stmt->get_result();
$company = ($tenant_result && $tenant_result->num_rows > 0) ? $tenant_result->fetch_assoc() : [];

// Map phone to hotline for display consistency
$company['hotline'] = $company['phone'] ?? '';

// Clean up the address - remove extra backslashes and format properly
if (!empty($company['address'])) {
    $company['address'] = str_replace(['\\\\r\\\\n', '\\r\\n', '\\n'], "\n", $company['address']);
}

// ==========================================
// ✅ ALWAYS USE LOGO FROM DATABASE
// ==========================================
if (!empty($company['logo_url'])) {
    // Check if it's a full URL (starts with http/https)
    if (strpos($company['logo_url'], 'http') === 0) {
        $logo_url = $company['logo_url'];
    } 
    // Check if it already has the full path
    else if (strpos($company['logo_url'], '/OMS/') === 0) {
        $logo_url = $company['logo_url']; // Already has full path
    }
    // Otherwise, it's a relative path from dist folder
    else {
        $logo_url = '/OMS/dist/' . ltrim($company['logo_url'], '/');
    }
}
function getPaymentStatusBadge($status) {
    $status = strtolower($status ?? 'unpaid');
    switch ($status) {
        case 'paid': return "status-paid";
        case 'partial': return "status-partial";
        default: return "status-unpaid";
    }
}

function getStatusBadge($status) {
    $status = strtolower(trim($status ?? 'pending'));
    switch ($status) {
        case 'pending': return "status-pending";
        case 'dispatch': return "status-dispatch";
        case 'delivered': return "status-delivered";
        case 'done': return "status-done";
        case 'cancel': return "status-cancel";
        case 'waiting': return "status-waiting";
        case 'return_handover': return "status-return";
        case 'return complete': return "status-return-done";
        default: return "status-pending";
    }
}

function getCallStatusText($callLog) {
    return $callLog == 1 ? 'Answered' : 'No Answer';
}

function getCallStatusBadge($callLog) {
    return $callLog == 1 ? 'call-answered' : 'call-no-answer';
}

$total_item_discounts = 0;
$subtotal_before_discounts = 0;
foreach ($items as $item) {
    $total_item_discounts += floatval($item['item_discount']);
    $subtotal_before_discounts += floatval($item['original_price']);
}
$has_any_discount = $total_item_discounts > 0 || floatval($order['discount']) > 0;
$final_total = $subtotal_before_discounts - $total_item_discounts + $delivery_fee;
$orderStatus = $order['status'] ?? 'pending';
$conditionVal = isset($order['condition']) ? (int)$order['condition'] : 4;
$conditionLabels = [0 => 'Excellent', 1 => 'Good', 2 => 'Average', 3 => 'Bad', 4 => 'New'];
$conditionLabel = $conditionLabels[$conditionVal] ?? 'New';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Order #<?php echo $order_id; ?></title>
    <link rel="stylesheet" href="../assets/css/orders.css" />
    <style>
        .od-wrapper { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 0; }

        .od-top-bar {
            display: flex; justify-content: space-between; align-items: center;
            padding: 12px 16px; background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%);
            border-radius: 8px; margin-bottom: 16px; color: white;
        }
        .od-top-bar .od-order-id { font-size: 1.15rem; font-weight: 700; letter-spacing: 0.5px; }
        .od-top-bar .od-badges { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }

        .od-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 10px; border-radius: 20px; font-size: 0.7rem;
            font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px;
            white-space: nowrap;
        }
        .od-badge.status-paid { background: #dcfce7; color: #166534; }
        .od-badge.status-partial { background: #fef3c7; color: #92400e; }
        .od-badge.status-unpaid { background: #fee2e2; color: #991b1b; }
        .od-badge.status-pending { background: #dbeafe; color: #1e40af; }
        .od-badge.status-dispatch { background: #fef3c7; color: #92400e; }
        .od-badge.status-delivered { background: #d1fae5; color: #065f46; }
        .od-badge.status-done { background: #dcfce7; color: #166534; }
        .od-badge.status-cancel { background: #fee2e2; color: #991b1b; }
        .od-badge.status-waiting { background: #e0e7ff; color: #3730a3; }
        .od-badge.status-return { background: #fed7aa; color: #9a3412; }
        .od-badge.status-return-done { background: #d1fae5; color: #065f46; }
        .od-badge.call-answered { background: #dcfce7; color: #166534; }
        .od-badge.call-no-answer { background: #fee2e2; color: #991b1b; }
        .od-badge.od-badge-outline { background: rgba(255,255,255,0.15); color: white; }
        .od-badge.cond-excellent { background: #dcfce7; color: #166534; }
        .od-badge.cond-good { background: #cffafe; color: #155e75; }
        .od-badge.cond-average { background: #fef3c7; color: #92400e; }
        .od-badge.cond-bad { background: #fee2e2; color: #991b1b; }
        .od-badge.cond-new { background: #f3f4f6; color: #6b7280; }

        .od-section {
            background: white; border-radius: 8px; padding: 16px;
            margin-bottom: 12px; border: 1px solid #e5e7eb;
        }
        .od-section-title {
            font-size: 0.8rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.8px; color: #6b7280; margin-bottom: 12px;
            padding-bottom: 8px; border-bottom: 1px solid #f3f4f6;
            display: flex; align-items: center; gap: 8px;
        }
        .od-section-title i { font-size: 0.85rem; color: #3b82f6; }

        .od-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .od-grid-4 { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 12px; }

        .od-field { display: flex; flex-direction: column; gap: 2px; }
        .od-field-label {
            font-size: 0.7rem; font-weight: 600; color: #9ca3af;
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .od-field-value {
            font-size: 0.85rem; color: #1f2937; font-weight: 500;
            word-break: break-word;
        }
        .od-field-value.od-highlight { font-size: 1rem; font-weight: 700; color: #111827; }
        .od-field-value.od-muted { color: #6b7280; font-weight: 400; }

        .od-customer-card {
            display: flex; align-items: flex-start; gap: 12px;
        }
        .od-customer-avatar {
            width: 40px; height: 40px; border-radius: 50%;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 700; font-size: 1rem; flex-shrink: 0;
        }
        .od-customer-info { flex: 1; }
        .od-customer-name { font-size: 0.95rem; font-weight: 600; color: #111827; margin-bottom: 4px; }
        .od-customer-detail { font-size: 0.8rem; color: #6b7280; line-height: 1.6; }
        .od-customer-detail i { width: 14px; color: #9ca3af; margin-right: 4px; }

        .od-items-table {
            width: 100%; border-collapse: collapse; font-size: 0.8rem;
        }
        .od-items-table thead th {
            background: #f9fafb; padding: 8px 10px; text-align: left;
            font-weight: 600; color: #6b7280; font-size: 0.7rem;
            text-transform: uppercase; letter-spacing: 0.5px;
            border-bottom: 2px solid #e5e7eb;
        }
        .od-items-table tbody td {
            padding: 10px; border-bottom: 1px solid #f3f4f6;
            color: #374151; vertical-align: middle;
        }
        .od-items-table tbody tr:hover { background: #f9fafb; }
        .od-items-table .text-right { text-align: right; }
        .od-items-table .text-center { text-align: center; }
        .od-items-table .fw-600 { font-weight: 600; }

        .od-totals { margin-top: 8px; }
        .od-total-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 6px 0; font-size: 0.82rem;
        }
        .od-total-row .od-total-label { color: #6b7280; }
        .od-total-row .od-total-value { font-weight: 500; color: #374151; }
        .od-total-row.od-grand-total {
            border-top: 2px solid #e5e7eb; padding-top: 8px; margin-top: 4px;
        }
        .od-total-row.od-grand-total .od-total-label { font-weight: 700; color: #111827; }
        .od-total-row.od-grand-total .od-total-value { font-size: 1rem; font-weight: 700; color: #111827; }
        .od-total-row.od-discount .od-total-value { color: #059669; }
        .od-total-row.od-delivery .od-total-value { color: #d97706; }

        .od-notes {
            background: #fffbeb; border-left: 3px solid #f59e0b;
            padding: 10px 12px; border-radius: 0 6px 6px 0;
            font-size: 0.82rem; color: #92400e; line-height: 1.5;
        }
        .od-notes-title { font-weight: 600; margin-bottom: 4px; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; }

        .od-empty-state {
            text-align: center; padding: 20px; color: #9ca3af;
            font-size: 0.82rem;
        }
        .od-empty-state i { font-size: 1.5rem; margin-bottom: 6px; display: block; }

        @media (max-width: 768px) {
            .od-grid-2, .od-grid-4 { grid-template-columns: 1fr; }
            .od-top-bar { flex-direction: column; gap: 8px; align-items: flex-start; }
            .od-items-table { font-size: 0.75rem; }
            .od-items-table thead th, .od-items-table tbody td { padding: 6px; }
        }
    </style>
</head>
<body>
<div class="od-wrapper">
    <?php if (session_status() == PHP_SESSION_NONE) { session_start(); } ?>

    <?php if (isset($_SESSION['order_success'])): ?>
        <div class="alert alert-success" style="margin-bottom:12px;"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['order_success']); unset($_SESSION['order_success']); ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['order_warning'])): ?>
        <div class="alert alert-warning" style="margin-bottom:12px;"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['order_warning']); unset($_SESSION['order_warning']); ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['order_error'])): ?>
        <div class="alert alert-danger" style="margin-bottom:12px;"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['order_error']); unset($_SESSION['order_error']); ?></div>
    <?php endif; ?>

    <!-- Top Bar -->
    <div class="od-top-bar">
        <div class="od-order-id"><i class="fas fa-hashtag"></i> <?php echo $order_id; ?></div>
        <div class="od-badges">
            <span class="od-badge <?php echo getStatusBadge($orderStatus); ?>">
                <i class="fas fa-circle" style="font-size:6px;"></i> <?php echo ucfirst($orderStatus); ?>
            </span>
            <span class="od-badge <?php echo getPaymentStatusBadge($orderPayStatus); ?>">
                <i class="fas fa-<?php echo $orderPayStatus == 'paid' ? 'check-circle' : ($orderPayStatus == 'partial' ? 'clock' : 'times-circle'); ?>"></i>
                <?php echo ucfirst($orderPayStatus); ?>
            </span>
            <span class="od-badge od-badge-outline">
                <i class="fas fa-coins"></i> <?php echo strtoupper($currency); ?>
            </span>
            <span class="od-badge <?php
                $condClasses = [0=>'cond-excellent', 1=>'cond-good', 2=>'cond-average', 3=>'cond-bad', 4=>'cond-new'];
                echo $condClasses[$conditionVal] ?? 'cond-new';
            ?>">
                <i class="fas fa-star"></i> <?php echo $conditionLabel; ?>
            </span>
        </div>
    </div>

    <!-- Order Info -->
    <div class="od-section">
        <div class="od-section-title"><i class="fas fa-info-circle"></i> Order Information</div>
        <div class="od-grid-4">
            <div class="od-field">
                <span class="od-field-label">Issue Date</span>
                <span class="od-field-value"><i class="fas fa-clock" style="color:#6b7280;margin-right:4px;font-size:0.75rem;"></i><?php echo date('d M Y H:i:s', strtotime($order['created_at'])); ?></span>
            </div>
            <div class="od-field">
                <span class="od-field-label">Due Date</span>
                <span class="od-field-value"><i class="fas fa-calendar-check" style="color:#f59e0b;margin-right:4px;font-size:0.75rem;"></i><?php echo date('d M Y', strtotime($order['due_date'])); ?></span>
            </div>
            <div class="od-field">
                <span class="od-field-label">Created By</span>
                <span class="od-field-value"><i class="fas fa-user" style="color:#6b7280;margin-right:4px;font-size:0.75rem;"></i><?php echo !empty($order['user_name']) ? htmlspecialchars($order['user_name']) : '-'; ?></span>
            </div>
        </div>
    </div>

    <!-- Customer Info -->
    <div class="od-section">
        <div class="od-section-title"><i class="fas fa-user"></i> Customer Information</div>
        <div class="od-customer-card">
            <div class="od-customer-avatar">
                <?php echo strtoupper(substr(htmlspecialchars($order['customer_name'] ?? 'U'), 0, 1)); ?>
            </div>
            <div class="od-customer-info">
                <div class="od-customer-name"><?php echo htmlspecialchars($order['customer_name'] ?? 'N/A'); ?></div>
                <div class="od-customer-detail">
                    <?php if (!empty($order['customer_phone'])): ?>
                        <div><i class="fas fa-phone"></i> <?php echo htmlspecialchars($order['customer_phone']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($order['customer_phone_2'])): ?>
                        <div><i class="fas fa-phone-alt"></i> <?php echo htmlspecialchars($order['customer_phone_2']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($order['customer_email'])): ?>
                        <div><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($order['customer_email']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($customer_address)): ?>
                        <div><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($customer_address); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($order['customer_city'])): ?>
                        <div><i class="fas fa-city"></i> <?php echo htmlspecialchars($order['customer_city']); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Order Items -->
    <div class="od-section">
        <div class="od-section-title"><i class="fas fa-box"></i> Order Items (<?php echo count($items); ?>)</div>
        <?php if (count($items) > 0): ?>
        <div style="overflow-x:auto;">
            <table class="od-items-table">
                <thead>
                    <tr>
                        <th width="4%">#</th>
                        <th>Product</th>
                        <th>Description</th>
                        <th class="text-center" width="7%">Qty</th>
                        <th class="text-right" width="12%">Unit Price</th>
                        <?php if ($has_any_discount): ?>
                        <th class="text-right" width="10%">Discount</th>
                        <?php endif; ?>
                        <th class="text-right" width="12%">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach ($items as $item):
                        $qty = intval($item['quantity'] ?? 1);
                        $up = floatval($item['unit_price'] ?? 0);
                        $disc = floatval($item['discount'] ?? 0);
                        $tot = floatval($item['total_amount'] ?? 0);
                    ?>
                    <tr>
                        <td><?php echo $i++; ?></td>
                        <td class="fw-600"><?php echo htmlspecialchars($item['product_name']); ?></td>
                        <td class="od-muted" style="font-size:0.75rem;"><?php echo htmlspecialchars($item['product_description'] ?? '-'); ?></td>
                        <td class="text-center fw-600"><?php echo $qty; ?></td>
                        <td class="text-right"><?php echo $currencySymbol . ' ' . number_format($up, 2); ?></td>
                        <?php if ($has_any_discount): ?>
                        <td class="text-right" style="color:<?php echo $disc > 0 ? '#059669' : '#9ca3af'; ?>;"><?php echo $disc > 0 ? $currencySymbol . ' ' . number_format($disc, 2) : '-'; ?></td>
                        <?php endif; ?>
                        <td class="text-right fw-600"><?php echo $currencySymbol . ' ' . number_format($tot, 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="od-totals" style="max-width:300px; margin-left:auto;">
            <div class="od-total-row">
                <span class="od-total-label">Subtotal</span>
                <span class="od-total-value"><?php echo $currencySymbol . ' ' . number_format($subtotal_before_discounts, 2); ?></span>
            </div>
            <?php if ($has_any_discount): ?>
            <div class="od-total-row od-discount">
                <span class="od-total-label">Discount</span>
                <span class="od-total-value">- <?php echo $currencySymbol . ' ' . number_format($total_item_discounts, 2); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($delivery_fee > 0): ?>
            <div class="od-total-row od-delivery">
                <span class="od-total-label">Delivery Fee</span>
                <span class="od-total-value">+ <?php echo $currencySymbol . ' ' . number_format($delivery_fee, 2); ?></span>
            </div>
            <?php endif; ?>
            <div class="od-total-row od-grand-total">
                <span class="od-total-label">Total</span>
                <span class="od-total-value"><?php echo $currencySymbol . ' ' . number_format($final_total, 2); ?></span>
            </div>
        </div>
        <?php else: ?>
        <div class="od-empty-state">
            <i class="fas fa-box-open"></i>
            No items found for this order
        </div>
        <?php endif; ?>
    </div>

    <!-- Payment & Call Info -->
    <div class="od-grid-2">
        <!-- Payment -->
        <div class="od-section">
            <div class="od-section-title"><i class="fas fa-credit-card"></i> Payment Details</div>
            <div class="od-field" style="margin-bottom:8px;">
                <span class="od-field-label">Payment Status</span>
                <span class="od-field-value"><span class="od-badge <?php echo getPaymentStatusBadge($orderPayStatus); ?>"><?php echo ucfirst($orderPayStatus); ?></span></span>
            </div>
            <?php if (!empty($order['payment_method'])): ?>
            <div class="od-field" style="margin-bottom:8px;">
                <span class="od-field-label">Payment Method</span>
                <span class="od-field-value"><?php echo htmlspecialchars(ucwords(str_replace('_',' ',$order['payment_method']))); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($order['amount_paid'])): ?>
            <div class="od-field" style="margin-bottom:8px;">
                <span class="od-field-label">Amount Paid</span>
                <span class="od-field-value od-highlight"><?php echo $currencySymbol . ' ' . number_format(floatval($order['amount_paid']), 2); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($order['order_pay_date'])): ?>
            <div class="od-field" style="margin-bottom:8px;">
                <span class="od-field-label">Payment Date</span>
                <span class="od-field-value"><?php echo date('d M Y H:i', strtotime($order['order_pay_date'])); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($order['paid_by_name'])): ?>
            <div class="od-field" style="margin-bottom:8px;">
                <span class="od-field-label">Processed By</span>
                <span class="od-field-value"><?php echo htmlspecialchars($order['paid_by_name']); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($order['payment_slip'])): ?>
            <div class="od-field">
                <span class="od-field-label">Payment Slip</span>
                <span class="od-field-value"><a href="/OMS/dist/uploads/payment_slips/<?php echo urlencode($order['payment_slip']); ?>" target="_blank" style="color:#3b82f6;text-decoration:none;font-size:0.8rem;"><i class="fas fa-file-image"></i> View Slip</a></span>
            </div>
            <?php endif; ?>
            <?php if (empty($order['payment_method']) && empty($order['amount_paid'])): ?>
            <div class="od-empty-state" style="padding:12px;">
                <i class="fas fa-minus-circle" style="font-size:1rem;"></i>
                No payment recorded
            </div>
            <?php endif; ?>
        </div>

        <!-- Call Status / Success Rate -->
        <div class="od-section">
            <div class="od-section-title"><i class="fas fa-headset"></i> Call & Success Rate</div>
            <div class="od-field" style="margin-bottom:8px;">
                <span class="od-field-label">Success Rate</span>
                <span class="od-field-value"><span class="od-badge <?php
                    $condClasses = [0=>'cond-excellent', 1=>'cond-good', 2=>'cond-average', 3=>'cond-bad', 4=>'cond-new'];
                    echo $condClasses[$conditionVal] ?? 'cond-new';
                ?>"><i class="fas fa-star" style="font-size:8px;"></i> <?php echo $conditionLabel; ?></span></span>
            </div>
            <div class="od-field" style="margin-bottom:8px;">
                <span class="od-field-label">Call Status</span>
                <span class="od-field-value"><span class="od-badge <?php echo getCallStatusBadge($order['call_log']); ?>"><?php echo getCallStatusText($order['call_log']); ?></span></span>
            </div>
            <?php if ($order['call_log'] == 1 && !empty($order['answer_reason'])): ?>
            <div class="od-field" style="margin-bottom:8px;">
                <span class="od-field-label">Answer Reason</span>
                <span class="od-field-value od-muted"><?php echo htmlspecialchars($order['answer_reason']); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($order['call_log'] != 1 && !empty($order['no_answer_reason'])): ?>
            <div class="od-field" style="margin-bottom:8px;">
                <span class="od-field-label">No Answer Reason</span>
                <span class="od-field-value od-muted"><?php echo htmlspecialchars($order['no_answer_reason']); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($order['cancellation_reason'])): ?>
            <div class="od-field">
                <span class="od-field-label">Cancellation Reason</span>
                <span class="od-field-value" style="color:#dc2626;"><?php echo htmlspecialchars($order['cancellation_reason']); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Notes -->
    <?php if (!empty($order['notes'])): ?>
    <div class="od-section">
        <div class="od-section-title"><i class="fas fa-sticky-note"></i> Notes</div>
        <div class="od-notes">
            <div class="od-notes-title">Order Note</div>
            <?php echo nl2br(htmlspecialchars($order['notes'])); ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function viewPaymentSlip(slipFileName) {
    const slipUrl = '/OMS/dist/uploads/payment_slips/' + encodeURIComponent(slipFileName);
    window.open(slipUrl, '_blank');
}

</script>
</body>
</html>
<?php $conn->close(); ?>