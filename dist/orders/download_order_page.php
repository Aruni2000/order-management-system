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
                
                -- Customer data with CONDITIONAL FALLBACK
                oh.customer_id,
                COALESCE(NULLIF(oh.full_name, ''), c.name) AS customer_name,
                COALESCE(NULLIF(oh.mobile, ''), c.phone) AS customer_phone,
                
                -- ✅ FIXED: Only get phone_2 from customers if main order data is missing
                CASE 
                    WHEN oh.full_name IS NOT NULL AND oh.full_name != '' 
                         AND oh.address_line1 IS NOT NULL AND oh.address_line1 != ''
                         AND oh.mobile IS NOT NULL AND oh.mobile != ''
                    THEN oh.mobile_2  -- Use order_header mobile_2 (even if empty/null)
                    ELSE COALESCE(NULLIF(oh.mobile_2, ''), c.phone_2)  -- Fallback to customers
                END AS customer_phone_2,
                
                COALESCE(NULLIF(oh.address_line1, ''), c.address_line1) AS customer_address_line1,
                COALESCE(NULLIF(oh.address_line2, ''), c.address_line2) AS customer_address_line2,
                COALESCE(NULLIF(oh.email, ''), c.email) AS customer_email,
                COALESCE(NULLIF(oh.city_id, 0), c.city_id) AS city_id,
                
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
            LEFT JOIN customers c ON oh.customer_id = c.customer_id
            LEFT JOIN city_table city ON COALESCE(NULLIF(oh.city_id, 0), c.city_id) = city.city_id
            LEFT JOIN payments p ON oh.order_id = p.order_id
            LEFT JOIN roles r ON p.pay_by = r.id
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

// Fetch company information from branding table (UPDATED TO INCLUDE LOGO)
// Fetch company information from branding table (UPDATED TO ALWAYS USE DB LOGO)
// Fetch company information from branding table (UPDATED TO ALWAYS USE DB LOGO)
// Try to get tenant-specific branding first
$order_tenant_id = isset($order['tenant_id']) ? (int)$order['tenant_id'] : 0;
$branding_query = "SELECT company_name, address, hotline, email, logo_url FROM branding WHERE tenant_id = $order_tenant_id AND active = 1 LIMIT 1";
$branding_result = $conn->query($branding_query);

// Fallback to general branding if no specific tenant branding found
if (!$branding_result || $branding_result->num_rows === 0) {
    $branding_query = "SELECT company_name, address, hotline, email, logo_url FROM branding WHERE active = 1 LIMIT 1";
    $branding_result = $conn->query($branding_query);
}

if ($branding_result && $branding_result->num_rows > 0) {
    $company = $branding_result->fetch_assoc();
    // Clean up the address - remove extra backslashes and format properly
    $company['address'] = str_replace(['\\\\r\\\\n', '\\r\\n', '\\n'], "\n", $company['address']);
    
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
    } else {
        // If logo_url is empty in DB, show error instead of fallback
        $logo_url = '';
        error_log("WARNING: No logo_url found in branding table for active branding record");
    }
} else {
    // If no active branding record found, show error
    $logo_url = '';
    error_log("ERROR: No active branding record found in database");
    die("Error: Company branding not configured. Please contact administrator.");
}

// Function to get the color for payment status
function getPaymentStatusColor($status)
{
    $status = strtolower($status ?? 'unpaid');

    switch ($status) {
        case 'paid':
            return "color: #28a745;"; // Green for paid
        case 'partial':
            return "color: #fd7e14;"; // Orange for partial payment
        case 'unpaid':
        default:
            return "color: #dc3545;"; // Red for unpaid
    }
}

// Function to get badge class for payment status
function getPaymentStatusBadge($status)
{
    $status = strtolower($status ?? 'unpaid');

    switch ($status) {
        case 'paid':
            return "bg-success"; // Green for paid
        case 'partial':
            return "bg-warning"; // Orange for partial payment
        case 'unpaid':
        default:
            return "bg-danger"; // Red for unpaid
    }
}

// Set autoPrint for normal view
// COMMENTED OUT: Print functionality disabled
// $autoPrint = !$show_payment_details;
$autoPrint = false; // Disabled printing

// Calculate total item-level discounts
$total_item_discounts = 0;
foreach ($items as $item) {
    $total_item_discounts += floatval($item['item_discount']);
}

// Calculate subtotal before discounts (using original prices)
$subtotal_before_discounts = 0;
foreach ($items as $item) {
    $subtotal_before_discounts += floatval($item['original_price']);
}

// Check if there are any discounts at all (order level or item level)
$has_any_discount = $total_item_discounts > 0 || floatval($order['discount']) > 0;

// Count how many columns we need to display in the table
$column_count = $has_any_discount ? 5 : 4;

// ==========================================
// ✅ DEBUG LOGGING (REMOVE IN PRODUCTION)
// ==========================================
error_log("DEBUG - Invoice Display for Order #$order_id:");
error_log("  - Customer Name: " . $order['customer_name']);
error_log("  - Customer Phone: " . $order['customer_phone']);
error_log("  - Customer Phone 2: " . ($order['customer_phone_2'] ?? 'NULL'));
error_log("  - Address Line 1: " . $order['customer_address_line1']);
error_log("  - Address Line 2: " . $order['customer_address_line2']);
error_log("  - City: " . $order['customer_city']);
error_log("  - Data Source: " . (empty($order['full_name']) ? 'customers table (fallback)' : 'order_header table'));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Order #<?php echo $order_id; ?></title>
    <link rel="stylesheet" href="../assets/css/orders.css" id="main-style-link" />
    <style>
        /* Alert message styles */
        .alert {
            margin: 20px 0;
            padding: 15px;
            border-radius: 8px;
            position: relative;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            font-family: Arial, sans-serif;
        }

        .alert-success {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .alert-warning {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
        }

        .alert-danger {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        .alert-info {
            background-color: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }

        .alert i {
            margin-right: 8px;
        }

        .btn-close {
            position: absolute;
            top: 10px;
            right: 15px;
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            opacity: 0.5;
        }

        .btn-close:hover {
            opacity: 1;
        }

        .payment-slip-section {
            margin-top: 20px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }
        .payment-slip-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .payment-slip-info h4 {
            margin: 0;
            color: #495057;
        }
        .view-slip-btn {
            background-color: #007bff;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        .view-slip-btn:hover {
            background-color: #0056b3;
        }
        .view-slip-btn:disabled {
            background-color: #6c757d;
            cursor: not-allowed;
        }
        .payment-date-info {
            margin-top: 5px;
        }

        /* Style for logo with fallback */
        .company-logo img {
            max-height: 80px;
            max-width: 200px;
            object-fit: contain;
        }

        /* Phone number styling */
        .phone-secondary {
            color: #666;
            font-size: 0.95em;
        }

        /* Data source indicator (for debugging - remove in production) */
        .data-source-debug {
            background-color: #e7f3ff;
            border: 1px solid #b3d9ff;
            padding: 8px 12px;
            margin: 10px 0;
            border-radius: 4px;
            font-size: 12px;
            color: #004085;
        }

        /* Make alerts responsive */
        @media (max-width: 768px) {
            .alert {
                margin: 10px 0;
                padding: 12px;
                font-size: 14px;
            }
        }
    </style>
</head>

<body>
    <div class="order-container">
        <?php
        // Start session if not already started
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }

        // Check for success message and display it
        if (isset($_SESSION['order_success'])) {
            $success_message = $_SESSION['order_success'];
            unset($_SESSION['order_success']); // Clear the message so it doesn't show again
            ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close">&times;</button>
            </div>
            <script>
                // Auto-hide the success message after 8 seconds (increased time)
                setTimeout(function() {
                    var alert = document.querySelector('.alert-success');
                    if (alert) {
                        alert.style.transition = 'opacity 0.7s';
                        alert.style.opacity = '0';
                        setTimeout(function() {
                            alert.remove();
                        }, 500);
                    }
                }, 8000);
            </script>
            <?php
        }

        // Check for warning message and display it (for FDE API errors, courier issues, etc.)
        if (isset($_SESSION['order_warning'])) {
            $warning_message = $_SESSION['order_warning'];
            unset($_SESSION['order_warning']); // Clear the message
            ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle"></i> <strong>Courier/Tracking Warning:</strong> <?php echo htmlspecialchars($warning_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close">&times;</button>
            </div>
            <script>
                // Auto-hide the warning message after 10 seconds (longer for warnings)
                setTimeout(function() {
                    var alert = document.querySelector('.alert-warning');
                    if (alert) {
                        alert.style.transition = 'opacity 0.7s';
                        alert.style.opacity = '0';
                        setTimeout(function() {
                            alert.remove();
                        }, 500);
                    }
                }, 10000);
            </script>
            <?php
        }

        // Check for error message and display it
        if (isset($_SESSION['order_error'])) {
            $error_message = $_SESSION['order_error'];
            unset($_SESSION['order_error']); // Clear the message
            ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close">&times;</button>
            </div>
            <script>
                // Auto-hide the error message after 10 seconds
                setTimeout(function() {
                    var alert = document.querySelector('.alert-danger');
                    if (alert) {
                        alert.style.transition = 'opacity 0.7s';
                        alert.style.opacity = '0';
                        setTimeout(function() {
                            alert.remove();
                        }, 500);
                    }
                }, 10000);
            </script>
            <?php
        }

        // Check for info message and display it
        if (isset($_SESSION['order_info'])) {
            $info_message = $_SESSION['order_info'];
            unset($_SESSION['order_info']); // Clear the message
            ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($info_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close">&times;</button>
            </div>
            <script>
                // Auto-hide the info message after 8 seconds
                setTimeout(function() {
                    var alert = document.querySelector('.alert-info');
                    if (alert) {
                        alert.style.transition = 'opacity 0.7s';
                        alert.style.opacity = '0';
                        setTimeout(function() {
                            alert.remove();
                        }, 500);
                    }
                }, 8000);
            </script>
            <?php
        }
        ?>

        <?php
        // ==========================================
        // ✅ DEBUG INFO (REMOVE IN PRODUCTION)
        // Shows where the data is coming from
        // ==========================================
        $data_source = empty($order['full_name']) ? 'Customers Table (Fallback)' : 'Order Header Table';
        ?>
        <!-- REMOVE THIS SECTION IN PRODUCTION -->
        <!-- <div class="data-source-debug">
            ℹ️ <strong>Data Source:</strong> <?php echo $data_source; ?> 
            <?php if (empty($order['full_name'])): ?>
                (Order header fields were empty, displaying from customers table)
            <?php endif; ?>
        </div> -->

        <?php if (!$autoPrint): ?>
            <div class="control-buttons">
                <?php if ($show_payment_details && $orderPayStatus != 'paid'): ?>
                    <button id="markAsPaidBtn" class="btn btn-success">Mark as Paid</button>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="order-header">
        <div class="company-logo">
    <?php if (!empty($logo_url)): ?>
        <!-- Show logo if available in database -->
        <img src="<?php echo htmlspecialchars($logo_url); ?>" 
             alt="<?php echo htmlspecialchars($company['company_name']); ?> Logo">
    <?php else: ?>
        <!-- Show company name if no logo -->
        <h6 style="margin: 0; color: #333; font-weight: bold;">
            <?php echo htmlspecialchars($company['company_name']); ?>
        </h6>
    <?php endif; ?>
</div>
            <div class="order-info">
                <div class="order-title">ORDER : # <?php echo $order_id; ?></div>
                <div class="order-date">Date Issued: <?php echo date('Y-m-d', strtotime($order['issue_date'])); ?>
                </div>
                <div>Due Date: <?php echo date('Y-m-d', strtotime($order['due_date'])); ?></div>
                <div>Created Time: <?php echo date('H:i:s', strtotime($order['created_at'])); ?></div>
                <div class="pay-status">
                    Pay Status:
                    <span class="payment-badge <?php echo getPaymentStatusBadge($orderPayStatus); ?>">
                        <?php echo ucfirst($orderPayStatus); ?>
                    </span>
                </div>
                
                <?php if (!empty($order['user_name'])): ?>
                    <div class="pay-by-info">
                        <strong>Created By:</strong> <?php echo htmlspecialchars($order['user_name']); ?> (ID: <?php echo $order['user_id']; ?>)
                    </div>
                <?php endif; ?>

                <?php if (!empty($order['order_pay_date'])): ?>
                    <div class="payment-date-info">
                        <strong>Payment Date:</strong> <?php echo date('d/m/Y', strtotime($order['order_pay_date'])); ?>
                    </div>
                <?php endif; ?>

            </div>
        </div>

        <div class="billing-details">
            <div class="billing-block">
                <div class="billing-title">Billing From :</div>
                <div class="billing-info">
                    <div><?php echo htmlspecialchars($company['company_name']); ?></div>
                    <div><?php echo nl2br(htmlspecialchars($company['address'])); ?></div>
                    <div><?php echo htmlspecialchars($company['email']); ?></div>
                    <div><?php echo htmlspecialchars($company['hotline']); ?></div>
                </div>
            </div>
            <div class="billing-block">
                <div class="billing-title">Billing To :</div>
                <div class="billing-info">
                    <strong><?php echo htmlspecialchars($order['customer_name']); ?></strong><br>
                    <?php echo nl2br(htmlspecialchars($customer_address)); ?><br>
                    <?php if (!empty($order['customer_city'])): ?>
                        City: <?php echo htmlspecialchars($order['customer_city']); ?><br>
                    <?php endif; ?>
                    
                    <?php 
                    // ==========================================
                    // ✅ Display phone numbers from order_header (with fallback to customers table)
                    // ==========================================
                    ?>
                    Phone Number 1: <?php echo htmlspecialchars($order['customer_phone']); ?>
                    <?php if (!empty($order['customer_phone_2'])): ?>
                        <br><span class="phone-secondary">Phone Number 2: <?php echo htmlspecialchars($order['customer_phone_2']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($order['customer_email'])): ?>
                        <br>Email: <?php echo htmlspecialchars($order['customer_email']); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <table class="product-table">
            <thead>
                <tr>
                    <th width="5%">#</th>
            <th width="<?php echo $has_any_discount ? '25%' : '30%'; ?>">PRODUCT</th>
            <th width="<?php echo $has_any_discount ? '25%' : '30%'; ?>">DESCRIPTION</th>
            <th width="8%" style="text-align: center;">QTY</th>
            <th width="12%" style="text-align: right;">UNIT PRICE</th>
                    <?php if ($has_any_discount): ?>
                <th width="12%" style="text-align: right;">DISCOUNT</th>
                    <?php endif; ?>
            <th width="13%" style="text-align: right;">TOTAL</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $i = 1;
                if (count($items) > 0):
                    foreach ($items as $item):
                // Get quantity from database
                $quantity = isset($item['quantity']) ? intval($item['quantity']) : 1;
                $unit_price = isset($item['unit_price']) ? floatval($item['unit_price']) : 0;
                $item_discount = isset($item['discount']) ? floatval($item['discount']) : 0;
                $total_price = isset($item['total_amount']) ? floatval($item['total_amount']) : 0;
                
                // Calculate total before discount for display
                $total_before_discount = $unit_price * $quantity;
                ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                            <td><?php echo htmlspecialchars($item['product_description']); ?></td>
                    <td style="text-align: center; font-weight: 600;">
                        <?php echo $quantity; ?>
                    </td>
                    <td style="text-align: right;">
                        <?php echo $currencySymbol . ' ' . number_format($unit_price, 2); ?>
                    </td>
                            <?php if ($has_any_discount): ?>
                            <td style="text-align: right;">
                                <?php 
                                if ($item_discount > 0) {
                                echo $currencySymbol . ' ' . number_format($item_discount, 2);
                                } else {
                                echo '-';
                                }
                                ?>
                        </td>
                    <?php endif; ?>
                    <td style="text-align: right; font-weight: 600;">
                        <?php echo $currencySymbol . ' ' . number_format($total_price, 2); ?>
                            </td>
                        </tr>
                    <?php endforeach;
                else: ?>
                    <tr>
                        <td colspan="<?php echo $column_count; ?>" style="text-align: center;">No items found for this order</td>
                    </tr>
                <?php endif; ?>

                <tr class="total-row">
            <td colspan="<?php echo $has_any_discount ? '6' : '5'; ?>" style="text-align: right; border-right: none;">Sub Total :</td>
                    <td class="total-value">
                        <?php echo $currencySymbol . ' ' . number_format($subtotal_before_discounts, 2); ?>
                    </td>
                </tr>

                <?php if ($has_any_discount): ?>
                    <tr class="total-row">
                <td colspan="6" style="text-align: right; border-right: none;">Total Discounts :</td>
                        <td class="total-value">
                            <?php echo $currencySymbol . ' ' . number_format($total_item_discounts, 2); ?>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php if ($delivery_fee > 0): ?>
                    <tr class="total-row delivery-fee-row">
                <td colspan="<?php echo $has_any_discount ? '6' : '5'; ?>" style="text-align: right; border-right: none;">Delivery Fee :</td>
                        <td class="total-value">
                            <?php echo $currencySymbol . ' ' . number_format($delivery_fee, 2); ?>
                        </td>
                    </tr>
                <?php endif; ?>

                <tr class="total-row">
            <td colspan="<?php echo $has_any_discount ? '6' : '5'; ?>" style="text-align: right; border-right: none;"><strong> Total :</strong></td>
                    <td class="total-value">
                <strong>
                        <?php 
                        // Calculate final total ensuring delivery fee is included
                        $final_total = $subtotal_before_discounts - $total_item_discounts + $delivery_fee;
                        echo $currencySymbol . ' ' . number_format($final_total, 2); 
                        ?>
                </strong>
                    </td>
                </tr>
            </tbody>
        </table>

        <div class="notes">
            <div class="note-title">Note:</div>
            <p><?php echo !empty($order['notes']) ? nl2br(htmlspecialchars($order['notes'])) : 'Once the order has been verified by the accounts payable team and recorded, the only task left is to send it for approval before releasing the payment'; ?>
            </p>
        </div>

        <?php if ($orderPayStatus != 'paid' && $orderPayStatus != 'partial'): ?>
            <div class="payment-info">
                <div class="payment-methods">
                    <!-- <h5>Payment Methods</h5>
                    <p>
                        Account Name: F E IT SOLUTIONS PVT (LTD)<br>
                        Account Number: 100810008655<br>
                        Account Type: LKR Current Account<br>
                        Bank Name: Nations Trust Bank PLC
                    </p> -->
                </div>
                <div class="signature">
                    <div class="signature-line">
                        Authorized Signature
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

   <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // COMMENTED OUT: Auto print functionality disabled
        /*
        <?php if ($autoPrint): ?>
            // Auto print when page loads
            window.onload = function () {
                window.print();
            }
        <?php endif; ?>
        */

        // Function to view payment slip in new tab
        function viewPaymentSlip(slipFileName) {
            const slipUrl = '/OMS/dist/uploads/payment_slips/' + encodeURIComponent(slipFileName);
            window.open(slipUrl, '_blank');
        }

        // Handle Mark as Paid button click
        document.addEventListener('DOMContentLoaded', function () {
            const markAsPaidBtn = document.getElementById('markAsPaidBtn');
            if (markAsPaidBtn) {
                markAsPaidBtn.addEventListener('click', function () {
                    // Create form data for the AJAX request
                    const formData = new FormData();
                    formData.append('order_id', '<?php echo $order_id; ?>');
                    formData.append('pay_status', 'paid');

                    // Send AJAX request
                    fetch('update_order_status.php', {
                        method: 'POST',
                        body: formData
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                // Simply reload the current page to show updated status
                                window.location.reload();
                            } else {
                                alert('Error updating payment status: ' + data.message);
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('An error occurred while updating the payment status.');
                        });
                });
            }
        });
        
    </script>
    <script>
        // Auto redirect after 10 seconds
        setTimeout(function() {
            window.location.href = 'create_order.php';
        }, 10000);

  </script>
        </body>

</html>
<?php
$conn->close();
?>