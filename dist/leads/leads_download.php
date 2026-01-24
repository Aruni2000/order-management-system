<?php
// Start session at the very beginning
session_start();

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // Clear any existing output buffers
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /order_management/dist/pages/login.php");
    exit();
}

// Get user permissions and tenant info
$logged_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
$is_main_admin = isset($_SESSION['is_main_admin']) ? $_SESSION['is_main_admin'] : 0;
$role_id = isset($_SESSION['role_id']) ? $_SESSION['role_id'] : 0;
$user_tenant_id = isset($_SESSION['tenant_id']) ? $_SESSION['tenant_id'] : 0;

// Include the database connection file
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/connection/db_connection.php');

// Check if order ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("Order ID is required");
}

$order_id = $_GET['id'];
$show_payment_details = isset($_GET['show_payment']) && $_GET['show_payment'] === 'true';

// ==========================================
// ✅ FETCH COMPANY INFORMATION FROM BRANDING TABLE
// ==========================================
// Branding should be tenant-specific if not superadmin
if ($is_main_admin === 1 && $role_id === 1) {
    $branding_query = "SELECT company_name, address, hotline, email, logo_url FROM branding WHERE active = 1 LIMIT 1";
    $branding_result = $conn->query($branding_query);
} else {
    $branding_query = "SELECT company_name, address, hotline, email, logo_url FROM branding WHERE tenant_id = ? AND active = 1 LIMIT 1";
    $branding_stmt = $conn->prepare($branding_query);
    $branding_stmt->bind_param("i", $user_tenant_id);
    $branding_stmt->execute();
    $branding_result = $branding_stmt->get_result();
}

if ($branding_result && $branding_result->num_rows > 0) {
    $branding = $branding_result->fetch_assoc();
    // Clean up the address - remove extra backslashes and format properly
    $branding['address'] = str_replace(['\\\\r\\\\n', '\\r\\n', '\\n'], "\n", $branding['address']);
    
    // ==========================================
    // ✅ ALWAYS USE LOGO FROM DATABASE
    // ==========================================
    if (!empty($branding['logo_url'])) {
        // Check if it's a full URL (starts with http/https)
        if (strpos($branding['logo_url'], 'http') === 0) {
            $logo_url = $branding['logo_url'];
        } 
        // Check if it already has the full path
        else if (strpos($branding['logo_url'], '/order_management/') === 0) {
            $logo_url = $branding['logo_url']; // Already has full path
        }
        // Otherwise, it's a relative path from dist folder
        else {
            $logo_url = '/order_management/dist/' . ltrim($branding['logo_url'], '/');
        }
    } else {
        // If logo_url is empty in DB, use fallback and log error
        $logo_url = '../assets/images/logo-white.svg';
        error_log("WARNING: No logo_url found in branding table for active branding record");
    }
    
    // Map branding fields to company array
    $company = [
        'name' => $branding['company_name'],
        'address' => $branding['address'],
        'email' => $branding['email'],
        'phone' => $branding['hotline']
    ];
} else {
    // If no active branding record found
    $logo_url = '../assets/images/logo-white.svg';
    error_log("ERROR: No active branding record found in database");
    
    // Fallback company info
    $company = [
        'name' => 'FE IT Solutions pvt (Ltd)',
        'address' => 'No: 04, Wijayamangalarama Road, Kohuwala',
        'email' => 'info@feitsolutions.com',
        'phone' => '011-2824524'
    ];
}

// UPDATED QUERY: Now gets customer info from order_header table instead of customers table
$order_query = "SELECT 
                i.*, 
                i.pay_status AS order_pay_status,
                i.full_name AS customer_name,
                i.mobile AS customer_phone,
                i.mobile_2 AS customer_phone_2,
                CONCAT_WS(', ', i.address_line1, i.address_line2) AS customer_address,
                i.address_line1,
                i.address_line2,
                ct.city_name AS customer_city,
                c.email AS customer_email,
                c.customer_id,
                p.payment_id, 
                p.amount_paid, 
                p.payment_method, 
                p.payment_date, 
                p.pay_by,
                r.name AS paid_by_name, 
                u.name AS user_name,
                i.delivery_fee, 
                u2.name AS creator_name
                FROM order_header i 
                LEFT JOIN customers c ON i.customer_id = c.customer_id
                LEFT JOIN city_table ct ON i.city_id = ct.city_id
                LEFT JOIN payments p ON i.order_id = p.order_id
                LEFT JOIN roles r ON p.pay_by = r.id
                LEFT JOIN users u ON i.user_id = u.id
                LEFT JOIN users u2 ON i.created_by = u2.id
                WHERE i.order_id = ? 
                AND i.interface = 'leads'
                AND i.status IN ('done', 'pending', 'cancel', 'dispatch', 'no_answer')";

// Add Access Control Clause
if ($is_main_admin === 1 && $role_id === 1) {
    // Superadmin: No additional filter
} elseif ($role_id === 1 && $is_main_admin === 0) {
    // Tenant Admin: filter by tenant
    $order_query .= " AND i.tenant_id = $user_tenant_id";
} else {
    // Regular Agent: filter by tenant and assignment
    $order_query .= " AND i.tenant_id = $user_tenant_id AND i.user_id = $logged_user_id";
}


$stmt = $conn->prepare($order_query);

// Add error checking for prepare statement
if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}

$stmt->bind_param("i", $order_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Leads order not found or access denied");
}

$order = $result->fetch_assoc();

// Get currency from order
$currency = isset($order['currency']) ? strtolower($order['currency']) : 'lkr';
$currencySymbol = ($currency == 'usd') ? '$' : 'Rs.';

// Ensure delivery fee is properly set
$delivery_fee = isset($order['delivery_fee']) && !is_null($order['delivery_fee']) ? floatval($order['delivery_fee']) : 0.00;

// Get order items with product information
$itemSql = "SELECT ii.*, ii.pay_status, p.name as product_name,
            p.description as product_description,
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
$autoPrint = !$show_payment_details;

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

// Count how many columns we need to display in the table (removed product code column)
$column_count = $has_any_discount ? 5 : 4;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Leads Order #<?php echo $order_id; ?></title>
    <link rel="stylesheet" href="../assets/css/orders.css" id="main-style-link" />
    <style>
        .leads-badge {
            background-color: #17a2b8;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8em;
            font-weight: bold;
            margin-left: 10px;
        }
        
        .product-code {
            font-weight: bold;
            color: #007bff;
        }
        
        .creator-info {
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            margin-top: 10px;
        }
        
        .creator-info strong {
            color: #495057;
        }
        
        .modal-specific {
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .order-container {
            max-width: 100%;
            margin: 0;
            padding: 20px;
        }
        
        .product-name {
            font-weight: bold;
        }

        /* Style for logo */
        .company-logo img {
            max-height: 80px;
            max-width: 200px;
            object-fit: contain;
        }
              /* Quantity column styling */
        .product-table td:nth-child(4) {
            font-weight: 600;
            color: #333;
        }

        /* Total column emphasis */
        .product-table td:last-child {
            font-weight: 600;
            color: #000;
        }

        /* Discount column */
        .item-discount {
            color: #dc3545;
            font-size: 0.9em;
        }
    </style>
</head>

<body>
    <div class="order-container modal-specific">
        <div class="order-header">
            <div class="company-logo">
                <img src="<?php echo htmlspecialchars($logo_url); ?>" 
                     alt="<?php echo htmlspecialchars($company['name']); ?> Logo"
                     onerror="this.onerror=null; this.src='../assets/images/logo-white.svg';">
            </div>
            <div class="order-info">
                <div class="order-title">
                    LEADS ORDER : # <?php echo $order_id; ?>
                    <span class="leads-badge">LEADS</span>
                </div>
                <div class="order-date">Date Issued: <?php echo date('Y-m-d', strtotime($order['issue_date'])); ?></div>
                <div>Due Date: <?php echo date('Y-m-d', strtotime($order['due_date'])); ?></div>
                <div>Created Time: <?php echo date('H:i:s', strtotime($order['created_at'])); ?></div>
                <div>Status: <span class="status-badge status-<?php echo strtolower($order['status']); ?>"><?php echo ucfirst($order['status']); ?></span></div>
                <div class="pay-status">
                    Pay Status:
                    <span class="payment-badge <?php echo getPaymentStatusBadge($orderPayStatus); ?>">
                        <?php echo ucfirst($orderPayStatus); ?>
                    </span>
                </div>
                <?php if (!empty($order['creator_name'])): ?>
                    <div class="creator-info">
                        <strong>Created By:</strong> <?php echo htmlspecialchars($order['creator_name']); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="billing-details">
            <div class="billing-block">
                <div class="billing-title">Billing From :</div>
                <div class="billing-info">
                    <div><?php echo htmlspecialchars($company['name']); ?></div>
                    <div><?php echo nl2br(htmlspecialchars($company['address'])); ?></div>
                    <div><?php echo htmlspecialchars($company['email']); ?></div>
                    <div><?php echo htmlspecialchars($company['phone']); ?></div>
                </div>
            </div>
            <div class="billing-block">
                <div class="billing-title">Billing To :</div>
                <div class="billing-info">
                    <!-- UPDATED: Now uses full_name from order_header -->
                    <strong><?php echo !empty($order['customer_name']) ? htmlspecialchars($order['customer_name']) : 'N/A'; ?></strong>
                    <?php if (!empty($order['customer_id'])): ?>
                        <span style="color: #666; font-size: 0.9em;">(ID: <?php echo htmlspecialchars($order['customer_id']); ?>)</span>
                    <?php endif; ?>
                    <br>
                    <!-- UPDATED: Now uses address_line1 and address_line2 from order_header -->
                    <?php if (!empty($order['customer_address'])): ?>
                        <?php echo nl2br(htmlspecialchars($order['customer_address'])); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($order['customer_city'])): ?>
                        City: <?php echo htmlspecialchars($order['customer_city']); ?><br>
                    <?php endif; ?>
                    <!-- UPDATED: Now displays mobile and mobile_2 from order_header -->
                    <?php if (!empty($order['customer_phone'])): ?>
                        Phone Number 1: <?php echo htmlspecialchars($order['customer_phone']); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($order['customer_phone_2'])): ?>
                        Phone Number 2: <?php echo htmlspecialchars($order['customer_phone_2']); ?>
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
                        <td colspan="<?php echo $column_count; ?>" style="text-align: center;">No items found for this leads order</td>
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
            <p><?php echo !empty($order['notes']) ? nl2br(htmlspecialchars($order['notes'])) : 'This is a leads order. Please process according to leads workflow procedures.'; ?>
            </p>
        </div>

        <?php if ($orderPayStatus == 'paid' || $orderPayStatus == 'partial'): ?>
            <div class="payment-info">
                <div class="payment-details">
                    <h3>Payment Information</h3>
                    <div>Payment Method: <?php echo htmlspecialchars($order['payment_method'] ?? 'N/A'); ?></div>
                    <div>Amount Paid:
                        <?php echo $currencySymbol . ' ' . number_format((float) ($order['amount_paid'] ?? 0), 2); ?>
                    </div>
                    <div>Payment Date:
                        <?php echo ($order['payment_date']) ? date('d/m/Y', strtotime($order['payment_date'])) : 'N/A'; ?>
                    </div>
                    <div>Processed By: <?php echo htmlspecialchars($order['paid_by_name'] ?? 'N/A'); ?></div>
                </div>
                <div class="signature">
                    <div class="signature-line">
                        Authorized Signature
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="payment-info">
                <div class="payment-methods">
                    <h5>Payment Methods</h5>
                    <p>
                        Account Name: F E IT SOLUTIONS PVT (LTD)<br>
                        Account Number: 100810008655<br>
                        Account Type: LKR Current Account<br>
                        Bank Name: Nations Trust Bank PLC
                    </p>
                </div>
                <div class="signature">
                    <div class="signature-line">
                        Authorized Signature
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>

</html>
<?php
$conn->close();
?>