<?php
// Start session at the very beginning
session_start();

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /order_management/dist/pages/login.php");
    exit();
}

// Include the database connection file
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/connection/db_connection.php');

// Get user permissions
$is_main_admin = isset($_SESSION['is_main_admin']) ? (int)$_SESSION['is_main_admin'] : 0;
$role_id = isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : 0;
$session_tenant_id = isset($_SESSION['tenant_id']) ? intval($_SESSION['tenant_id']) : 0;

// Determine selected tenant - either from GET parameter or session
$selected_tenant_id = isset($_GET['tenant_id']) ? intval($_GET['tenant_id']) : $session_tenant_id;

// If not main admin, force to use their own tenant
if (!($is_main_admin === 1 && $role_id === 1)) {
    $selected_tenant_id = $session_tenant_id;
}

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
        'courier_id' => 0,
        'has_tracking' => false,
        'tracking_count' => 0,
        'warning_message' => '',
        'error_message' => '',
        'info_message' => ''
    ];
  
    // Get default courier for selected tenant - Updated to use co_id and include status 3
    $courierSql = "SELECT co_id, courier_id, courier_name, api_key, client_id, is_default, status 
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
        $status['courier_id'] = $courier['courier_id'];
        
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
            // New API system
            $status['has_tracking'] = true;
            if (empty($courier['api_key'])) {
                $status['warning_message'] = "Warning: {$courier['courier_name']} API key is missing.";
            }
        } else if ($courier['is_default'] == 3) {
            // Existing API Parcel system
            $status['has_tracking'] = true;
            if (empty($courier['api_key'])) {
                $status['warning_message'] = "Warning: {$courier['courier_name']} API key is missing.";
            }
        }
    } else {
        $status['info_message'] = "No default courier selected for this tenant.";
    }
    
    return $status;
}

// Check courier status for selected tenant
$courierStatus = checkCourierStatus($conn, $selected_tenant_id);

// Fetch necessary data for the form
$sql = "SELECT id, name, description, lkr_price FROM products WHERE status = 'active' ORDER BY name ASC";
$result = $conn->query($sql);

// Updated customer query with selected tenant filter
$customerSql = "SELECT c.*, ct.city_name 
                FROM customers c 
                LEFT JOIN city_table ct ON c.city_id = ct.city_id 
                WHERE c.tenant_id = ? 
                AND c.status = 'Active' 
                ORDER BY c.name ASC";

$customerStmt = $conn->prepare($customerSql);
$customerStmt->bind_param("i", $selected_tenant_id);
$customerStmt->execute();
$customerResult = $customerStmt->get_result();

// Fetch cities for dropdown
$citySql = "SELECT city_id, city_name FROM city_table WHERE is_active = 1 ORDER BY city_name ASC";
$cityResult = $conn->query($citySql);

// Fetch delivery fee from branding table
$deliveryFeeSql = "SELECT delivery_fee FROM branding LIMIT 1";
$deliveryFeeResult = $conn->query($deliveryFeeSql);
$deliveryFee = 0.00;
if ($deliveryFeeResult && $deliveryFeeResult->num_rows > 0) {
    $row = $deliveryFeeResult->fetch_assoc();
    $deliveryFee = floatval($row['delivery_fee']);
}

// Fetch tenants for dropdown if main admin
$tenants = [];
if ($is_main_admin === 1 && $role_id === 1) {
    $tenantsQuery = "SELECT tenant_id, company_name FROM tenants WHERE status = 'active' ORDER BY company_name ASC";
    $tenantsResult = $conn->query($tenantsQuery);
    if ($tenantsResult && $tenantsResult->num_rows > 0) {
        while ($t = $tenantsResult->fetch_assoc()) {
            $tenants[] = $t;
        }
    }
}

// Get selected tenant name
$selectedTenantName = '';
if ($is_main_admin === 1 && $role_id === 1) {
    foreach ($tenants as $tenant) {
        if ($tenant['tenant_id'] == $selected_tenant_id) {
            $selectedTenantName = $tenant['company_name'];
            break;
        }
    }
} else {
    $tenantNameSql = "SELECT company_name FROM tenants WHERE tenant_id = ?";
    $tenantNameStmt = $conn->prepare($tenantNameSql);
    $tenantNameStmt->bind_param("i", $selected_tenant_id);
    $tenantNameStmt->execute();
    $tenantNameResult = $tenantNameStmt->get_result();
    if ($tenantNameResult && $tenantNameResult->num_rows > 0) {
        $tenantNameData = $tenantNameResult->fetch_assoc();
        $selectedTenantName = $tenantNameData['company_name'];
    }
}

include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/sidebar.php');
?>
<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <!-- TITLE -->
    <title>Order Management Admin Portal - Create Order</title>

    <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/head.php'); ?>
    
    <!-- [Template CSS Files] -->
    <link rel="stylesheet" href="../assets/css/styles.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/alert.css" id="main-style-link" />

</head>
<style>
.tenant-selector-card {
    background: #f8f9fa;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 15px 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
     width: fit-content;
     display: flex;
     align-items: center;
   
}

.tenant-selector-content {
    display: flex;
    align-items: center;
    gap: 15px;
}

.tenant-selector-label {
    color: #495057;
    font-weight: 600;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
    white-space: nowrap;
}

.tenant-selector-label i {
    font-size: 16px;
    color: #667eea;
}

.tenant-selector-dropdown {
    flex: 1;
    max-width: 350px;
}

.tenant-selector-dropdown select {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ced4da;
    border-radius: 6px;
    background: #fff;
    color: #495057;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
}

.tenant-selector-dropdown select:hover {
    border-color: #ccd1e8;
}

.tenant-selector-dropdown select:focus {
    outline: none;
    border-color: #ccd1e8;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.tenant-info-display {
    color: #495057;
    font-size: 14px;
    font-weight: 500;
    padding: 8px 16px;
    background: #f8f9fa;
    border-radius: 6px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.tenant-info-display i {
    color: #6473b5;
    font-size: 16px;
}

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
}

.autocomplete-suggestion {
    padding: 10px;
    cursor: pointer;
    border-bottom: 1px solid #f0f0f0;
}

.autocomplete-suggestions {
    width: max-content;
    max-width: 100%;
    white-space: nowrap;
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
    font-weight: 600;
}

.duplicate-product-alert {
    background-color: #fff3cd;
    border: 1px solid #ffc107;
    color: #856404;
    padding: 12px 16px;
    border-radius: 4px;
    margin-bottom: 15px;
    display: flex;
    align-items: center;
    gap: 10px;
    animation: slideIn 0.3s ease-out;
}

.duplicate-product-alert .alert-icon {
    font-size: 20px;
}

.duplicate-product-alert .alert-message {
    flex: 1;
}

.duplicate-product-alert .alert-close {
    background: none;
    border: none;
    font-size: 20px;
    cursor: pointer;
    color: #856404;
    padding: 0;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
.alert-container {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.alert-container .alert {
    animation: slideInRight 0.3s ease-out;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

@keyframes slideInRight {
    from {
        opacity: 0;
        transform: translateX(100px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

</style>
<body>
    <!-- LOADER -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/loader.php'); ?>
    <!-- END LOADER -->

   
        <!-- [ breadcrumb ] start -->
 <div class="pc-container">
        <div class="pc-content">
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">Create Order</h5>
                    </div>
                </div>
            </div>
          <!-- Tenant Selector Card -->
<?php if ($is_main_admin === 1 && $role_id === 1): ?>
<div class="tenant-selector-card">
    <div class="tenant-selector-content">
        <label class="tenant-selector-label">
           <i class="feather icon-briefcase"></i>
            Tenant:
        </label>
        <div class="tenant-selector-dropdown">
            <select id="tenant_selector" onchange="window.location.href='create_order.php?tenant_id=' + this.value">
                <?php foreach ($tenants as $tenant): ?>
                    <option value="<?php echo $tenant['tenant_id']; ?>" 
                            <?php echo ($tenant['tenant_id'] == $selected_tenant_id) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($tenant['company_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</div>
<?php endif; ?>
<!-- REMOVED THE ELSE BLOCK COMPLETELY -->

      <!-- Alert Messages and Courier Status -->
<div class="alert-container" style="position: absolute; top: 85px; right: 20px; z-index: 1000; max-width: 400px;">
    <?php
    // Display session messages
    if (isset($_SESSION['order_success'])) {
        echo '<div class="alert alert-success" id="success-alert">
                <div><span class="alert-icon">✅</span><span>' . htmlspecialchars($_SESSION['order_success']) . '</span></div>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
              </div>';
        unset($_SESSION['order_success']);
    }

    if (isset($_SESSION['order_error'])) {
        echo '<div class="alert alert-error" id="error-alert">
                <div><span class="alert-icon">❌</span><span>' . htmlspecialchars($_SESSION['order_error']) . '</span></div>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
              </div>';
        unset($_SESSION['order_error']);
    }

    if (isset($_SESSION['order_warning'])) {
        echo '<div class="alert alert-warning" id="warning-alert">
                <div><span class="alert-icon">⚠️</span><span>' . htmlspecialchars($_SESSION['order_warning']) . '</span></div>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
              </div>';
        unset($_SESSION['order_warning']);
    }

    // Display courier status messages
    if (!empty($courierStatus['error_message'])) {
        echo '<div class="alert alert-error">
                <div><span class="alert-icon">❌</span><span>' . htmlspecialchars($courierStatus['error_message']) . '</span></div>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
              </div>';
    }

    if (!empty($courierStatus['warning_message'])) {
        echo '<div class="alert alert-warning">
                <div><span class="alert-icon">⚠️</span><span>' . htmlspecialchars($courierStatus['warning_message']) . '</span></div>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
              </div>';
    }

    if (!empty($courierStatus['info_message'])) {
        echo '<div class="alert alert-info">
                <div><span class="alert-icon">ℹ️</span><span>' . htmlspecialchars($courierStatus['info_message']) . '</span></div>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
              </div>';
    }
    ?>

    <!-- Courier Status Card -->
    <?php if ($courierStatus['has_courier']): ?>
    <div class="courier-status-card">
        <h6 style="margin-bottom: 10px; color: #495057;"></h6>
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
                        <span style="color: #dc3545;">0 unused numbers</span>
                    <?php endif; ?>
                </div>
            <?php elseif ($courierStatus['courier_type'] == 2): ?>
                <div>
                    <span class="status-indicator status-active"></span>
                    <strong>Courier:</strong> <?php echo htmlspecialchars($courierStatus['courier_name']); ?> (New API Courier)
                </div>
                <div style="margin-top: 5px;">
                    <strong>Info:</strong> <span style="color: #28a745;">Automatic tracking generation</span>
                </div>
            <?php elseif ($courierStatus['courier_type'] == 3): ?>
                <div>
                    <span class="status-indicator status-api"></span>
                    <strong>Courier:</strong> <?php echo htmlspecialchars($courierStatus['courier_name']); ?> (Existing API Parcel)
                </div>
                <div style="margin-top: 5px;">
                    <strong>Info:</strong> <span style="color: #17a2b8;">Integrated API system</span>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<!-- [ breadcrumb ] end -->

            <!-- [ Main Content ] start -->
             <div class="order-container">
                <form method="post" action="process_order.php" id="orderForm">
                    <!-- Hidden tenant field -->
                    <input type="hidden" name="tenant_id" value="<?php echo $selected_tenant_id; ?>">
                    <!-- Order Details Section -->
                    <div class="order-details-section">
                        <div class="order-details-grid">
                            <div class="form-group">
                                <label class="form-label">Status</label>
                                <div class="status-radio-group">
                                    <div class="radio-option">
                                        <input type="radio" name="order_status" value="Paid" id="status_paid">
                                        <label for="status_paid">Paid</label>
                                    </div>
                                    <div class="radio-option">
                                        <input type="radio" name="order_status" value="Unpaid" id="status_unpaid" checked>
                                        <label for="status_unpaid">Unpaid</label>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Order Date</label>
                                <input type="date" class="form-control" name="order_date"
                                    value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Due Date</label>
                                <input type="date" class="form-control" name="due_date"
                                    value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>" required>
                            </div>
                        </div>
                        <!-- Hidden currency field - always set to LKR -->
                        <input type="hidden" name="order_currency" id="order_currency" value="lkr">
                    </div>

                                <!-- Customer Information Section -->
                <div class="section-card">
                    <div class="section-header" style="display:flex; align-items:center; width:100%;">
                        <h5 class="section-title">Customer Information</h5>

                        <button type="button" 
                                class="btn-outline-primary" 
                                id="select_existing_customer"
                                style="margin-left:auto;">
                            <i class="feather icon-users"></i> Select Customer
                        </button>
                    </div>
                    <div class="section-body">
                        <div class="customer-info-grid">
                            <input type="hidden" name="customer_id" id="customer_id" value="">
                            
                            <div class="form-group">
                                <label class="form-label">Name <span style="color: #dc3545;">*</span></label>
                                <input type="text" class="form-control" name="customer_name" id="customer_name" required placeholder="Enter Full Name">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="customer_email" id="customer_email" placeholder="example@email.com">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Phone</label>
                                <input type="text" class="form-control" name="customer_phone" id="customer_phone" placeholder="(07) xxxx xxxx">
                            </div>

                            <!-- NEW Phone 2 Field -->
                            <div class="form-group">
                                <label class="form-label">Phone 2</label>
                                <input type="text" class="form-control" name="customer_phone_2" id="customer_phone_2" placeholder="(07) xxxx xxxx">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">City</label>
                                <input type="text" 
                                    class="form-control" 
                                    id="city_autocomplete" 
                                    placeholder="Start typing city name..."
                                    autocomplete="off">
                                <input type="hidden" name="city_id" id="city_id">
                                <div id="city_suggestions" class="autocomplete-suggestions"></div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Address Line 1</label>
                                <input type="text" class="form-control" name="address_line1" id="address_line1">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Address Line 2</label>
                                <input type="text" class="form-control" name="address_line2" id="address_line2">
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
                                        <tr>
                                            <td class="action-col">
                                                <button type="button" class="btn-remove remove_product">×</button>
                                            </td>
                                            <td class="product-col">
                                                <select name="order_product[]" class="form-select product-select">
                                                    <option value="">-- Select Product --</option>
                                                    <?php
                                                    // Reset the pointer for $result
                                                    $result->data_seek(0);
                                                    while ($row = $result->fetch_assoc()): ?>
                                                        <option value="<?= $row['id'] ?>"
                                                            data-lkr-price="<?= $row['lkr_price'] ?>"
                                                            data-description="<?= htmlspecialchars($row['description']) ?>">
                                                            <?= htmlspecialchars($row['name']) ?>
                                                        </option>
                                                    <?php endwhile; ?>
                                                </select>
                                            </td>
                                            <td class="description-col">
                                                <input type="text" name="order_product_description[]" class="form-control product-description">
                                            </td>
                                            <td class="quantity-col">
                                                <input type="number" 
                                                       name="order_product_quantity[]" 
                                                       class="form-control quantity" 
                                                       value="1" 
                                                       min="1" 
                                                       step="1">
                                            </td>
                                            <td class="price-col">
                                                <div class="input-group">
                                                    <span class="input-group-text">Rs.</span>
                                                    <input type="number" name="order_product_price[]" class="form-control price" value="0.00" step="0.01">
                                                </div>
                                            </td>
                                            <td class="discount-col">
                                                <input type="number" name="order_product_discount[]" class="form-control discount" value="0" min="0" step="1">
                                            </td>
                                            <td class="subtotal-col">
                                                <div class="input-group">
                                                    <span class="input-group-text">Rs.</span>
                                                    <input type="text" name="order_product_sub[]" class="form-control subtotal" value="0.00" readonly>
                                                </div>
                                            </td>
                                        </tr>
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
                                            Rs. <span id="subtotal_display">0.00</span>
                                            <input type="hidden" id="subtotal_amount" name="subtotal" value="0.00">
                                        </span>
                                    </div>
                                    <div class="totals-row">
                                        <span class="totals-label">Discount:</span>
                                        <span class="totals-value">
                                            Rs. <span id="discount_display">0.00</span>
                                            <input type="hidden" id="discount_amount" name="discount" value="0.00">
                                        </span>
                                    </div>
                                    <div class="totals-row delivery-fee-row" id="delivery_fee_row">
                                        <span class="totals-label">Delivery Fee:</span>
                                        <span class="totals-value">
                                            Rs. <span id="delivery_fee_display"><?php echo number_format($deliveryFee, 2); ?></span>
                                            <input type="hidden" id="delivery_fee" name="delivery_fee" value="<?php echo number_format($deliveryFee, 2); ?>">
                                        </span>
                                    </div>
                                    <div class="totals-row">
                                        <span class="totals-label">Total:</span>
                                        <span class="totals-value">
                                            Rs. <span id="total_display">0.00</span>
                                            <input type="hidden" id="total_amount" name="total_amount" value="0.00">
                                            <input type="hidden" id="lkr_total_amount" name="lkr_price" value="0.00">
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
                                <textarea name="notes" class="form-control" rows="3" placeholder="Enter any additional notes for this order..."></textarea>
                            </div>
                            <div class="submit-section">
                                <button type="submit" class="btn-primary" id="submit_order" 
                                    <?php echo (!$courierStatus['has_courier']) ? 'title="Warning: No courier configured"' : ''; ?>>
                                    <i class="feather icon-save"></i> Create Order
                                    <?php if (!$courierStatus['has_courier']): ?>
                                        <small style="display: block; font-size: 11px; opacity: 0.8;"></small>
                                    <?php elseif ($courierStatus['courier_type'] == 1 && !$courierStatus['has_tracking']): ?>
                                        <small style="display: block; font-size: 11px; opacity: 0.8;"></small>
                                    <?php endif; ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <script>
                // Auto-hide success and info alerts after 5 seconds
                setTimeout(function() {
                    const successAlert = document.getElementById('success-alert');
                    const infoAlert = document.getElementById('info-alert');
                    
                    if (successAlert) {
                        successAlert.style.opacity = '0';
                        setTimeout(() => successAlert.remove(), 300);
                    }
                    
                    if (infoAlert) {
                        infoAlert.style.opacity = '0';
                        setTimeout(() => infoAlert.remove(), 300);
                    }
                }, 5000);

                // Keep error and warning alerts visible longer (10 seconds)
                setTimeout(function() {
                    const errorAlert = document.getElementById('error-alert');
                    const warningAlert = document.getElementById('warning-alert');
                    
                    if (errorAlert) {
                        errorAlert.style.opacity = '0';
                        setTimeout(() => errorAlert.remove(), 300);
                    }
                    
                    if (warningAlert) {
                        warningAlert.style.opacity = '0';
                        setTimeout(() => warningAlert.remove(), 300);
                    }
                }, 10000);
            </script>

            <!-- [ Main Content ] end -->
        </div>
    </div>
    <!-- [ Main Content ] end -->

 <!-- Customer Modal - Will show only selected tenant's customers -->
    <div id="customerModal" class="customer-modal">
        <div class="customer-modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="feather icon-users"></i>
                    Select Customer (<?php echo htmlspecialchars($selectedTenantName); ?>)
                </h5>
                <button type="button" class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="input-group" style="margin-bottom: 20px;">
                    <span class="input-group-text"><i class="feather icon-search"></i></span>
                    <input type="text" id="customerSearch" class="form-control" placeholder="Search customers...">
                </div>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>NAME</th>
                                <th>CONTACT</th>
                                <th>ADDRESS</th>
                                <th>ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $customerResult->data_seek(0);
                            while ($customer = $customerResult->fetch_assoc()): ?>
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
                                    <td><?= htmlspecialchars($customer['name'] ?? '') ?></td>
                                    <td>
                                        <div><?= htmlspecialchars($customer['phone'] ?? '') ?></div>
                                        <?php if (!empty($customer['email'])): ?>
                                            <small><?= htmlspecialchars($customer['email']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?= htmlspecialchars($customer['address_line1'] ?? '') ?></div>
                                        <small><?= htmlspecialchars($customer['city_name'] ?? '') ?></small>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-primary select-customer-btn">Select</button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>


    <!-- FOOTER -->
    <?php
    include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/footer.php');
    ?>
    <!-- END FOOTER -->

    <!-- SCRIPTS -->
    <?php
    include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/scripts.php');
    ?>
    <!-- END SCRIPTS -->
<script>
       // Tenant change function
    function changeTenant(tenantId) {
        window.location.href = '?tenant_id=' + tenantId;
    }

document.addEventListener('DOMContentLoaded', function() {
    // ========== GLOBAL VARIABLES ==========
    let deliveryFee = <?php echo $deliveryFee; ?>;
    let isExistingCustomer = false;

    // ========== VALIDATION UTILITIES ==========
    const ValidationUtils = {
        isValidEmail: (email) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email),
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
    
    checkPhoneExists: async (phone, currentCustomerId = 0) => {
        if (!phone || phone.length !== 10) return { exists: false };
        
        // Get tenant_id from hidden field
        const tenantId = document.querySelector('input[name="tenant_id"]').value;
        
        try {
            const response = await fetch(`check_phone.php?phone=${encodeURIComponent(phone)}&customer_id=${currentCustomerId}&tenant_id=${tenantId}`);
            const data = await response.json();
            return data;
        } catch (error) {
            console.error('Error checking phone:', error);
            return { exists: false };
        }
    },
    
    validatePhoneField: (fieldId, otherFieldId) => {
        const field = document.getElementById(fieldId);
        const otherField = document.getElementById(otherFieldId);
        
        if (PhoneValidator.timeouts[fieldId]) {
            clearTimeout(PhoneValidator.timeouts[fieldId]);
        }
        
        const existingError = field.parentNode.querySelector('.phone-validation-error');
        if (existingError) existingError.remove();
        
        const phone = field.value.trim();
        const otherPhone = otherField.value.trim();
        
        if (!phone) {
            FormValidator.validateAndToggleSubmit();
            return;
        }
        
        if (phone === otherPhone && phone.length === 10) {
            ValidationUtils.showError(field, 'Phone numbers cannot be the same', 'phone-validation-error');
            FormValidator.validateAndToggleSubmit();
            return;
        }
        
        if (phone.length !== 10) {
            FormValidator.validateAndToggleSubmit();
            return;
        }
        
        PhoneValidator.timeouts[fieldId] = setTimeout(async () => {
            const currentCustomerId = document.getElementById('customer_id').value || 0;
            const result = await PhoneValidator.checkPhoneExists(phone, currentCustomerId);
            
            if (result.exists) {
                let message = '';
                if (result.type === 'primary') {
                    message = 'This number is already registered as a primary phone';
                } else if (result.type === 'secondary') {
                    message = 'This number is already registered as a secondary phone';
                }
                
                ValidationUtils.showError(field, message, 'phone-validation-error');
            }
            
            FormValidator.validateAndToggleSubmit();
        }, 500);
    }
};

// ========== EMAIL VALIDATION MODULE ==========
const EmailValidator = {
    timeout: null,

    checkEmailExists: async (email, customerId = 0) => {
        if (!EmailValidator.isValidFormat(email)) return { exists: false };

        // Get tenant_id from hidden field
        const tenantId = document.querySelector('input[name="tenant_id"]').value;

        try {
            const response = await fetch(
                `check_email.php?email=${encodeURIComponent(email)}&customer_id=${customerId}&tenant_id=${tenantId}`
            );
            return await response.json();
        } catch (err) {
            console.error('Email check error:', err);
            return { exists: false };
        }
    },

    getCustomerByEmail: async (email) => {
        // Get tenant_id from hidden field
        const tenantId = document.querySelector('input[name="tenant_id"]').value;
        
        try {
            const response = await fetch(
                `get_customer_by_email.php?email=${encodeURIComponent(email)}&tenant_id=${tenantId}`
            );
            return await response.json();
        } catch (err) {
            console.error('Get customer error:', err);
            return { exists: false };
        }
    },

    autoFillCustomerData: (customerData) => {
        document.getElementById('customer_id').value = customerData.customer_id;
        document.getElementById('customer_name').value = customerData.name || '';
        document.getElementById('customer_phone').value = customerData.phone || '';
        document.getElementById('customer_phone_2').value = customerData.phone_2 || '';
        document.getElementById('address_line1').value = customerData.address_line1 || '';
        document.getElementById('address_line2').value = customerData.address_line2 || '';
        document.getElementById('city_id').value = customerData.city_id || '';
        document.getElementById('city_autocomplete').value = customerData.city_name || '';
        
        isExistingCustomer = true;
        CustomerManager.toggleFields(false);
        ValidationUtils.clearErrors();
        ValidationUtils.clearErrors('phone-validation-error');
        ValidationUtils.clearErrors('email-validation-error');
        
        const emailField = document.getElementById('customer_email');
        const successMsg = document.createElement('div');
        successMsg.className = 'email-validation-success';
        successMsg.style.color = '#28a745';
        successMsg.style.fontSize = '0.875rem';
        successMsg.style.marginTop = '0.25rem';
        successMsg.textContent = '✓ Customer found — email already exists';
        emailField.parentNode.appendChild(successMsg);
        
        setTimeout(() => {
            const successEl = emailField.parentNode.querySelector('.email-validation-success');
            if (successEl) successEl.remove();
        }, 3000);
        
        FormValidator.validateAndToggleSubmit();
        console.log('Auto-filled customer data for:', customerData.name);
    },

    validateEmailField: () => {
        const field = document.getElementById('customer_email');

        const oldError = field.parentNode.querySelector('.email-validation-error');
        if (oldError) oldError.remove();
        
        const oldSuccess = field.parentNode.querySelector('.email-validation-success');
        if (oldSuccess) oldSuccess.remove();

        const email = field.value.trim();
        
        if (!email) {
            if (isExistingCustomer) {
                CustomerManager.clearFields();
            }
            FormValidator.validateAndToggleSubmit();
            return;
        }

        if (!EmailValidator.isValidFormat(email)) {
            ValidationUtils.showError(
                field,
                'Please enter a valid email address (e.g., name@example.com)',
                'email-validation-error'
            );
            FormValidator.validateAndToggleSubmit();
            return;
        }

        clearTimeout(EmailValidator.timeout);

        EmailValidator.timeout = setTimeout(async () => {
            const customerId = document.getElementById('customer_id').value || 0;
            
            const customerResult = await EmailValidator.getCustomerByEmail(email);
            
            if (customerResult.exists && customerResult.customer) {
                EmailValidator.autoFillCustomerData(customerResult.customer);
            } else {
                const duplicateResult = await EmailValidator.checkEmailExists(email, customerId);
                
                if (duplicateResult.exists) {
                    ValidationUtils.showError(
                        field,
                        'This email is already registered',
                        'email-validation-error'
                    );
                } else {
                    if (isExistingCustomer) {
                        CustomerManager.clearFields();
                        document.getElementById('customer_email').value = email;
                    }
                }
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

        if (!name) {
            ValidationUtils.showError(document.getElementById('customer_name'), 'Customer name is required');
            isValid = false;
        }

        const phoneErrors = document.querySelectorAll('.phone-validation-error');
        if (phoneErrors.length > 0) {
            isValid = false;
        }

        const emailErrors = document.querySelectorAll('.email-validation-error');
        if (emailErrors.length > 0) {
            isValid = false;
        }

        if (phone && phone2 && phone === phone2 && phone.length === 10) {
            const phone2Field = document.getElementById('customer_phone_2');
            const existingError = phone2Field.parentNode.querySelector('.phone-validation-error');
            if (!existingError) {
                ValidationUtils.showError(phone2Field, 'Phone numbers cannot be the same', 'phone-validation-error');
            }
            isValid = false;
        }

        if (!isExistingCustomer) {
            if (!phone) {
                ValidationUtils.showError(document.getElementById('customer_phone'), 'Phone number is required');
                isValid = false;
            } else if (!ValidationUtils.isValidPhone(phone)) {
                ValidationUtils.showError(document.getElementById('customer_phone'), 'Phone number must be 10 digits');
                isValid = false;
            }

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
            } else if (orderDate > today) {
                ValidationUtils.showError(document.querySelector('input[name="order_date"]'), 'Order date cannot be in the future', 'date-validation-error');
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

  // ========== PRODUCT MANAGEMENT WITH QUANTITY ==========
const ProductManager = {
    // NEW FUNCTION: Check if product already exists in the order
    checkDuplicateProduct: (productId, currentRow) => {
        if (!productId) return null;
        
        let existingRow = null;
        document.querySelectorAll('#order_table tbody tr').forEach(row => {
            // Skip the current row we're checking
            if (row === currentRow) return;
            
            const productSelect = row.querySelector('.product-select');
            if (productSelect && productSelect.value === productId) {
                existingRow = row;
            }
        });
        
        return existingRow;
    },

    // NEW FUNCTION: Show duplicate product alert
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
        
        // Highlight existing row with yellow background
        if (existingRow) {
            existingRow.style.backgroundColor = '#fff3cd';
            existingRow.style.transition = 'background-color 0.3s';
            
            // Scroll to the existing row
            existingRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
            
            // Remove highlight after 3 seconds
            setTimeout(() => {
                existingRow.style.backgroundColor = '';
            }, 3000);
        }
        
        // Auto-hide alert after 5 seconds
        setTimeout(() => {
            if (alertDiv.parentElement) {
                alertDiv.style.opacity = '0';
                alertDiv.style.transition = 'opacity 0.3s';
                setTimeout(() => alertDiv.remove(), 300);
            }
        }, 5000);
        
        // Scroll to alert
        alertDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    },

    updatePrice: (row) => {
        const productSelect = row.querySelector('.product-select');
        const selectedOption = productSelect.options[productSelect.selectedIndex];
        
        if (!productSelect.value) return;

        // DUPLICATE CHECK: Check if product already exists
        const productId = productSelect.value;
        const productName = selectedOption.text;
        
        const existingRow = ProductManager.checkDuplicateProduct(productId, row);
        
        if (existingRow) {
            // Show alert message
            ProductManager.showDuplicateAlert(productName, existingRow);
            
            // Reset the current row's product selection
            productSelect.value = '';
            row.querySelector('.product-description').value = '';
            row.querySelector('.price').value = '0.00';
            row.querySelector('.discount').value = '0';
            row.querySelector('.quantity').value = '1';
            row.querySelector('.subtotal').value = '0.00';
            
            ProductManager.updateTotals();
            FormValidator.validateAndToggleSubmit();
            return; // Stop further execution
        }

        // Continue with normal product selection if no duplicate
        const priceField = row.querySelector('.price');
        const descriptionField = row.querySelector('.product-description');
        const description = selectedOption.getAttribute('data-description') || '';
        const price = parseFloat(selectedOption.getAttribute('data-lkr-price') || 0);

        priceField.value = isNaN(price) ? '0.00' : price.toFixed(2);
        descriptionField.value = description;

        ProductManager.updateRowTotal(row);
        ProductManager.checkForProducts();
    },

updateRowTotal: (row) => {
    let price = parseFloat(row.querySelector('.price').value) || 0;
    let discount = parseFloat(row.querySelector('.discount').value) || 0;
    let quantity = parseInt(row.querySelector('.quantity').value) || 1;

    // Calculate total price before discount
    let totalPrice = price * quantity;

    // Discount should not exceed total price
    if (discount > totalPrice) {
        discount = totalPrice;
        row.querySelector('.discount').value = discount;
    }

    // FIXED CALCULATION: (price × quantity) - total_discount
    let subtotal = totalPrice - discount;
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
        deliveryFeeRow.style.display = hasProducts ? 'flex' : 'none';
        
        return hasProducts;
    },

   updateTotals: () => {
    let subtotal = 0;
    let totalDiscount = 0;

    document.querySelectorAll('#order_table tbody tr').forEach(row => {
        let rowPrice = parseFloat(row.querySelector('.price').value) || 0;
        let rowDiscount = parseFloat(row.querySelector('.discount').value) || 0;
        let rowQuantity = parseInt(row.querySelector('.quantity').value) || 1;

        // Calculate total price for this row
        let rowTotalPrice = rowPrice * rowQuantity;

        // Discount should not exceed total price for this row
        if (rowDiscount > rowTotalPrice) {
            rowDiscount = rowTotalPrice;
            row.querySelector('.discount').value = rowDiscount;
        }

        // FIXED CALCULATION: (price × quantity) - total_discount
        let rowSubtotal = rowTotalPrice - rowDiscount;
        row.querySelector('.subtotal').value = rowSubtotal.toFixed(2);

        subtotal += rowTotalPrice;
        totalDiscount += rowDiscount; // Don't multiply by quantity!
    });

    document.getElementById('subtotal_display').textContent = subtotal.toFixed(2);
    document.getElementById('subtotal_amount').value = subtotal.toFixed(2);
    document.getElementById('discount_display').textContent = totalDiscount.toFixed(2);
    document.getElementById('discount_amount').value = totalDiscount.toFixed(2);

    let subtotalAfterDiscount = subtotal - totalDiscount;
    const hasProducts = ProductManager.checkForProducts();

    // UPDATED: Always apply delivery fee if products exist (no free delivery logic)
    let finalDeliveryFee = hasProducts ? deliveryFee : 0;
    
    document.getElementById('delivery_fee_display').textContent = finalDeliveryFee.toFixed(2);
    document.getElementById('delivery_fee').value = finalDeliveryFee.toFixed(2);

    let total = subtotalAfterDiscount + finalDeliveryFee;

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
                    ValidationUtils.showError(priceInput, 'Price must be greater than 0', 'product-validation-error');
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
            if (input.classList.contains('price')) {
                input.value = '0.00';
            } else if (input.classList.contains('discount')) {
                input.value = '0';
            } else if (input.classList.contains('quantity')) {
                input.value = '1';
            } else if (input.classList.contains('subtotal')) {
                input.value = '0.00';
            } else {
                input.value = '';
            }
        });
        
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
            row.querySelector('.quantity').value = '1';
            row.querySelector('.price').value = '0.00';
            row.querySelector('.discount').value = '0';
            row.querySelector('.subtotal').value = '0.00';
            ProductManager.checkForProducts();
            ProductManager.updateTotals();
        }
        FormValidator.validateAndToggleSubmit();
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

        const isFormValid = customerValid && datesValid && productsValid && hasValidProducts;

        submitButton.disabled = !isFormValid;
        submitButton.style.opacity = isFormValid ? '1' : '0.6';
        submitButton.style.cursor = isFormValid ? 'pointer' : 'not-allowed';
        submitButton.style.backgroundColor = isFormValid ? '#007bff' : '#6c757d';

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

            fetch('get_cities.php')
                .then(response => response.json())
                .then(data => CityAutocomplete.cities = data)
                .catch(error => console.error('Error loading cities:', error));

            cityInput.addEventListener('input', function() {
                const searchTerm = this.value.trim().toLowerCase();
                
                if (searchTerm.length === 0) {
                    suggestionsDiv.style.display = 'none';
                    cityIdInput.value = '';
                    FormValidator.validateAndToggleSubmit();
                    return;
                }

                const filteredCities = CityAutocomplete.cities.filter(city => 
                    city.city_name.toLowerCase().includes(searchTerm)
                );

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
                        const selected = suggestions[CityAutocomplete.selectedIndex];
                        CityAutocomplete.selectCity(selected.dataset.cityId, selected.dataset.cityName, cityInput, cityIdInput, suggestionsDiv);
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

            let html = filteredCities.map((city, index) => 
                `<div class="autocomplete-suggestion" data-city-id="${city.city_id}" data-city-name="${city.city_name}" data-index="${index}">
                    ${city.city_name}
                </div>`
            ).join('');

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
                alert('Customer selected: ' + row.getAttribute('data-name'));
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
            // Customer field validation
            ['customer_name', 'customer_email', 'customer_phone', 'address_line1', 'address_line2'].forEach(id => {
                document.getElementById(id).addEventListener('input', FormValidator.validateAndToggleSubmit);
            });

            // Phone validation listeners
            document.getElementById('customer_phone').addEventListener('input', () => {
                PhoneValidator.validatePhoneField('customer_phone', 'customer_phone_2');
            });

            document.getElementById('customer_phone_2').addEventListener('input', () => {
                PhoneValidator.validatePhoneField('customer_phone_2', 'customer_phone');
            });

            document.getElementById('customer_email').addEventListener('input', () => {
                EmailValidator.validateEmailField();
            });

            document.getElementById('customer_email').addEventListener('blur', () => {
                EmailValidator.validateEmailField();
            });

            document.getElementById('customer_phone').addEventListener('blur', () => {
                PhoneValidator.validatePhoneField('customer_phone', 'customer_phone_2');
            });

            document.getElementById('customer_phone_2').addEventListener('blur', () => {
                PhoneValidator.validatePhoneField('customer_phone_2', 'customer_phone');
            });

            // Phone input - only numbers, max 10 digits
            document.getElementById('customer_phone').addEventListener('input', function(e) {
                this.value = this.value.replace(/[^0-9]/g, '');
                if (this.value.length > 10) {
                    this.value = this.value.slice(0, 10);
                }
                PhoneValidator.validatePhoneField('customer_phone', 'customer_phone_2');
            });

            document.getElementById('customer_phone_2').addEventListener('input', function(e) {
                this.value = this.value.replace(/[^0-9]/g, '');
                if (this.value.length > 10) {
                    this.value = this.value.slice(0, 10);
                }
                PhoneValidator.validatePhoneField('customer_phone_2', 'customer_phone');
            });

            // Prevent pasting non-numeric content
            document.getElementById('customer_phone').addEventListener('paste', function(e) {
                e.preventDefault();
                const pastedText = (e.clipboardData || window.clipboardData).getData('text');
                const numericOnly = pastedText.replace(/[^0-9]/g, '').slice(0, 10);
                this.value = numericOnly;
                PhoneValidator.validatePhoneField('customer_phone', 'customer_phone_2');
            });

            document.getElementById('customer_phone_2').addEventListener('paste', function(e) {
                e.preventDefault();
                const pastedText = (e.clipboardData || window.clipboardData).getData('text');
                const numericOnly = pastedText.replace(/[^0-9]/g, '').slice(0, 10);
                this.value = numericOnly;
                PhoneValidator.validatePhoneField('customer_phone_2', 'customer_phone');
            });

            // Date validation
            document.querySelector('input[name="order_date"]').addEventListener('change', FormValidator.validateAndToggleSubmit);
            document.querySelector('input[name="due_date"]').addEventListener('change', FormValidator.validateAndToggleSubmit);

            // Product events (event delegation)
            document.addEventListener('change', (e) => {
                if (e.target.classList.contains('product-select')) {
                    ProductManager.updatePrice(e.target.closest('tr'));
                    FormValidator.validateAndToggleSubmit();
                }
            });

            document.addEventListener('input', (e) => {
                if (e.target.classList.contains('discount')) {
                    e.target.value = e.target.value.replace(/[^0-9]/g, '');
                }
                
                // UPDATED: Quantity handling - only allow positive integers
                if (e.target.classList.contains('quantity')) {
                    let value = parseInt(e.target.value) || 1;
                    if (value < 1) {
                        e.target.value = 1;
                    }
                }
                
                // UPDATED: Update totals when price, discount, OR quantity changes
                if (e.target.classList.contains('price') || 
                    e.target.classList.contains('discount') ||
                    e.target.classList.contains('quantity')) {
                    ProductManager.updateRowTotal(e.target.closest('tr'));
                    FormValidator.validateAndToggleSubmit();
                }
                
                if (e.target.classList.contains('product-description')) {
                    FormValidator.validateAndToggleSubmit();
                }
            });

            // Add product row
            document.getElementById('add_product').addEventListener('click', ProductManager.addRow);

            // Remove product row
            document.addEventListener('click', (e) => {
                if (e.target.classList.contains('remove_product')) {
                    ProductManager.removeRow(e.target);
                }
            });

            // Form submission
            document.getElementById('orderForm').addEventListener('submit', (e) => {
                if (!FormValidator.validateAndToggleSubmit()) {
                    e.preventDefault();
                    let issues = [];
                    if (!CustomerManager.validate()) issues.push('Customer information');
                    if (!DateValidator.validate()) issues.push('Order dates');
                    if (!ProductManager.validate()) issues.push('Product information');
                    if (!ProductManager.hasValidProduct()) issues.push('At least one complete product');
                
                if (document.querySelectorAll('.email-validation-error').length > 0) {
                    isValid = false;
                }

                    alert('Please fix the following issues:\n- ' + issues.join('\n- '));
                    return false;
                }
                return true;
            });
        }
    };

    // ========== INITIALIZATION ==========
    CityAutocomplete.init();
    CustomerModal.init();
    EventListeners.init();
    
    document.getElementById('delivery_fee_row').style.display = 'none';
    ProductManager.updateTotals();
    FormValidator.validateAndToggleSubmit();
});
</script>
</body>
</html>