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

// Get user permissions and tenant info
$is_main_admin = isset($_SESSION['is_main_admin']) ? (int)$_SESSION['is_main_admin'] : 0;
$role_id = isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : 0;
$session_tenant_id = isset($_SESSION['tenant_id']) ? intval($_SESSION['tenant_id']) : 0;

// Function to log user actions
function logUserAction($conn, $user_id, $action_type, $inquiry_id, $details = null) {
    $stmt = $conn->prepare("INSERT INTO user_logs (user_id, action_type, inquiry_id, details) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isis", $user_id, $action_type, $inquiry_id, $details);
    return $stmt->execute();
}

// Function to check courier and tracking status FOR SELECTED TENANT
function checkCourierStatus($conn, $tenant_id) {
    $status = [
        'has_courier' => false,
        'courier_type' => null,
        'courier_name' => '',
        'has_tracking' => false,
        'tracking_count' => 0,
        'warning_message' => '',
        'error_message' => '',
        'info_message' => ''
    ];
  
    // Get default courier for selected tenant - Updated to include status 3 (Existing API Parcel)
    $courierSql = "SELECT courier_id, courier_name, api_key, client_id, is_default, status 
                   FROM couriers 
                   WHERE tenant_id = ? AND is_default IN (1, 2, 3) AND status = 'active' 
                   ORDER BY is_default ASC 
                   LIMIT 1";
    $courierStmt = $conn->prepare($courierSql);
    $courierStmt->bind_param("i", $tenant_id);
    $courierStmt->execute();
    $courierResult = $courierStmt->get_result();
    
    if ($courierResult && $courierResult->num_rows > 0) {
        $courier = $courierResult->fetch_assoc();
        $status['has_courier'] = true;
        $status['courier_type'] = $courier['is_default'];
        $status['courier_name'] = $courier['courier_name'];
        
        if ($courier['is_default'] == 1) {
            // Internal tracking system - check for unused tracking numbers
            $trackingSql = "SELECT COUNT(*) as unused_count 
                           FROM tracking 
                           WHERE courier_id = ? AND status = 'unused'";
            $trackingStmt = $conn->prepare($trackingSql);
            $trackingStmt->bind_param("i", $courier['courier_id']);
            $trackingStmt->execute();
            $trackingResult = $trackingStmt->get_result();
            
            if ($trackingResult) {
                $trackingData = $trackingResult->fetch_assoc();
                $status['tracking_count'] = $trackingData['unused_count'];
                
                if ($status['tracking_count'] > 0) {
                    $status['has_tracking'] = true;
                }
            }
        } else if ($courier['is_default'] == 2) {
            // FDE API system
            $status['has_tracking'] = true; // API generates tracking numbers
            if (empty($courier['api_key'])) {
                $status['warning_message'] = "Warning: {$courier['courier_name']} API key is missing. Orders may not get tracking numbers automatically.";
            }
        } else if ($courier['is_default'] == 3) {
            // Existing API Parcel system
            $status['has_tracking'] = true; // API integration available
            if (empty($courier['api_key'])) {
                $status['warning_message'] = "Warning: {$courier['courier_name']} API key is missing. Existing API integration may not function properly.";
            }
        }
    } else {
        $status['info_message'] = "No default courier selected. ";
    }
    
    return $status;
}

// Get Order ID from URL
$order_id = isset($_GET['id']) ? trim($_GET['id']) : '';

if (empty($order_id)) {
    $_SESSION['order_error'] = "No order ID provided.";
    header("Location: pending_order_list.php");
    exit();
}

// Fetch order header data
$orderSql = "SELECT i.*, u.name as creator_name, ct.city_name 
             FROM order_header i 
             LEFT JOIN users u ON i.user_id = u.id
             LEFT JOIN city_table ct ON i.city_id = ct.city_id
             WHERE i.order_id = ? LIMIT 1";
$stmt = $conn->prepare($orderSql);
$stmt->bind_param("s", $order_id);
$stmt->execute();
$orderResult = $stmt->get_result();

if (!$orderResult || $orderResult->num_rows === 0) {
    $_SESSION['order_error'] = "Order not found.";
    header("Location: pending_order_list.php");
    exit();
}

$order = $orderResult->fetch_assoc();
$stmt->close();

// Check if order is pending - only pending orders can be edited
if ($order['status'] !== 'pending') {
    $_SESSION['order_error'] = "Only pending orders can be edited.";
    header("Location: pending_order_list.php");
    exit();
}

// Validate tenant access - non-main admins can only edit orders from their own tenant
$order_tenant_id = isset($order['tenant_id']) ? intval($order['tenant_id']) : 0;
if (!($is_main_admin === 1 && $role_id === 1)) {
    // Regular user - must belong to same tenant as order
    if ($order_tenant_id !== $session_tenant_id) {
        $_SESSION['order_error'] = "You do not have permission to edit this order.";
        header("Location: pending_order_list.php");
        exit();
    }
}

// Check courier status for the order's tenant
$courierStatus = checkCourierStatus($conn, $order_tenant_id);

// Fetch order items
$itemsSql = "SELECT oi.*, p.name as product_name, p.description as product_desc, p.lkr_price 
             FROM order_items oi 
             LEFT JOIN products p ON oi.product_id = p.id 
             WHERE oi.order_id = ?";
$stmt = $conn->prepare($itemsSql);
$stmt->bind_param("s", $order_id);
$stmt->execute();
$itemsResult = $stmt->get_result();
$order_items = [];
while ($item = $itemsResult->fetch_assoc()) {
    $order_items[] = $item;
}
$stmt->close();

// Fetch necessary data for the form
$productSql = "SELECT id, name, description, lkr_price FROM products WHERE status = 'active' ORDER BY name ASC";
$productStmt = $conn->prepare($productSql);
$productStmt->execute();
$productsResult = $productStmt->get_result();

// Fetch customer data 
if ($is_main_admin === 1 && $role_id === 1) {
    // Main admin sees all customers from all tenants
    $customerSql = "SELECT c.*, ct.city_name 
                    FROM customers c 
                    LEFT JOIN city_table ct ON c.city_id = ct.city_id 
                    WHERE c.status = 'Active' 
                    ORDER BY c.customer_id DESC";
    $customersResult = $conn->query($customerSql);
} else {
    // Regular users see only their tenant's customers
    $customerSql = "SELECT c.*, ct.city_name 
                    FROM customers c 
                    LEFT JOIN city_table ct ON c.city_id = ct.city_id 
                    WHERE c.tenant_id = ? 
                    AND c.status = 'Active' 
                    ORDER BY c.customer_id DESC";
    $customerStmt = $conn->prepare($customerSql);
    $customerStmt->bind_param("i", $order_tenant_id);
    $customerStmt->execute();
    $customersResult = $customerStmt->get_result();
}

$citySql = "SELECT city_id, city_name FROM city_table WHERE is_active = 1 ORDER BY city_name ASC";
$cityResult = $conn->query($citySql);

// Fetch delivery fee from branding table for the order's tenant
$deliveryFeeSql = "SELECT delivery_fee FROM branding WHERE tenant_id = ? AND active = 1 LIMIT 1";
$deliveryFeeStmt = $conn->prepare($deliveryFeeSql);
$deliveryFeeStmt->bind_param("i", $order_tenant_id);
$deliveryFeeStmt->execute();
$deliveryFeeResult = $deliveryFeeStmt->get_result();
$defaultDeliveryFee = 0.00;
if ($deliveryFeeResult && $deliveryFeeResult->num_rows > 0) {
    $row = $deliveryFeeResult->fetch_assoc();
    $defaultDeliveryFee = floatval($row['delivery_fee']);
}

// Close prepared statements
$productStmt->close();
if (!($is_main_admin === 1 && $role_id === 1)) {
    $customerStmt->close();
}
$deliveryFeeStmt->close();

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Order Management Admin Portal - Edit Order #<?= htmlspecialchars($order_id) ?></title>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>
    <link rel="stylesheet" href="../assets/css/styles.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/alert.css" id="main-style-link" />
    <style>
        .autocomplete-suggestions {
            position: absolute;
            background: white;
            border: 1px solid #ddd;
            border-top: none;
            max-height: 200px;
            overflow-y: auto;
            width: 100%;
            z-index: 1000;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            display: none;
            width: max-content;
            max-width: 100%;
            white-space: nowrap;
        }
        .autocomplete-suggestion {
            padding: 10px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
        }
        .autocomplete-suggestion:hover {
            background-color: #f5f5f5;
        }
        .autocomplete-suggestion:last-child {
            border-bottom: none;
        }
        .autocomplete-suggestion.active {
            background-color: #e9ecef;
        }
        .no-results {
            padding: 10px;
            color: #999;
            text-align: center;
        }
        .quantity-col {
            width: 100px;
            min-width: 80px;
        }
        .quantity-col input {
            text-align: center;
            font-weight: 500;
        }
        .row-highlight {
            background-color: #fff3cd !important;
            transition: background-color 0.5s ease;
        }
        #product-alert-container {
            margin-bottom: 15px;
        }
        .duplicate-product-alert {
            display: flex;
            align-items: center;
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 12px 15px;
            border-radius: 4px;
            position: relative;
            margin-bottom: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .duplicate-product-alert .alert-icon {
            font-size: 20px;
            margin-right: 15px;
        }
        .duplicate-product-alert .alert-message {
            flex-grow: 1;
            color: #856404;
            font-size: 13px;
            line-height: 1.4;
        }
        .duplicate-product-alert .alert-close {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: #856404;
            opacity: 0.5;
            transition: opacity 0.2s;
            padding: 0;
            margin-left: 10px;
        }
        .duplicate-product-alert .alert-close:hover {
            opacity: 1;
        }
    </style>
</head>

<body>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/loader.php'); ?>

    <div class="pc-container">
        <div class="pc-content">
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title" style="display: flex; justify-content: space-between; align-items: flex-start;">
                        <h5 class="mb-0 font-medium">Edit Order #<?= htmlspecialchars($order_id) ?></h5>
                        <div style="max-width: 400px;">
                            <div class="alert-container">
                                <?php if (!empty($order['upload_error'])): ?>
                                    <div class="alert alert-warning" id="upload-error-alert">
                                        <div>
                                            <span class="alert-icon">⚠️</span>
                                            <span><strong>Upload Error:</strong> <?php echo htmlspecialchars($order['upload_error']); ?></span>
                                        </div>
                                        <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                                    </div>
                                <?php endif; ?>
                                <?php
                                // Display success messages
                                if (isset($_SESSION['order_success'])) {
                                    echo '<div class="alert alert-success" id="success-alert">
                                            <div>
                                                <span class="alert-icon">✅</span>
                                                <span>' . htmlspecialchars($_SESSION['order_success']) . '</span>
                                            </div>
                                            <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                                          </div>';
                                    unset($_SESSION['order_success']);
                                }

                                // Display error messages
                                if (isset($_SESSION['order_error'])) {
                                    echo '<div class="alert alert-error" id="error-alert">
                                            <div>
                                                <span class="alert-icon">❌</span>
                                                <span>' . htmlspecialchars($_SESSION['order_error']) . '</span>
                                            </div>
                                            <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                                          </div>';
                                    unset($_SESSION['order_error']);
                                }

                                // Display warning messages
                                if (isset($_SESSION['order_warning'])) {
                                    echo '<div class="alert alert-warning" id="warning-alert">
                                            <div>
                                                <span class="alert-icon">⚠️</span>
                                                <span>' . htmlspecialchars($_SESSION['order_warning']) . '</span>
                                            </div>
                                            <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                                          </div>';
                                    unset($_SESSION['order_warning']);
                                }

                                // Display info messages
                                if (isset($_SESSION['order_info'])) {
                                    echo '<div class="alert alert-info" id="info-alert">
                                            <div>
                                                <span class="alert-icon">ℹ️</span>
                                                <span>' . htmlspecialchars($_SESSION['order_info']) . '</span>
                                            </div>
                                            <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                                          </div>';
                                    unset($_SESSION['order_info']);
                                }

                                // Display courier status messages
                                if (!empty($courierStatus['error_message'])) {
                                    echo '<div class="alert alert-error">
                                            <div>
                                                <span class="alert-icon">❌</span>
                                                <span>' . htmlspecialchars($courierStatus['error_message']) . '</span>
                                            </div>
                                            <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                                          </div>';
                                }

                                if (!empty($courierStatus['warning_message'])) {
                                    echo '<div class="alert alert-warning">
                                            <div>
                                                <span class="alert-icon">⚠️</span>
                                                <span>' . htmlspecialchars($courierStatus['warning_message']) . '</span>
                                            </div>
                                            <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                                          </div>';
                                }

                                // Display info messages for courier status
                                if (!empty($courierStatus['info_message'])) {
                                    echo '<div class="alert alert-info">
                                            <div>
                                                <span class="alert-icon">ℹ️</span>
                                                <span>' . htmlspecialchars($courierStatus['info_message']) . '</span>
                                            </div>
                                            <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                                          </div>';
                                }
                                ?>

                                <!-- Courier Status Information Card -->
                                <?php if ($courierStatus['has_courier']): ?>
                                <div class="courier-status-card">
                                    <div style="font-size: 11px;">
                                        <?php if ($courierStatus['courier_type'] == 1): ?>
                                            <div>
                                                <span class="status-indicator <?php echo $courierStatus['has_tracking'] ? 'status-active' : 'status-warning'; ?>"></span>
                                                <strong>Courier:</strong> <?php echo htmlspecialchars($courierStatus['courier_name']); ?> (Internal Tracking)
                                            </div>
                                            <div style="margin-top: 5px;">
                                                <strong>Available Tracking Numbers:</strong> 
                                                <?php if ($courierStatus['has_tracking']): ?>
                                                    <span style="color: #28a745;"><?php echo $courierStatus['tracking_count']; ?> unused numbers</span>
                                                <?php else: ?>
                                                    <span style="color: #dc3545;">0 unused numbers - Orders will be pending</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php elseif ($courierStatus['courier_type'] == 2): ?>
                                            <div>
                                                <span class="status-indicator status-active"></span>
                                                <strong>Courier:</strong> <?php echo htmlspecialchars($courierStatus['courier_name']); ?> (API Parcel Courier)
                                            </div>
                                            <div style="margin-top: 5px;">
                                                <strong>Info:</strong> <span style="color: #28a745;">Automatic tracking number generation</span>
                                            </div>
                                        <?php elseif ($courierStatus['courier_type'] == 3): ?>
                                            <div>
                                                <span class="status-indicator status-api"></span>
                                                <strong>Courier:</strong> <?php echo htmlspecialchars($courierStatus['courier_name']); ?> (Existing API Parcel)
                                            </div>
                                            <div style="margin-top: 5px;">
                                                <strong>Info:</strong> <span style="color: #17a2b8;">Integrated with existing API parcel system</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <div class="order-container">
                <form method="post" action="process_edit_order.php" id="orderForm">
                    <input type="hidden" name="order_id" value="<?= htmlspecialchars($order_id) ?>">
                    
                    <!-- Order Details Section -->
                    <div class="order-details-section">
                        <div class="order-details-grid">
                            <div class="form-group">
                                <label class="form-label">Status</label>
                                <div class="status-radio-group">
                                    <div class="radio-option">
                                        <input type="radio" name="order_status" value="Paid" id="status_paid" <?= $order['pay_status'] == 'paid' ? 'checked' : '' ?>>
                                        <label for="status_paid">Paid</label>
                                    </div>
                                    <div class="radio-option">
                                        <input type="radio" name="order_status" value="Unpaid" id="status_unpaid" <?= $order['pay_status'] == 'unpaid' ? 'checked' : '' ?>>
                                        <label for="status_unpaid">Unpaid</label>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Order Date</label>
                                <input type="date" class="form-control" name="order_date" value="<?= date('Y-m-d', strtotime($order['issue_date'])) ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Due Date</label>
                                <input type="date" class="form-control" name="due_date" value="<?= date('Y-m-d', strtotime($order['due_date'])) ?>" required>
                            </div>
                        </div>
                        <input type="hidden" name="order_currency" id="order_currency" value="<?= htmlspecialchars($order['currency']) ?>">
                    </div>

                    <!-- Customer Information Section -->
                    <div class="section-card">
                        <div class="section-header" style="display:flex; align-items:center; width:100%;">
                            <h5 class="section-title">Customer Information</h5>
                            <button type="button" class="btn-outline-primary" id="select_existing_customer" style="margin-left:auto;">
                                <i class="feather icon-users"></i> Select Customer
                            </button>
                        </div>
                        <div class="section-body">
                            <div class="customer-info-grid">
                                <input type="hidden" name="customer_id" id="customer_id" value="<?= $order['customer_id'] ?>">
                                
                                <div class="form-group">
                                    <label class="form-label">Name <span style="color: #dc3545;">*</span></label>
                                    <input type="text" class="form-control" name="customer_name" id="customer_name" value="<?= htmlspecialchars($order['full_name']) ?>" required placeholder="Enter Full Name">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Email</label>
                                    <input type="email" class="form-control" name="customer_email" id="customer_email" value="<?= htmlspecialchars($order['email'] ?? '') ?>" placeholder="example@email.com">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Phone</label>
                                    <input type="text" class="form-control" name="customer_phone" id="customer_phone" value="<?= htmlspecialchars($order['mobile']) ?>" placeholder="(07) xxxx xxxx">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Phone 2</label>
                                    <input type="text" class="form-control" name="customer_phone_2" id="customer_phone_2" value="<?= htmlspecialchars($order['mobile_2'] ?? '') ?>" placeholder="(07) xxxx xxxx">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">City</label>
                                    <input type="text" class="form-control" id="city_autocomplete" value="<?= htmlspecialchars($order['city_name'] ?? '') ?>" placeholder="Start typing city name..." autocomplete="off">
                                    <input type="hidden" name="city_id" id="city_id" value="<?= $order['city_id'] ?>">
                                    <div id="city_suggestions" class="autocomplete-suggestions"></div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Address Line 1</label>
                                    <input type="text" class="form-control" name="address_line1" id="address_line1" value="<?= htmlspecialchars($order['address_line1']) ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Address Line 2</label>
                                    <input type="text" class="form-control" name="address_line2" id="address_line2" value="<?= htmlspecialchars($order['address_line2'] ?? '') ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Products Section -->
                    <div class="section-card">
                        <div class="section-header">
                            <h5 class="section-title">Products</h5>
                        </div>
                        <div class="section-body">
                            <div id="product-alert-container"></div>
                            <div style="overflow-x: auto;">
                                <table class="products-table" id="order_table">
                                    <thead>
                                        <tr>
                                            <th class="action-col">Action</th>
                                            <th class="product-col">Product</th>
                                            <th class="description-col">Description</th>
                                            <th class="quantity-col">Quantity</th>
                                            <th class="price-col">Price</th>
                                            <th class="discount-col">Discount</th>
                                            <th class="subtotal-col">Subtotal</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($order_items)): ?>
                                            <?php foreach ($order_items as $item): ?>
                                                <tr>
                                                    <td class="action-col">
                                                        <button type="button" class="btn-remove remove_product">×</button>
                                                    </td>
                                                    <td class="product-col">
                                                        <input type="hidden" name="order_item_id[]" value="<?= $item['item_id'] ?? '' ?>">
                                                        <select name="order_product[]" class="form-select product-select">
                                                            <option value="">-- Select Product --</option>
                                                            <?php
                                                            $productsResult->data_seek(0);
                                                            while ($p = $productsResult->fetch_assoc()): 
                                                            ?>
                                                                <option value="<?= $p['id'] ?>"
                                                                    data-lkr-price="<?= $p['lkr_price'] ?>"
                                                                    data-description="<?= htmlspecialchars($p['description']) ?>"
                                                                    data-base-name="<?= htmlspecialchars($p['name']) ?>"
                                                                    <?= $p['id'] == $item['product_id'] ? 'selected' : '' ?>>
                                                                    <?= htmlspecialchars($p['name']) ?>
                                                                </option>
                                                            <?php endwhile; ?>
                                                        </select>
                                                    </td>
                                                    <td class="description-col">
                                                        <input type="text" name="order_product_description[]" class="form-control product-description" value="<?= htmlspecialchars($item['description']) ?>">
                                                    </td>
                                                    <td class="quantity-col">
                                                        <input type="number" name="order_product_quantity[]" class="form-control quantity" value="<?= (int)$item['quantity'] ?>" min="1" step="1">
                                                    </td>
                                                    <td class="price-col">
                                                        <div class="input-group">
                                                            <span class="input-group-text">Rs.</span>
                                                            <input type="number" name="order_product_price[]" class="form-control price" value="<?= number_format($item['unit_price'], 2, '.', '') ?>" step="0.01">
                                                        </div>
                                                    </td>
                                                    <td class="discount-col">
                                                        <input type="number" name="order_product_discount[]" class="form-control discount" value="<?= number_format($item['discount'], 2, '.', '') ?>" placeholder="0.00" step="0.01">
                                                    </td>
                                                    <td class="subtotal-col">
                                                        <div class="input-group">
                                                            <span class="input-group-text">Rs.</span>
                                                            <input type="text" name="order_product_sub[]" class="form-control subtotal" value="<?= number_format($item['total_amount'], 2, '.', '') ?>" readonly>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td class="action-col">
                                                    <button type="button" class="btn-remove remove_product">×</button>
                                                </td>
                                                <td class="product-col">
                                                    <select name="order_product[]" class="form-select product-select">
                                                        <option value="">-- Select Product --</option>
                                                        <?php
                                                        $productsResult->data_seek(0);
                                                        while ($p = $productsResult->fetch_assoc()): 
                                                        ?>
                                                            <option value="<?= $p['id'] ?>"
                                                                data-lkr-price="<?= $p['lkr_price'] ?>"
                                                                data-description="<?= htmlspecialchars($p['description']) ?>"
                                                                data-base-name="<?= htmlspecialchars($p['name']) ?>">
                                                                <?= htmlspecialchars($p['name']) ?>
                                                            </option>
                                                        <?php endwhile; ?>
                                                    </select>
                                                </td>
                                                <td class="description-col">
                                                    <input type="text" name="order_product_description[]" class="form-control product-description">
                                                </td>
                                                <td class="quantity-col">
                                                    <input type="number" name="order_product_quantity[]" class="form-control quantity" value="1" min="1" step="1" disabled>
                                                </td>
                                                <td class="price-col">
                                                    <div class="input-group">
                                                        <span class="input-group-text">Rs.</span>
                                                        <input type="number" name="order_product_price[]" class="form-control price" value="0.00" step="0.01" disabled>
                                                    </div>
                                                </td>
                                                <td class="discount-col">
                                                    <input type="number" name="order_product_discount[]" class="form-control discount" placeholder="0.00" step="0.01" disabled>
                                                </td>
                                                <td class="subtotal-col">
                                                    <div class="input-group">
                                                        <span class="input-group-text">Rs.</span>
                                                        <input type="text" name="order_product_sub[]" class="form-control subtotal" value="0.00" readonly>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 20px;">
                                <button type="button" id="add_product" class="btn-add-product">
                                    <span>+</span> Add Product
                                </button>

                                <div class="totals-section">
                                    <div class="totals-row">
                                        <span class="totals-label">Subtotal:</span>
                                        <span class="totals-value">
                                            Rs. <span id="subtotal_display"><?= number_format($order['subtotal'], 2) ?></span>
                                            <input type="hidden" id="subtotal_amount" name="subtotal" value="<?= $order['subtotal'] ?>">
                                        </span>
                                    </div>
                                    <div class="totals-row">
                                        <span class="totals-label">Discount:</span>
                                        <span class="totals-value">
                                            Rs. <span id="discount_display"><?= number_format($order['discount'], 2) ?></span>
                                            <input type="hidden" id="discount_amount" name="discount" value="<?= $order['discount'] ?>">
                                        </span>
                                    </div>
                                    <div class="totals-row delivery-fee-row" id="delivery_fee_row">
                                        <span class="totals-label">Delivery Fee:</span>
                                        <span class="totals-value">
                                            Rs. <span id="delivery_fee_display"><?= number_format($order['delivery_fee'], 2) ?></span>
                                            <input type="hidden" id="delivery_fee" name="delivery_fee" value="<?= $order['delivery_fee'] ?>">
                                        </span>
                                    </div>
                                    <div class="totals-row">
                                        <span class="totals-label">Total:</span>
                                        <span class="totals-value">
                                            Rs. <span id="total_display"><?= number_format($order['total_amount'], 2) ?></span>
                                            <input type="hidden" id="total_amount" name="total_amount" value="<?= $order['total_amount'] ?>">
                                            <input type="hidden" id="lkr_total_amount" name="lkr_price" value="<?= $order['total_amount'] ?>">
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Notes & Submit Section -->
                    <div class="section-card">
                        <div class="section-body">
                            <div class="notes-section">
                                <label class="form-label">Additional Notes</label>
                                <textarea name="notes" id="notes" class="form-control" rows="3" placeholder="Enter any additional notes for this order..."><?= htmlspecialchars($order['notes']) ?></textarea>
                            </div>
                            <div class="submit-section">
                                <button type="submit" class="btn-primary" id="submit_order">
                                    <i class="feather icon-save"></i> Update Order
                                </button>
                                <a href="pending_order_list.php" class="btn btn-secondary" style="margin-left: 10px; padding: 9px 30px; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; border: 1px solid #ddd; background: #fff; color: #333; font-size: 14px; font-weight: 500;">Cancel</a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Customer Selection Modal (same as create_order.php) -->
    <div id="customerModal" class="customer-modal">
        <div class="customer-modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="feather icon-users"></i> Select Customer</h5>
                <button type="button" class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="input-group" style="margin-bottom: 20px;">
                    <span class="input-group-text"><i class="feather icon-search"></i></span>
                    <input type="text" id="customerSearch" class="form-control" placeholder="Search : Customer id | Customer Name | Email | Phone Number | city ">
                </div>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>CUSTOMER NAME</th>
                                <th>PHONE & EMAIL</th>
                                <th>ADDRESS</th>
                                <th>ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $customersResult->data_seek(0);
                            while ($customer = $customersResult->fetch_assoc()): ?>
                                <tr class="customer-row" 
                                    data-customer-id="<?= $customer['customer_id'] ?? '' ?>"
                                    data-name="<?= htmlspecialchars($customer['name'] ?? '') ?>"
                                    data-email="<?= htmlspecialchars($customer['email'] ?? '') ?>"
                                    data-phone="<?= htmlspecialchars($customer['phone'] ?? '') ?>"
                                    data-phone-2="<?= htmlspecialchars($customer['phone_2'] ?? '') ?>"
                                    data-address-line1="<?= htmlspecialchars($customer['address_line1'] ?? '') ?>"
                                    data-address-line2="<?= htmlspecialchars($customer['address_line2'] ?? '') ?>"
                                    data-city-name="<?= htmlspecialchars($customer['city_name'] ?? '') ?>"
                                    data-city-id="<?= $customer['city_id'] ?? '' ?>">
                                    <td><?= $customer['customer_id'] ?? '' ?></td>
                                    <td><div class="customer-name"><?= htmlspecialchars($customer['name'] ?? '') ?></div></td>
                                    <td>
                                        <div class="contact-info">
                                            <div class="phone-number"><?= htmlspecialchars($customer['phone'] ?? '') ?></div>
                                            <?php if (!empty($customer['phone_2'])): ?>
                                                <div class="phone-number-2" style="color: #6c757d; font-size: 0.9em;"><?= htmlspecialchars($customer['phone_2']) ?></div>
                                            <?php endif; ?>
                                            <div class="email-address"><?= htmlspecialchars($customer['email'] ?? '') ?></div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="address-info">
                                            <div class="address-line"><?= htmlspecialchars($customer['address_line1'] ?? '') ?></div>
                                            <div class="city-name"><?= htmlspecialchars($customer['city_name'] ?? '') ?></div>
                                        </div>
                                    </td>
                                    <td><button type="button" class="btn btn-primary select-customer-btn">Select</button></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php'); ?>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php'); ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // ========== GLOBAL VARIABLES ==========
            let deliveryFee = <?php echo $defaultDeliveryFee; ?>;
            let isExistingCustomer = <?= $order['customer_id'] ? 'true' : 'false' ?>;

    // ========== VALIDATION UTILITIES ==========
    const ValidationUtils = {
        isValidEmail: (email) => /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email),
        isValidPhone: (phone) => /^\d{10}$/.test(phone),
        isValidDate: (dateString) => {
            const date = new Date(dateString);
            return date instanceof Date && !isNaN(date) && dateString === date.toISOString().split('T')[0];
        },
        
        showError: (element, message, className = 'validation-error') => {
            const errorDiv = document.createElement('div');
            errorDiv.className = className;
            errorDiv.style.color = '#dc3545';
            errorDiv.style.fontSize = '0.875rem';
            errorDiv.style.marginTop = '0.25rem';
            errorDiv.textContent = message;
            element.parentNode.appendChild(errorDiv);
        },
        
        clearErrors: (className = 'validation-error') => {
            document.querySelectorAll(`.${className}`).forEach(el => el.remove());
        }
    };

// ========== PHONE VALIDATION MODULE ==========
const PhoneValidator = {
    timeouts: {},
    
    // Check if phone number exists in database
    checkPhoneExists: async (phone, currentCustomerId = 0) => {
        if (!phone || phone.length !== 10) return { exists: false };
        
        try {
            const response = await fetch(`check_phone.php?phone=${encodeURIComponent(phone)}&customer_id=${currentCustomerId}`);
            return await response.json();
        } catch (error) {
            console.error('Error checking phone:', error);
            return { exists: false };
        }
    },
    
    getCustomerByPhone: async (phone) => {
        if (!phone || phone.length !== 10) return { exists: false };
        
        try {
            const response = await fetch(`get_customer_by_phone.php?phone=${encodeURIComponent(phone)}`);
            return await response.json();
        } catch (error) {
            console.error('Error getting customer by phone:', error);
            return { exists: false };
        }
    },
    
    // Validate phone field with debouncing
    validatePhoneField: (fieldId, otherFieldId) => {
        const field = document.getElementById(fieldId);
        const otherField = document.getElementById(otherFieldId);
        
        // Clear existing timeout
        if (PhoneValidator.timeouts[fieldId]) {
            clearTimeout(PhoneValidator.timeouts[fieldId]);
        }
        
        // Remove previous error/success for this field
        const existingError = field.parentNode.querySelector('.phone-validation-error');
        if (existingError) existingError.remove();
        const existingSuccess = field.parentNode.querySelector('.phone-validation-success');
        if (existingSuccess) existingSuccess.remove();
        
        const phone = field.value.trim();
        const otherPhone = otherField.value.trim();
        
        // Check if empty (allowed for phone_2)
        if (!phone) {
            FormValidator.validateAndToggleSubmit();
            return;
        }
        
        // Check if same as other field
        if (phone === otherPhone && phone.length === 10) {
            ValidationUtils.showError(field, 'Phone numbers cannot be the same', 'phone-validation-error');
            FormValidator.validateAndToggleSubmit();
            return;
        }
        
        // Check format
        if (phone.length !== 10) {
            FormValidator.validateAndToggleSubmit();
            return;
        }
        
        // Debounced database check
        PhoneValidator.timeouts[fieldId] = setTimeout(async () => {
            const currentCustomerId = document.getElementById('customer_id').value || 0;
            const customerResult = await PhoneValidator.getCustomerByPhone(phone);
            
            if (customerResult.exists && customerResult.customer && customerResult.customer.customer_id != currentCustomerId) {
                isExistingCustomer = true;
            }
            
            FormValidator.validateAndToggleSubmit();
        }, 500);
    }
};

// ========== EMAIL VALIDATION WITH AUTO-FILL MODULE ==========
const EmailValidator = {
    timeout: null,

    // Validate email format using regex
    isValidFormat: (email) => {
        const emailRegex = /^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
        return emailRegex.test(email);
    },

    checkEmailExists: async (email, customerId = 0) => {
        if (!EmailValidator.isValidFormat(email)) return { exists: false };

        try {
            const response = await fetch(
                `check_email.php?email=${encodeURIComponent(email)}&customer_id=${customerId}`
            );
            return await response.json();
        } catch (err) {
            console.error('Email check error:', err);
            return { exists: false };
        }
    },

    // Get customer data by email
    getCustomerByEmail: async (email) => {
        try {
            const response = await fetch(
                `get_customer_by_email.php?email=${encodeURIComponent(email)}`
            );
            return await response.json();
        } catch (err) {
            console.error('Get customer error:', err);
            return { exists: false };
        }
    },

    validateEmailField: () => {
        const field = document.getElementById('customer_email');

        // Clear old errors and success messages
        const oldError = field.parentNode.querySelector('.email-validation-error');
        if (oldError) oldError.remove();
        
        const oldSuccess = field.parentNode.querySelector('.email-validation-success');
        if (oldSuccess) oldSuccess.remove();

        const email = field.value.trim();
        
        // If email is empty, just validate form
        if (!email) {
            FormValidator.validateAndToggleSubmit();
            return;
        }

        // First check: Email format validation
        if (!EmailValidator.isValidFormat(email)) {
            ValidationUtils.showError(
                field,
                'Please enter a valid email address (e.g., name@example.com)',
                'email-validation-error'
            );
            FormValidator.validateAndToggleSubmit();
            return;
        }

        // Second check: Look up customer by email (with debounce)
        clearTimeout(EmailValidator.timeout);

        EmailValidator.timeout = setTimeout(async () => {
            const currentCustomerId = document.getElementById('customer_id').value || 0;
            
            const customerResult = await EmailValidator.getCustomerByEmail(email);
            
            if (customerResult.exists && customerResult.customer && customerResult.customer.customer_id != currentCustomerId) {
                isExistingCustomer = true;
            }

            FormValidator.validateAndToggleSubmit();
        }, 500);
    }
};

// ========== CUSTOMER MANAGER ==========
const CustomerManager = {
    toggleFields: (readonly = false) => {
        const fields = ['customer_name', 'customer_email', 'customer_phone', 'customer_phone_2', 'city_autocomplete', 'address_line1', 'address_line2'];
        fields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                field.readOnly = readonly;
                field.style.backgroundColor = readonly ? '#f8f9fa' : '';
                field.style.cursor = readonly ? 'not-allowed' : '';
            }
        });
    },

    clearFields: () => {
        document.getElementById('customer_id').value = '';
        document.getElementById('customer_name').value = '';
        document.getElementById('customer_email').value = '';
        document.getElementById('customer_phone').value = '';
        document.getElementById('customer_phone_2').value = '';
        document.getElementById('city_id').value = '';
        document.getElementById('city_autocomplete').value = '';
        document.getElementById('address_line1').value = '';
        document.getElementById('address_line2').value = '';
        ValidationUtils.clearErrors();
        ValidationUtils.clearErrors('phone-validation-error');
        ValidationUtils.clearErrors('email-validation-error');
        isExistingCustomer = false;
        CustomerManager.toggleFields(false);
        FormValidator.validateAndToggleSubmit();
    },

    validate: () => {
        const name = document.getElementById('customer_name').value.trim();
        const email = document.getElementById('customer_email').value.trim();
        const phone = document.getElementById('customer_phone').value.trim();
        const phone2 = document.getElementById('customer_phone_2').value.trim();
        const cityId = document.getElementById('city_id').value;
        const address = document.getElementById('address_line1').value.trim();

        let isValid = true;

        // Name is always required
        if (!name) {
            ValidationUtils.showError(document.getElementById('customer_name'), 'Customer name is required');
            isValid = false;
        }

        // Check for phone validation errors
        const phoneErrors = document.querySelectorAll('.phone-validation-error');
        if (phoneErrors.length > 0) {
            isValid = false;
        }

        // Check for email validation errors
        const emailErrors = document.querySelectorAll('.email-validation-error');
        if (emailErrors.length > 0) {
            isValid = false;
        }

        // Check if phones are the same
        if (phone && phone2 && phone === phone2 && phone.length === 10) {
            const phone2Field = document.getElementById('customer_phone_2');
            const existingError = phone2Field.parentNode.querySelector('.phone-validation-error');
            if (!existingError) {
                ValidationUtils.showError(phone2Field, 'Phone numbers cannot be the same', 'phone-validation-error');
            }
            isValid = false;
        }

        // For new customers or edited customers, basic fields required
        if (!phone) {
            ValidationUtils.showError(document.getElementById('customer_phone'), 'Phone number is required');
            isValid = false;
        } else if (!ValidationUtils.isValidPhone(phone)) {
            ValidationUtils.showError(document.getElementById('customer_phone'), 'Phone number must be 10 digits');
            isValid = false;
        }

        // Validate phone_2 ONLY if value is entered
        if (phone2 && !ValidationUtils.isValidPhone(phone2)) {
            ValidationUtils.showError(document.getElementById('customer_phone_2'), 'Phone 2 must be 10 digits');
            isValid = false;
        }

        if (!cityId) {
            ValidationUtils.showError(document.getElementById('city_autocomplete'), 'City is required');
            isValid = false;
        }

        if (!address) {
            ValidationUtils.showError(document.getElementById('address_line1'), 'Address Line 1 is required');
            isValid = false;
        }

        return isValid;
    }
};

    // ========== DATE VALIDATION ==========
    const DateValidator = {
        validate: () => {
            const orderDate = document.querySelector('input[name="order_date"]').value;
            const dueDate = document.querySelector('input[name="due_date"]').value;
            const today = new Date().toISOString().split('T')[0];

            ValidationUtils.clearErrors('date-validation-error');
            let isValid = true;

            if (!orderDate) {
                ValidationUtils.showError(document.querySelector('input[name="order_date"]'), 'Order date is required', 'date-validation-error');
                isValid = false;
            } else if (!ValidationUtils.isValidDate(orderDate)) {
                ValidationUtils.showError(document.querySelector('input[name="order_date"]'), 'Invalid order date format', 'date-validation-error');
                isValid = false;
            }

            if (!dueDate) {
                ValidationUtils.showError(document.querySelector('input[name="due_date"]'), 'Due date is required', 'date-validation-error');
                isValid = false;
            } else if (!ValidationUtils.isValidDate(dueDate)) {
                ValidationUtils.showError(document.querySelector('input[name="due_date"]'), 'Invalid due date format', 'date-validation-error');
                isValid = false;
            } else if (orderDate && dueDate < orderDate) {
                ValidationUtils.showError(document.querySelector('input[name="due_date"]'), 'Due date cannot be earlier than order date', 'date-validation-error');
                isValid = false;
            }

            return isValid;
        }
    };

// ========== PRODUCT MANAGEMENT ==========
const ProductManager = {
    updatePrice: (row) => {
        const productSelect = row.querySelector('.product-select');
        const selectedValue = productSelect.value;
        const selectedOption = productSelect.options[productSelect.selectedIndex];
        
        if (!selectedValue) {
            // Clear fields if no product is selected
            row.querySelector('.product-description').value = '';
            row.querySelector('.price').value = '0.00';
            row.querySelector('.quantity').value = '1';
            row.querySelector('.discount').value = '0';
            row.querySelector('.subtotal').value = '0.00';
            
            // Disable fields
            row.querySelector('.quantity').disabled = true;
            row.querySelector('.price').disabled = true;
            row.querySelector('.discount').disabled = true;
            
            ProductManager.updateTotals();
            FormValidator.validateAndToggleSubmit();
            return;
        }

        const productName = selectedOption.text;

        // Check for duplicates
        let existingRow = null;
        const allRows = document.querySelectorAll('#order_table tbody tr');
        
        for (let tr of allRows) {
            const select = tr.querySelector('.product-select');
            if (tr !== row && select && select.value && select.value === selectedValue) {
                existingRow = tr;
                break;
            }
        }

        if (existingRow) {
            ProductManager.showDuplicateAlert(productName, existingRow);

            // Clear new row selection
            productSelect.value = '';
            row.querySelector('.product-description').value = '';
            row.querySelector('.price').value = '0.00';
            row.querySelector('.quantity').value = '1';
            row.querySelector('.discount').value = '0';
            row.querySelector('.subtotal').value = '0.00';

            FormValidator.validateAndToggleSubmit();
            return;
        }

        const priceField = row.querySelector('.price');
        const descriptionField = row.querySelector('.product-description');
        const quantityInput = row.querySelector('.quantity');
        const description = selectedOption.getAttribute('data-description') || '';
        const price = parseFloat(selectedOption.getAttribute('data-lkr-price') || 0);

        priceField.value = isNaN(price) ? '0.00' : price.toFixed(2);
        descriptionField.value = description;

        // Enable fields
        quantityInput.disabled = false;
        quantityInput.removeAttribute('max');

        priceField.disabled = false;
        row.querySelector('.discount').disabled = false;
        descriptionField.disabled = false;

        ProductManager.updateRowTotal(row);
        ProductManager.checkForProducts();
    },

    showDuplicateAlert: (productName, existingRow) => {
        const alertContainer = document.getElementById('product-alert-container');
        
        // Remove any existing alerts
        alertContainer.innerHTML = '';
        
        // Create new alert
        const alertDiv = document.createElement('div');
        alertDiv.className = 'duplicate-product-alert';
        alertDiv.innerHTML = `
            <span class="alert-icon">⚠️</span>
            <div class="alert-message">
                <strong>Product Already Added!</strong><br>
                "${productName}" is already in your order. Please increase the quantity of the existing item instead of adding it again.
            </div>
            <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
        `;
        
        alertContainer.appendChild(alertDiv);
        
        // Highlight existing row
        if (existingRow) {
            existingRow.style.backgroundColor = '#fff3cd';
            existingRow.style.transition = 'background-color 0.3s';
            
            existingRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
            
            setTimeout(() => {
                existingRow.style.backgroundColor = '';
            }, 3000);
        }
        
        setTimeout(() => {
            if (alertDiv.parentElement) {
                alertDiv.style.opacity = '0';
                alertDiv.style.transition = 'opacity 0.3s';
                setTimeout(() => alertDiv.remove(), 300);
            }
        }, 5000);
    },

    updateRowTotal: (row) => {
        let price = parseFloat(row.querySelector('.price').value) || 0;
        let discount = parseFloat(row.querySelector('.discount').value) || 0;

        // Handle Quantity - ensure it is at least 1
        const qtyInput = row.querySelector('.quantity');
        const productSelect = row.querySelector('.product-select');
        const selectedOption = productSelect.options[productSelect.selectedIndex];
        
        if (qtyInput.value !== "" && parseInt(qtyInput.value) < 1) {
            qtyInput.value = 1;
        }
        
        let quantity = parseInt(qtyInput.value) || 1;

        qtyInput.removeAttribute('max');

        if (discount > (price * quantity)) {
            discount = price * quantity;
            row.querySelector('.discount').value = discount.toFixed(2);
        }

        let subtotal = (price * quantity) - discount;
        row.querySelector('.subtotal').value = subtotal.toFixed(2);
        ProductManager.updateTotals();
    },

    checkForProducts: () => {
        let hasProducts = false;
        document.querySelectorAll('#order_table tbody tr').forEach(row => {
            const productSelect = row.querySelector('.product-select');
            if (productSelect && productSelect.value !== "") {
                hasProducts = true;
            }
        });

        const deliveryFeeRow = document.getElementById('delivery_fee_row');
        if (deliveryFeeRow) deliveryFeeRow.style.display = hasProducts ? 'flex' : 'none';
        
        const addProductBtn = document.getElementById('add_product');
        if (addProductBtn) {
            addProductBtn.disabled = !hasProducts;
            addProductBtn.style.opacity = hasProducts ? '1' : '0.6';
            addProductBtn.style.cursor = hasProducts ? 'pointer' : 'not-allowed';
        }
        
        return hasProducts;
    },

    updateTotals: () => {
        let subtotalGross = 0;
        let totalDiscount = 0;

        document.querySelectorAll('#order_table tbody tr').forEach(row => {
            let rowPrice = parseFloat(row.querySelector('.price').value) || 0;
            let rowQuantity = parseInt(row.querySelector('.quantity').value) || 1;
            let rowDiscount = parseFloat(row.querySelector('.discount').value) || 0;

            if (rowDiscount > (rowPrice * rowQuantity)) {
                rowDiscount = (rowPrice * rowQuantity);
                row.querySelector('.discount').value = rowDiscount.toFixed(2);
            }

            let rowSubtotal = (rowPrice * rowQuantity) - rowDiscount;
            row.querySelector('.subtotal').value = rowSubtotal.toFixed(2);

            if (row.querySelector('.product-select').value !== '') {
                subtotalGross += (rowPrice * rowQuantity);
                totalDiscount += rowDiscount;
            }
        });

        const hasProducts = ProductManager.checkForProducts();
        let finalDeliveryFee = 0;
        let subtotalAfterDiscount = subtotalGross - totalDiscount;

        if (hasProducts) {
                finalDeliveryFee = deliveryFee;
                document.getElementById('delivery_fee_display').textContent = deliveryFee.toFixed(2);
        } else {
            document.getElementById('delivery_fee_display').textContent = '0.00';
        }

        document.getElementById('delivery_fee').value = finalDeliveryFee.toFixed(2);
        let total = subtotalAfterDiscount + finalDeliveryFee;

        document.getElementById('subtotal_display').textContent = subtotalGross.toFixed(2);
        document.getElementById('subtotal_amount').value = subtotalGross.toFixed(2);
        document.getElementById('discount_display').textContent = totalDiscount.toFixed(2);
        document.getElementById('discount_amount').value = totalDiscount.toFixed(2);
        document.getElementById('total_display').textContent = total.toFixed(2);
        document.getElementById('total_amount').value = total.toFixed(2);
        document.getElementById('lkr_total_amount').value = total.toFixed(2);
    },

    validate: () => {
        ValidationUtils.clearErrors('product-validation-error');
        let isValid = true;

        document.querySelectorAll('#order_table tbody tr').forEach(row => {
            const productSelect = row.querySelector('.product-select');
            const descriptionInput = row.querySelector('.product-description');
            const priceInput = row.querySelector('.price');

            if (productSelect.value !== '') {
                if (!descriptionInput.value.trim()) {
                    ValidationUtils.showError(descriptionInput, 'Description required', 'product-validation-error');
                    isValid = false;
                }

                const price = parseFloat(priceInput.value) || 0;
                if (price <= 0) {
                    ValidationUtils.showError(priceInput, 'Price required', 'product-validation-error');
                    isValid = false;
                }
                
                const quantityInput = row.querySelector('.quantity');
                const quantity = parseInt(quantityInput.value) || 0;
                if (quantity < 1) {
                    ValidationUtils.showError(quantityInput, 'Qty required', 'product-validation-error');
                    isValid = false;
                }
            }
        });

        return isValid;
    },

    hasValidProduct: () => {
        let hasValid = false;
        document.querySelectorAll('#order_table tbody tr').forEach(row => {
            const productSelect = row.querySelector('.product-select');
            const descriptionInput = row.querySelector('.product-description');
            const priceInput = row.querySelector('.price');
            const price = parseFloat(priceInput.value) || 0;

            if (productSelect.value !== '' && descriptionInput.value.trim() !== '' && price > 0) {
                hasValid = true;
            }
        });
        return hasValid;
    },

    addRow: () => {
        let newRow = document.querySelector('#order_table tbody tr').cloneNode(true);
        newRow.querySelectorAll('input').forEach(input => {
            if (input.classList.contains('price')) input.value = '0.00';
            else if (input.classList.contains('discount')) input.value = '0';
            else if (input.classList.contains('quantity')) input.value = '1';
            else if (input.classList.contains('subtotal')) input.value = '0.00';
            else if (input.name === 'order_item_id[]') input.value = ''; // Clear item_id for new rows
            else input.value = '';
        });
        
        // Ensure specific fields in new row are disabled initially until product selected
        newRow.querySelector('.quantity').disabled = true;
        newRow.querySelector('.price').disabled = true;
        newRow.querySelector('.discount').disabled = true;
        newRow.querySelector('.subtotal').readOnly = true;

        newRow.querySelector('.product-select').value = '';
        document.querySelector('#order_table tbody').appendChild(newRow);
    },

    removeRow: (button) => {
        const tableBody = document.querySelector('#order_table tbody');
        if (tableBody.children.length > 1) {
            button.closest('tr').remove();
            ProductManager.checkForProducts();
            ProductManager.updateTotals();
        } else {
            let row = button.closest('tr');
            row.querySelector('.product-select').value = '';
            row.querySelector('.product-description').value = '';
            row.querySelector('.price').value = '0.00';
            row.querySelector('.quantity').value = '1';
            row.querySelector('.discount').value = '0';
            row.querySelector('.subtotal').value = '0.00';
            row.querySelector('.quantity').disabled = true;
            row.querySelector('.price').disabled = true;
            row.querySelector('.discount').disabled = true;
            ProductManager.checkForProducts();
            ProductManager.updateTotals();
        }
        FormValidator.validateAndToggleSubmit();
    },

};

// ========== FORM TRACKER ==========
const FormTracker = {
    initialState: null,
    
    getFormData: () => {
        const form = document.getElementById('orderForm');
        if (!form) return '';
        const formData = new FormData(form);
        const data = {};
        for (let [key, value] of formData.entries()) {
            if (data[key]) {
                if (!Array.isArray(data[key])) data[key] = [data[key]];
                data[key].push(value);
            } else {
                data[key] = value;
            }
        }
        return JSON.stringify(data);
    },

    init: () => {
        FormTracker.initialState = FormTracker.getFormData();
    },

    hasChanges: () => {
        if (!FormTracker.initialState) return false;
        return FormTracker.getFormData() !== FormTracker.initialState;
    }
};

// ========== FORM VALIDATOR ==========
const FormValidator = {
    validateAndToggleSubmit: () => {
        const submitButton = document.getElementById('submit_order');
        ValidationUtils.clearErrors();
        ValidationUtils.clearErrors('date-validation-error');
        ValidationUtils.clearErrors('product-validation-error');

        const customerValid = CustomerManager.validate();
        const datesValid = DateValidator.validate();
        const productsValid = ProductManager.validate();
        const hasValidProducts = ProductManager.hasValidProduct();
        const hasChanges = FormTracker.hasChanges();

        const isFormValid = customerValid && datesValid && productsValid && hasValidProducts && hasChanges;

        submitButton.disabled = !isFormValid;
        submitButton.style.opacity = isFormValid ? '1' : '0.6';
        submitButton.style.cursor = isFormValid ? 'pointer' : 'not-allowed';
        
        // Update button text to indicate if changes are needed
        if (!hasChanges && submitButton.innerHTML.includes('Update Order')) {
            submitButton.title = "No changes made";
        } else {
            submitButton.title = "";
        }

        return isFormValid;
    }
};

    // ========== CITY AUTOCOMPLETE ==========
    const CityAutocomplete = {
        cities: [],
        selectedIndex: -1,
        
        init: () => {
            const cityInput = document.getElementById('city_autocomplete');
            const cityIdInput = document.getElementById('city_id');
            const suggestionsDiv = document.getElementById('city_suggestions');

            fetch('get_cities.php').then(r => r.json()).then(data => CityAutocomplete.cities = data);

            cityInput.addEventListener('input', function() {
                const searchTerm = this.value.trim().toLowerCase();
                if (searchTerm.length === 0) {
                    suggestionsDiv.style.display = 'none';
                    cityIdInput.value = '';
                    FormValidator.validateAndToggleSubmit();
                    return;
                }
                const filteredCities = CityAutocomplete.cities.filter(city => city.city_name.toLowerCase().includes(searchTerm));
                CityAutocomplete.displaySuggestions(filteredCities, suggestionsDiv);
            });

            cityInput.addEventListener('keydown', function(e) {
                const suggestions = document.querySelectorAll('.autocomplete-suggestion');
                if (suggestions.length === 0) return;
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    CityAutocomplete.selectedIndex = (CityAutocomplete.selectedIndex + 1) % suggestions.length;
                    CityAutocomplete.updateSelection(suggestions);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    CityAutocomplete.selectedIndex = CityAutocomplete.selectedIndex <= 0 ? suggestions.length - 1 : CityAutocomplete.selectedIndex - 1;
                    CityAutocomplete.updateSelection(suggestions);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (CityAutocomplete.selectedIndex >= 0 && suggestions[CityAutocomplete.selectedIndex]) {
                        const s = suggestions[CityAutocomplete.selectedIndex];
                        CityAutocomplete.selectCity(s.dataset.cityId, s.dataset.cityName, cityInput, cityIdInput, suggestionsDiv);
                    }
                } else if (e.key === 'Escape') {
                    suggestionsDiv.style.display = 'none';
                    CityAutocomplete.selectedIndex = -1;
                }
            });

            cityInput.addEventListener('blur', function() {
                setTimeout(() => {
                    if (this.value.trim() === '') {
                        cityIdInput.value = '';
                        FormValidator.validateAndToggleSubmit();
                    }
                }, 200);
            });

            document.addEventListener('click', function(e) {
                if (e.target !== cityInput && e.target !== suggestionsDiv) {
                    suggestionsDiv.style.display = 'none';
                    CityAutocomplete.selectedIndex = -1;
                }
            });
        },

        displaySuggestions: (filteredCities, suggestionsDiv) => {
            if (filteredCities.length === 0) {
                suggestionsDiv.innerHTML = '<div class="no-results">No cities found</div>';
                suggestionsDiv.style.display = 'block';
                return;
            }
            let html = filteredCities.map((city, index) => `<div class="autocomplete-suggestion" data-city-id="${city.city_id}" data-city-name="${city.city_name}" data-index="${index}">${city.city_name}</div>`).join('');
            suggestionsDiv.innerHTML = html;
            suggestionsDiv.style.display = 'block';
            CityAutocomplete.selectedIndex = -1;
            document.querySelectorAll('.autocomplete-suggestion').forEach(suggestion => {
                suggestion.addEventListener('click', function() {
                    const cityInput = document.getElementById('city_autocomplete');
                    const cityIdInput = document.getElementById('city_id');
                    CityAutocomplete.selectCity(this.dataset.cityId, this.dataset.cityName, cityInput, cityIdInput, suggestionsDiv);
                });
            });
        },

        selectCity: (cityId, cityName, cityInput, cityIdInput, suggestionsDiv) => {
            cityInput.value = cityName;
            cityIdInput.value = cityId;
            suggestionsDiv.style.display = 'none';
            CityAutocomplete.selectedIndex = -1;
            FormValidator.validateAndToggleSubmit();
        },

        updateSelection: (suggestions) => {
            suggestions.forEach((suggestion, index) => {
                if (index === CityAutocomplete.selectedIndex) {
                    suggestion.classList.add('active');
                    suggestion.scrollIntoView({ block: 'nearest' });
                } else {
                    suggestion.classList.remove('active');
                }
            });
        }
    };

    // ========== CUSTOMER MODAL ==========
    const CustomerModal = {
        init: () => {
            const modal = document.getElementById("customerModal");
            const selectBtn = document.getElementById("select_existing_customer");
            const closeBtn = document.querySelector(".close-modal");
            const searchInput = document.getElementById("customerSearch");

            selectBtn.addEventListener('click', () => modal.style.display = "block");
            closeBtn.addEventListener('click', () => modal.style.display = "none");
            
            window.addEventListener('click', (event) => {
                if (event.target == modal) modal.style.display = "none";
            });

            searchInput.addEventListener('keyup', function() {
                const value = this.value.toLowerCase();
                document.querySelectorAll(".customer-row").forEach(row => {
                    const text = row.textContent || row.innerText;
                    row.style.display = text.toLowerCase().indexOf(value) > -1 ? "" : "none";
                });
            });

            document.querySelectorAll(".select-customer-btn").forEach(btn => {
                btn.addEventListener('click', function() {
                    const row = this.closest('tr');
                    document.getElementById('customer_id').value = row.getAttribute('data-customer-id');
                    document.getElementById('customer_name').value = row.getAttribute('data-name');
                    document.getElementById('customer_email').value = row.getAttribute('data-email');
                    document.getElementById('customer_phone').value = row.getAttribute('data-phone');
                    document.getElementById('customer_phone_2').value = row.getAttribute('data-phone-2') || '';
                    document.getElementById('address_line1').value = row.getAttribute('data-address-line1');
                    document.getElementById('address_line2').value = row.getAttribute('data-address-line2');
                    document.getElementById('city_id').value = row.getAttribute('data-city-id');
                    document.getElementById('city_autocomplete').value = row.getAttribute('data-city-name');
                    isExistingCustomer = true;
                    CustomerManager.toggleFields(false);
                    ValidationUtils.clearErrors();
                    modal.style.display = "none";
                    FormValidator.validateAndToggleSubmit();
                });
            });

            const clearBtn = document.createElement('button');
            clearBtn.type = 'button';
            clearBtn.className = 'btn btn-outline-secondary ml-2';
            clearBtn.innerHTML = '<i class="feather icon-x"></i> Clear Selection';
            clearBtn.style.marginLeft = '10px';
            clearBtn.addEventListener('click', CustomerManager.clearFields);
            selectBtn.parentNode.appendChild(clearBtn);
        }
    };

    // ========== EVENT LISTENERS ==========
    const EventListeners = {
        init: () => {
            ['customer_name', 'customer_email', 'customer_phone', 'address_line1', 'address_line2', 'notes'].forEach(id => {
                document.getElementById(id).addEventListener('input', FormValidator.validateAndToggleSubmit);
            });

            document.querySelector('input[name="order_date"]').addEventListener('change', FormValidator.validateAndToggleSubmit);
            document.querySelector('input[name="due_date"]').addEventListener('change', FormValidator.validateAndToggleSubmit);

            // Add event listeners for paid/unpaid radio buttons
            document.querySelectorAll('input[name="order_status"]').forEach(radio => {
                radio.addEventListener('change', FormValidator.validateAndToggleSubmit);
            });

            document.addEventListener('change', (e) => {
                if (e.target.classList.contains('product-select')) {
                    ProductManager.updatePrice(e.target.closest('tr'));
                    FormValidator.validateAndToggleSubmit();
                }
            });

            document.addEventListener('input', (e) => {
                // if (e.target.classList.contains('discount')) {
                //     e.target.value = e.target.value.replace(/[^0-9.]/g, '');
                //     const parts = e.target.value.split('.');
                //     if (parts.length > 2) e.target.value = parts[0] + '.' + parts.slice(1).join('');
                // }
                if (e.target.classList.contains('price') || e.target.classList.contains('discount') || e.target.classList.contains('quantity')) {
                    if (e.target.classList.contains('quantity') && e.target.value !== "" && parseInt(e.target.value) < 1) e.target.value = 1;
                    ProductManager.updateRowTotal(e.target.closest('tr'));
                    FormValidator.validateAndToggleSubmit();
                }
                
                if (e.target.classList.contains('product-description')) {
                    FormValidator.validateAndToggleSubmit();
                }
            });

            const sanitizePhone = function() {
                this.value = this.value.replace(/[^0-9]/g, '');
                if (this.value.length > 10) {
                    this.value = this.value.slice(0, 10);
                }
                PhoneValidator.validatePhoneField(this.id, this.id === 'customer_phone' ? 'customer_phone_2' : 'customer_phone');
            };

            const handlePhonePaste = function(e) {
                e.preventDefault();
                const pastedText = (e.clipboardData || window.clipboardData).getData('text');
                const numericOnly = pastedText.replace(/[^0-9]/g, '').slice(0, 10);
                this.value = numericOnly;
                PhoneValidator.validatePhoneField(this.id, this.id === 'customer_phone' ? 'customer_phone_2' : 'customer_phone');
            };

            document.getElementById('customer_phone').addEventListener('input', sanitizePhone);
            document.getElementById('customer_phone_2').addEventListener('input', sanitizePhone);
            document.getElementById('customer_phone').addEventListener('paste', handlePhonePaste);
            document.getElementById('customer_phone_2').addEventListener('paste', handlePhonePaste);
            document.getElementById('customer_email').addEventListener('input', () => EmailValidator.validateEmailField());

            document.getElementById('add_product').addEventListener('click', ProductManager.addRow);
            document.addEventListener('click', (e) => {
                if (e.target.classList.contains('remove_product')) ProductManager.removeRow(e.target);
            });

            document.getElementById('orderForm').addEventListener('submit', (e) => {
                if (!FormValidator.validateAndToggleSubmit()) {
                    e.preventDefault();
                    let issues = [];
                    if (!CustomerManager.validate()) issues.push('Customer info');
                    if (!DateValidator.validate()) issues.push('Order dates');
                    if (!ProductManager.validate()) issues.push('Product info');
                    if (!ProductManager.hasValidProduct()) issues.push('At least one complete product');
                    alert('Please fix issues:\n- ' + issues.join('\n- '));
                }
            });
        }
    };

    CityAutocomplete.init();
    CustomerModal.init();
    EventListeners.init();
    ProductManager.updateTotals();
    
    // Initialize tracker AFTER everything is set up
    FormTracker.init();
    FormValidator.validateAndToggleSubmit();
});
</script>

</body>
</html>