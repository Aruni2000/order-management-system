<?php
// Start session at the very beginning
session_start();

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /orderhub_nextwave/dist/pages/login.php");
    exit();
}

// Include the database connection file
include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/connection/db_connection.php');

// Get user permissions
$is_main_admin = isset($_SESSION['is_main_admin']) ? (int)$_SESSION['is_main_admin'] : 0;
$role_id = isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : 0;
$session_tenant_id = isset($_SESSION['tenant_id']) ? intval($_SESSION['tenant_id']) : 0;

// Determine selected tenant - either from GET parameter or session
$selected_tenant_id = isset($_GET['tenant_id']) ? intval($_GET['tenant_id']) : $session_tenant_id;

// If not main admin, force to use their own tenant
if (!($is_main_admin === 1 && $_SESSION['role_id'] == 1)) {
    $selected_tenant_id = $session_tenant_id;
}

// Function to log user actions
function logUserAction($conn, $user_id, $action_type, $inquiry_id, $details = null) {
    $stmt = $conn->prepare("INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, ?, ?, ?, NOW())");
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
        $status['info_message'] = "No default courier selected.";
    }
    
    return $status;
}

// Check courier status for selected tenant
$courierStatus = checkCourierStatus($conn, $selected_tenant_id);

// Fetch necessary data for the form - filter by selected tenant for proper isolation
$productSql = "SELECT id, name, description, stock_quantity, low_stock_threshold FROM products WHERE status = 'active' AND tenant_id = ? ORDER BY name ASC";
$productStmt = $conn->prepare($productSql);
$productStmt->bind_param("i", $selected_tenant_id);
$productStmt->execute();
$result = $productStmt->get_result();
$productStmt->close();


// Fetch cities for dropdown
$citySql = "SELECT city_id, city_name FROM city_table WHERE is_active = 1 ORDER BY city_name ASC";
$cityResult = $conn->query($citySql);

// Fetch delivery fee from tenants table (filtered by selected tenant)
$deliveryFeeSql = "SELECT delivery_fee FROM tenants WHERE tenant_id = ? AND status = 'active' LIMIT 1";
$deliveryFeeStmt = $conn->prepare($deliveryFeeSql);
$deliveryFeeStmt->bind_param("i", $selected_tenant_id);
$deliveryFeeStmt->execute();
$deliveryFeeResult = $deliveryFeeStmt->get_result();
$deliveryFee = 0.00;
if ($deliveryFeeResult && $deliveryFeeResult->num_rows > 0) {
    $row = $deliveryFeeResult->fetch_assoc();
    $deliveryFee = floatval($row['delivery_fee']);
}
$deliveryFeeStmt->close();

// Fetch tenants for dropdown if main admin
$tenants = [];
if ($is_main_admin === 1 && $_SESSION['role_id'] == 1) {
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
if ($is_main_admin === 1 && $_SESSION['role_id'] == 1) {
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
?>
<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <!-- TITLE -->
    <title>Create Order | <?= htmlspecialchars($_SESSION['company_name'] ?? '') ?></title>

    <?php include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/include/head.php'); ?>
    
    <!-- [Template CSS Files] -->
    <link rel="stylesheet" href="../assets/css/styles.css" />
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

.batch-col {
    min-width: 150px;
}

.batch-col select {
    min-width: 150px;
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
    position: absolute;
    top: 25px;
    right: 20px;
    z-index: 1000;
    max-width: 400px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

@media (max-width: 768px) {
    .alert-container {
        position: static;
        top: auto;
        right: auto;
        max-width: 100%;
        margin-bottom: 20px;
    }
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

/* ===== CUSTOMER MODAL PAGINATION ===== */
.customer-pagination {
    display: flex;
    justify-content: center;
    padding: 15px 0 5px;
}

.pagination-controls {
    display: flex;
    align-items: center;
    gap: 4px;
}

.pagination-btn {
    padding: 5px 12px;
    border: 1px solid #dee2e6;
    background: #fff;
    color: #007bff;
    border-radius: 4px;
    cursor: pointer;
    font-size: 13px;
    transition: all 0.2s;
}

.pagination-btn:hover:not(:disabled):not(.active) {
    background: #e9ecef;
}

.pagination-btn.active {
    background: #007bff;
    color: #fff;
    border-color: #007bff;
}

.pagination-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.pagination-ellipsis {
    padding: 5px 6px;
    color: #6c757d;
    font-size: 13px;
}

.customer-count-info {
    text-align: center;
    font-size: 12px;
    color: #6c757d;
    padding-bottom: 10px;
}

.customer-search-bar {
    display: flex;
    gap: 8px;
    margin-bottom: 20px;
}

.customer-search-bar .form-control {
    flex: 1;
}

.customer-search-btn {
    white-space: nowrap;
}

.customer-clear-btn {
    white-space: nowrap;
    border: 1px solid #dee2e6;
    background: #fff;
    color: #6c757d;
}

.customer-clear-btn:hover {
    background: #f8f9fa;
    border-color: #c0c5cc;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

.out-of-stock-option {
    color: #dc3545 !important;
    font-style: italic;
}

/* Simple success rate label (plain colored text, no badge pill) */
.success-rate-label {
    font-size: 0.85rem;
    font-weight: 600;
}
.success-rate-label[data-rate="rate-excellent"] { color: #16a34a; }
.success-rate-label[data-rate="rate-good"] { color: #2563eb; }
.success-rate-label[data-rate="rate-average"] { color: #d97706; }
.success-rate-label[data-rate="rate-bad"] { color: #dc2626; }
.success-rate-label[data-rate="rate-new"] { color: #6b7280; }

</style>
<body>
    <!-- LOADER -->
    <?php 
    include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/include/loader.php');
    include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/include/navbar.php');
    include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/include/sidebar.php');
    ?>
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

      <!-- Alert Messages and Courier Status -->
<div class="alert-container">
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

          <!-- Tenant Selector Card -->
<?php if ($is_main_admin === 1 && $_SESSION['role_id'] == 1): ?>
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

<!-- [ breadcrumb ] end -->

            <!-- [ Main Content ] start -->
             <div class="order-container">
                <form method="post" action="process_order.php" id="orderForm" target="_blank" onsubmit="setTimeout(function() { window.location.reload(); }, 1500);">
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
                                <input type="tel" class="form-control" name="customer_phone" id="customer_phone" placeholder="Enter Phone Number">
                                <div id="customer_success_rate" style="margin-top: 6px; display: none;"></div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Phone 2</label>
                                <input type="tel" class="form-control" name="customer_phone_2" id="customer_phone_2" placeholder="Enter Phone Number 2">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Address Line 1</label>
                                <input type="text" class="form-control" name="address_line1" id="address_line1" placeholder="Enter Address Line 1">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Address Line 2</label>
                                <input type="text" class="form-control" name="address_line2" id="address_line2" placeholder="Enter Address Line 2">
                            </div>

                            <div class="form-group">
                                <label class="form-label">City</label>
                                <input type="text" 
                                    class="form-control" 
                                    id="city_autocomplete" 
                                    placeholder="Enter City"
                                    autocomplete="off">
                                <input type="hidden" name="city_id" id="city_id">
                                <div id="city_suggestions" class="autocomplete-suggestions"></div>
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
                                            <th class="batch-col">Price</th>
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
                                                    while ($row = $result->fetch_assoc()): 
                                                        $stock = (int)$row['stock_quantity'];
                                                        $allow_inventory = isset($_SESSION['allow_inventory']) && $_SESSION['allow_inventory'] == 1;
                                                        $is_out_of_stock = $allow_inventory && ($stock <= 0);
                                                        
                                                        $stock_label = "";
                                                        if ($allow_inventory) {
                                                            $stock_label = $is_out_of_stock ? " (Out of Stock)" : " (Stock: $stock)";
                                                        }
                                                    ?>
                                                        <option value="<?= $row['id'] ?>"
                                                            data-description="<?= htmlspecialchars($row['description']) ?>"
                                                            data-stock="<?= $allow_inventory ? $stock : 999999 ?>"
                                                            class="<?= $is_out_of_stock ? 'out-of-stock-option' : '' ?>"
                                                            <?= $is_out_of_stock ? 'disabled' : '' ?>>
                                                            <?= htmlspecialchars($row['name']) . $stock_label ?>
                                                        </option>
                                                    <?php endwhile; ?>
                                                </select>
                                            </td>
                                            <td class="description-col">
                                                <input type="text" name="order_product_description[]" class="form-control product-description" disabled>
                                            </td>
                                            <td class="batch-col">
                                                <select name="order_product_batch[]" class="form-select batch-select" style="min-width: 150px;" disabled>
                                                    <option value="">-- Select Price --</option>
                                                </select>
                                            </td>
                                            <td class="quantity-col">
                                                <input type="number" 
                                                       name="order_product_quantity[]" 
                                                       class="form-control quantity" 
                                                       value="1" 
                                                       min="1" 
                                                       step="1"
                                                       disabled>
                                            </td>
                                            <td class="price-col">
                                                <div class="input-group">
                                                    <span class="input-group-text">Rs.</span>
                                                    <input type="number" name="order_product_price[]" class="form-control price" value="0.00" step="0.01" disabled>
                                                </div>
                                            </td>
                                            <td class="discount-col">
                                                <input type="number" name="order_product_discount[]" class="form-control discount" value="0.00" min="0" step="0.01" disabled>
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

  <!-- Customer Selection Modal (AJAX with Pagination) -->
    <div id="customerModal" class="customer-modal">
        <div class="customer-modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="feather icon-users"></i>
                    Select Customer
                </h5>
                <button type="button" class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="customer-search-bar">
                    <input type="text" id="customerSearch" class="form-control" placeholder="Search : Customer id | Customer Name | Email | Phone Number | City" onkeydown="if(event.key==='Enter'){event.preventDefault();document.getElementById('customerSearchBtn').click();}">
                    <button type="button" id="customerSearchBtn" class="btn btn-primary customer-search-btn"><i class="feather icon-search"></i> Search</button>
                    <button type="button" id="customerClearBtn" class="btn customer-clear-btn"><i class="feather icon-x"></i> Clear</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>CUSTOMER NAME</th>
                                <th>PHONE &amp; EMAIL</th>
                                <th>ADDRESS</th>
                                <th>ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody id="customerTableBody">
                            <tr><td colspan="5" style="text-align:center; padding:20px;">Loading customers...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div id="customerPagination" class="customer-pagination"></div>
                <div id="customerCountInfo" class="customer-count-info"></div>
            </div>
        </div>
    </div>


    <!-- FOOTER -->
    <?php
    include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/include/footer.php');
    ?>
    <!-- END FOOTER -->

    <!-- SCRIPTS -->
    <?php
    include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/include/scripts.php');
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
    
    getCustomerByPhone: async (phone) => {
        if (!phone || phone.length !== 10) return { exists: false };
        
        const tenantId = document.querySelector('input[name="tenant_id"]').value;
        
        try {
            const response = await fetch(`get_customer_by_phone.php?phone=${encodeURIComponent(phone)}&tenant_id=${tenantId}`);
            return await response.json();
        } catch (error) {
            console.error('Error getting customer by phone:', error);
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
            const customerResult = await PhoneValidator.getCustomerByPhone(phone);
            
            if (customerResult.exists && customerResult.customer) {
                document.getElementById('customer_id').value = customerResult.customer.customer_id;
                isExistingCustomer = true;
            } else {
                if (isExistingCustomer) {
                    document.getElementById('customer_id').value = '';
                    isExistingCustomer = false;
                }
            }
            
            FormValidator.validateAndToggleSubmit();
        }, 500);
    }
};

// ========== SUCCESS RATE MODULE ==========
// Shows the customer's order success rate (condition) based on the entered phone number
const SuccessRate = {
    timeout: null,
    containerId: 'customer_success_rate',

    update: () => {
        const rawPhone = document.getElementById('customer_phone').value.trim();
        const phone = rawPhone.replace(/[^0-9]/g, '').slice(0, 10);
        const container = document.getElementById(SuccessRate.containerId);

        if (!container) return;

        // Only check complete 10-digit phone numbers
        if (phone.length !== 10) {
            SuccessRate.clear();
            return;
        }

        // Get tenant_id from hidden field
        const tenantId = document.querySelector('input[name="tenant_id"]').value;

        // Debounce the request
        if (SuccessRate.timeout) clearTimeout(SuccessRate.timeout);
        SuccessRate.timeout = setTimeout(async () => {
            try {
                const response = await fetch(`get_customer_success_rate.php?phone=${encodeURIComponent(phone)}&tenant_id=${tenantId}`);
                const data = await response.json();

                if (data.found) {
                    container.style.display = 'block';
                    container.innerHTML = `<span class="success-rate-label" data-rate="${data.css_class}">Success Rate: ${data.label}</span>`;
                } else {
                    SuccessRate.clear();
                }
            } catch (error) {
                console.error('Error fetching success rate:', error);
            }
        }, 500);
    },

    clear: () => {
        if (SuccessRate.timeout) {
            clearTimeout(SuccessRate.timeout);
            SuccessRate.timeout = null;
        }
        const container = document.getElementById(SuccessRate.containerId);
        if (container) {
            container.style.display = 'none';
            container.innerHTML = '';
        }
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

    validateEmailField: () => {
        const field = document.getElementById('customer_email');

        const oldError = field.parentNode.querySelector('.email-validation-error');
        if (oldError) oldError.remove();
        
        const oldSuccess = field.parentNode.querySelector('.email-validation-success');
        if (oldSuccess) oldSuccess.remove();

        const email = field.value.trim();
        
        if (!email) {
            if (isExistingCustomer) {
                document.getElementById('customer_id').value = ''; // Only clear customer_id
                isExistingCustomer = false; // Reset flag
                CustomerManager.toggleFields(false); // Make fields editable again
            }
            FormValidator.validateAndToggleSubmit();
            return;
        }

        if (!ValidationUtils.isValidEmail(email)) { // Changed from EmailValidator.isValidFormat
            ValidationUtils.showError(
                field,
                'Please enter a valid email address',
                'email-validation-error'
            );
            if (isExistingCustomer) {
                document.getElementById('customer_id').value = '';
                isExistingCustomer = false;
                CustomerManager.toggleFields(false);
            }
            FormValidator.validateAndToggleSubmit();
            return;
        }

        clearTimeout(EmailValidator.timeout);

        EmailValidator.timeout = setTimeout(async () => {
            const customerId = document.getElementById('customer_id').value || 0;
            
            const customerResult = await EmailValidator.getCustomerByEmail(email);
            
            if (customerResult.exists && customerResult.customer) {
                document.getElementById('customer_id').value = customerResult.customer.customer_id;
                isExistingCustomer = true;
            } else {
                if (isExistingCustomer) {
                    document.getElementById('customer_id').value = '';
                    isExistingCustomer = false;
                    CustomerManager.toggleFields(false);
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
        SuccessRate.clear();
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
    // Check if the same product exists in the order at the SAME selling price.
    // Same product at a different price group is allowed (different batch price).
    checkDuplicateProductAndPrice: (productId, price, currentRow) => {
        if (!productId) return null;
        
        let existingRow = null;
        document.querySelectorAll('#order_table tbody tr').forEach(row => {
            // Skip the current row we're checking
            if (row === currentRow) return;
            
            const productSelect = row.querySelector('.product-select');
            const batchSelect = row.querySelector('.batch-select');
            if (!productSelect || productSelect.value !== productId) return;
            if (!batchSelect || !batchSelect.value) return;
            if (parseFloat(batchSelect.value) === price) {
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
        
        if (!productSelect.value) {
            row.querySelector('.product-description').value = '';
            row.querySelector('.product-description').disabled = true;
            row.querySelector('.price').value = '0.00';
            row.querySelector('.quantity').value = '1';
            row.querySelector('.discount').value = '0.00';
            row.querySelector('.subtotal').value = '0.00';

            const batchSelect = row.querySelector('.batch-select');
            if (batchSelect) {
                batchSelect.innerHTML = '<option value="">-- Select Price --</option>';
                batchSelect.disabled = true;
            }
            row.querySelector('.quantity').disabled = true;
            row.querySelector('.price').disabled = true;
            row.querySelector('.discount').disabled = true;

            ProductManager.updateTotals();
            FormValidator.validateAndToggleSubmit();
            return;
        }

        const productId = productSelect.value;
        const productName = selectedOption.text;

        // Continue with normal product selection
        const priceField = row.querySelector('.price');
        const descriptionField = row.querySelector('.product-description');
        const quantityInput = row.querySelector('.quantity');
        const batchSelect = row.querySelector('.batch-select');
        const tenantId = document.querySelector('input[name="tenant_id"]').value;
        const description = selectedOption.getAttribute('data-description') || '';

        descriptionField.value = description;
        descriptionField.disabled = false;

        // Reset batch/price/quantity until a price group is chosen
        priceField.value = '0.00';
        priceField.disabled = true;
        batchSelect.innerHTML = '<option value="">-- Select Price --</option>';
        batchSelect.disabled = true;
        quantityInput.disabled = true;
        quantityInput.value = 1;
        row.querySelector('.discount').disabled = true;
        row.querySelector('.discount').value = '0.00';

        // Load available price groups from batches
        fetch('get_product_batches.php?product_id=' + encodeURIComponent(productId) + '&tenant_id=' + encodeURIComponent(tenantId))
            .then(response => response.json())
            .then(data => {
                if (data.success && data.prices && data.prices.length > 0) {
                    let opts = '<option value="">-- Select Price --</option>';
                    data.prices.forEach(p => {
                        const lbl = p.formatted_label || ('Rs. ' + Number(p.selling_price).toFixed(2) + ' (Stock: ' + p.stock + ')');
                        opts += '<option value="' + p.selling_price + '" data-stock="' + p.stock + '">' + lbl + '</option>';
                    });
                    batchSelect.innerHTML = opts;
                    batchSelect.disabled = false;
                    FormValidator.validateAndToggleSubmit();
                } else {
                    // No batches available
                    batchSelect.innerHTML = '<option value="">-- No Price --</option>';
                    batchSelect.disabled = true;
                    priceField.value = '0.00';
                    priceField.disabled = true;
                    quantityInput.disabled = true;
                    row.querySelector('.discount').disabled = true;
                    ProductManager.updateRowTotal(row);
                    ProductManager.checkForProducts();
                    FormValidator.validateAndToggleSubmit();
                }
            })
            .catch(() => {
                // On error, disable pricing
                batchSelect.innerHTML = '<option value="">-- No Price --</option>';
                batchSelect.disabled = true;
                priceField.value = '0.00';
                priceField.disabled = true;
                quantityInput.disabled = true;
                row.querySelector('.discount').disabled = true;
                ProductManager.updateRowTotal(row);
                ProductManager.checkForProducts();
                FormValidator.validateAndToggleSubmit();
            });
    },

    selectBatch: (row) => {
        const batchSelect = row.querySelector('.batch-select');
        const selectedOption = batchSelect.options[batchSelect.selectedIndex];
        const priceField = row.querySelector('.price');
        const quantityInput = row.querySelector('.quantity');

        if (!batchSelect.value) {
            priceField.value = '0.00';
            priceField.disabled = true;
            quantityInput.disabled = true;
            quantityInput.value = 1;
            row.querySelector('.discount').disabled = true;
            ProductManager.updateRowTotal(row);
            return;
        }

        const price = parseFloat(batchSelect.value) || 0;
        priceField.value = price.toFixed(2);
        priceField.disabled = false;
        quantityInput.disabled = false;
        row.querySelector('.discount').disabled = false;

        // Duplicate check: block same product at the SAME selling price.
        // Same product at a different price group is allowed.
        const productId = row.querySelector('.product-select').value;
        const existingRow = ProductManager.checkDuplicateProductAndPrice(productId, price, row);
        if (existingRow) {
            const productName = row.querySelector('.product-select').options[row.querySelector('.product-select').selectedIndex].text || 'Product';
            ProductManager.showDuplicateAlert(productName, existingRow);
            batchSelect.value = '';
            priceField.value = '0.00';
            priceField.disabled = true;
            quantityInput.disabled = true;
            quantityInput.value = 1;
            row.querySelector('.discount').disabled = true;
            ProductManager.updateRowTotal(row);
            ProductManager.checkForProducts();
            FormValidator.validateAndToggleSubmit();
            return;
        }

        const stock = parseInt(selectedOption.getAttribute('data-stock') || 0);
        if (stock > 0) {
            quantityInput.max = stock;
            if (parseInt(quantityInput.value) > stock) {
                quantityInput.value = stock;
            }
        }

        ProductManager.updateRowTotal(row);
        ProductManager.checkForProducts();
        FormValidator.validateAndToggleSubmit();
    },

updateRowTotal: (row) => {
    let price = parseFloat(row.querySelector('.price').value) || 0;
    let discount = parseFloat(row.querySelector('.discount').value) || 0;
    
    const qtyInput = row.querySelector('.quantity');
    const productSelect = row.querySelector('.product-select');
    const selectedOption = productSelect.options[productSelect.selectedIndex];
    const batchSelect = row.querySelector('.batch-select');
    const selectedBatch = batchSelect ? batchSelect.options[batchSelect.selectedIndex] : null;
    
    if (qtyInput.value !== "" && parseInt(qtyInput.value) < 1) {
        qtyInput.value = 1;
    }
    
    let quantity = parseInt(qtyInput.value) || 1;

    // Stock validation - use the selected batch/price group stock when available
    if (selectedBatch && selectedBatch.value !== "") {
        const stock = parseInt(selectedBatch.getAttribute('data-stock') || 0);
        if (stock > 0) {
            if (quantity > stock) {
                qtyInput.value = stock;
                quantity = stock;
            }
            qtyInput.max = stock;
        }
    } else if (selectedOption && selectedOption.value !== "") {
        const stock = parseInt(selectedOption.getAttribute('data-stock') || 0);
        if (quantity > stock) {
            qtyInput.value = stock;
            quantity = stock;
        }
        qtyInput.max = stock;
    }

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
},

    validate: (showErrors = false) => {
        ValidationUtils.clearErrors('product-validation-error');
        let isValid = true;

        document.querySelectorAll('#order_table tbody tr').forEach(row => {
            const productSelect = row.querySelector('.product-select');
            const descriptionInput = row.querySelector('.product-description');
            const batchSelect = row.querySelector('.batch-select');

            if (productSelect.value !== '') {
                if (!descriptionInput.value.trim()) {
                    ValidationUtils.showError(descriptionInput, 'Description required', 'product-validation-error');
                    isValid = false;
                }

                if (batchSelect && !batchSelect.disabled && !batchSelect.value) {
                    if (showErrors) {
                        ValidationUtils.showError(batchSelect, 'Please select a price/batch', 'product-validation-error');
                    }
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
            const batchSelect = row.querySelector('.batch-select');

            const batchOk = !batchSelect || batchSelect.value !== '';
            if (productSelect.value !== '' && descriptionInput.value.trim() !== '' && batchOk) {
                hasValid = true;
            }
        });
        return hasValid;
    },

    addRow: () => {
        let newRow = document.querySelector('#order_table tbody tr').cloneNode(true);
        
        newRow.querySelectorAll('input').forEach(input => {
            input.removeAttribute('max');
            if (input.classList.contains('price')) {
                input.value = '0.00';
                input.disabled = true;
            } else if (input.classList.contains('discount')) {
                input.value = '0.00';
                input.disabled = true;
            } else if (input.classList.contains('quantity')) {
                input.value = '1';
                input.disabled = true;
            } else if (input.classList.contains('subtotal')) {
                input.value = '0.00';
            } else if (input.classList.contains('product-description')) {
                input.value = '';
                input.disabled = true;
            } else {
                input.value = '';
            }
        });
        
        newRow.querySelector('.product-select').value = '';
        const newBatch = newRow.querySelector('.batch-select');
        if (newBatch) {
            newBatch.innerHTML = '<option value="">-- Select Price --</option>';
            newBatch.disabled = true;
        }
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
            const batchSel = row.querySelector('.batch-select');
            if (batchSel) {
                batchSel.innerHTML = '<option value="">-- Select Price --</option>';
                batchSel.disabled = true;
            }
            row.querySelector('.discount').value = '0.00';
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
window.CustomerModal = {
    currentPage: 1,
    currentSearch: '',
    selectedTenantId: <?php echo $selected_tenant_id; ?>,

    init: () => {
        const modal = document.getElementById("customerModal");
        const selectBtn = document.getElementById("select_existing_customer");
        const closeBtn = document.querySelector(".close-modal");
        const searchInput = document.getElementById("customerSearch");
        const searchBtn = document.getElementById("customerSearchBtn");
        const clearBtn = document.getElementById("customerClearBtn");

        selectBtn.addEventListener('click', () => {
            CustomerModal.currentPage = 1;
            CustomerModal.currentSearch = '';
            document.getElementById('customerSearch').value = '';
            modal.style.display = "block";
            CustomerModal.loadCustomers();
        });

        closeBtn.addEventListener('click', () => modal.style.display = "none");
        window.addEventListener('click', (event) => {
            if (event.target == modal) modal.style.display = "none";
        });

        // Search button click
        searchBtn.addEventListener('click', function() {
            CustomerModal.currentSearch = document.getElementById('customerSearch').value.trim();
            CustomerModal.currentPage = 1;
            CustomerModal.loadCustomers();
        });

        // Clear button
        clearBtn.addEventListener('click', function() {
            document.getElementById('customerSearch').value = '';
            CustomerModal.currentSearch = '';
            CustomerModal.currentPage = 1;
            CustomerModal.loadCustomers();
        });

        // Add clear selection button next to select customer button
        const clearSelectionBtn = document.createElement('button');
        clearSelectionBtn.type = 'button';
        clearSelectionBtn.className = 'btn btn-outline-secondary ml-2';
        clearSelectionBtn.innerHTML = '<i class="feather icon-x"></i> Clear Selection';
        clearSelectionBtn.style.marginLeft = '10px';
        clearSelectionBtn.style.border = '1px solid #6c757d';
        clearSelectionBtn.addEventListener('click', function() {
            CustomerManager.clearFields();
            this.blur();
        });
        selectBtn.parentNode.appendChild(clearSelectionBtn);
    },

    escapeHtml: (str) => {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    },

    loadCustomers: () => {
        const tbody = document.getElementById('customerTableBody');
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:20px;"><i class="feather icon-loader" style="animation: spin 1s linear infinite;"></i> Loading customers...</td></tr>';

        const params = new URLSearchParams();
        params.append('page', CustomerModal.currentPage);
        params.append('search', CustomerModal.currentSearch);
        params.append('tenant_id', CustomerModal.selectedTenantId);

        fetch(`get_customers_ajax.php?${params.toString()}`)
            .then(response => response.json())
            .then(data => {
                CustomerModal.renderCustomers(data.customers);
                CustomerModal.renderPagination(data.pagination);
                CustomerModal.renderCountInfo(data.pagination);
            })
            .catch(error => {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:20px; color:#dc3545;">Error loading customers. Please try again.</td></tr>';
                console.error('Error loading customers:', error);
            });
    },

    renderCustomers: (customers) => {
        const tbody = document.getElementById('customerTableBody');

        if (!customers || customers.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:20px; color:#6c757d;">No customers found</td></tr>';
            return;
        }

        let html = '';
        customers.forEach(c => {
            const phone2Html = c.phone_2 
                ? `<div class="phone-number-2" style="color: #6c757d; font-size: 0.9em;">${CustomerModal.escapeHtml(c.phone_2)}</div>` 
                : '';

            html += `<tr class="customer-row"
                        data-customer-id="${CustomerModal.escapeHtml(String(c.customer_id))}"
                        data-name="${CustomerModal.escapeHtml(c.name)}"
                        data-email="${CustomerModal.escapeHtml(c.email)}"
                        data-phone="${CustomerModal.escapeHtml(c.phone)}"
                        data-phone-2="${CustomerModal.escapeHtml(c.phone_2)}"
                        data-address-line1="${CustomerModal.escapeHtml(c.address_line1)}"
                        data-address-line2="${CustomerModal.escapeHtml(c.address_line2)}"
                        data-city-name="${CustomerModal.escapeHtml(c.city_name)}"
                        data-city-id="${CustomerModal.escapeHtml(String(c.city_id))}">
                        <td>${CustomerModal.escapeHtml(String(c.customer_id))}</td>
                        <td><div class="customer-name">${CustomerModal.escapeHtml(c.name)}</div></td>
                        <td>
                            <div class="phone-number">${CustomerModal.escapeHtml(c.phone)}</div>
                            ${phone2Html}
                            <div class="email-address" style="color: #6c757d; font-size: 0.85em;">${CustomerModal.escapeHtml(c.email)}</div>
                        </td>
                        <td>
                            <div class="address-line">${CustomerModal.escapeHtml(c.address_line1)}</div>
                            <div class="city-name" style="color: #6c757d; font-size: 0.85em;">${CustomerModal.escapeHtml(c.city_name)}</div>
                        </td>
                        <td>
                            <button type="button" class="btn btn-primary select-customer-btn">Select</button>
                        </td>
                    </tr>`;
        });

        tbody.innerHTML = html;
        CustomerModal.bindSelectButtons();
    },

    bindSelectButtons: () => {
        document.querySelectorAll(".select-customer-btn").forEach(btn => {
            btn.addEventListener('click', function() {
                const row = this.closest('tr');
                const modal = document.getElementById("customerModal");

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
                ValidationUtils.clearErrors('phone-validation-error');
                ValidationUtils.clearErrors('email-validation-error');

                // Show the customer's success rate for the selected phone number
                SuccessRate.update();

                modal.style.display = "none";
                FormValidator.validateAndToggleSubmit();
            });
        });
    },

    renderPagination: (pagination) => {
        const container = document.getElementById('customerPagination');
        const { current_page, total_pages } = pagination;

        if (total_pages <= 1) {
            container.innerHTML = '';
            return;
        }

        let html = '<div class="pagination-controls">';

        // Previous button
        html += `<button class="pagination-btn" ${current_page === 1 ? 'disabled' : ''} 
                  onclick="CustomerModal.goToPage(${current_page - 1})">&laquo; Prev</button>`;

        // Page numbers
        let startPage = Math.max(1, current_page - 2);
        let endPage = Math.min(total_pages, current_page + 2);

        if (startPage > 1) {
            html += `<button class="pagination-btn" onclick="CustomerModal.goToPage(1)">1</button>`;
            if (startPage > 2) html += '<span class="pagination-ellipsis">...</span>';
        }

        for (let i = startPage; i <= endPage; i++) {
            html += `<button class="pagination-btn ${i === current_page ? 'active' : ''}" 
                      onclick="CustomerModal.goToPage(${i})">${i}</button>`;
        }

        if (endPage < total_pages) {
            if (endPage < total_pages - 1) html += '<span class="pagination-ellipsis">...</span>';
            html += `<button class="pagination-btn" onclick="CustomerModal.goToPage(${total_pages})">${total_pages}</button>`;
        }

        // Next button
        html += `<button class="pagination-btn" ${current_page === total_pages ? 'disabled' : ''} 
                  onclick="CustomerModal.goToPage(${current_page + 1})">Next &raquo;</button>`;

        html += '</div>';
        container.innerHTML = html;
    },

    renderCountInfo: (pagination) => {
        const container = document.getElementById('customerCountInfo');
        const { current_page, per_page, total_customers } = pagination;
        const start = ((current_page - 1) * per_page) + 1;
        const end = Math.min(current_page * per_page, total_customers);
        container.textContent = `Showing ${start}–${end} of ${total_customers} customers`;
    },

    goToPage: (page) => {
        CustomerModal.currentPage = page;
        CustomerModal.loadCustomers();
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
                SuccessRate.update();
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
                SuccessRate.update();
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
                SuccessRate.update();
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
                SuccessRate.update();
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
                if (e.target.classList.contains('batch-select')) {
                    ProductManager.selectBatch(e.target.closest('tr'));
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
                    let issues = [];
                    if (!CustomerManager.validate()) issues.push('Customer information');
                    if (!DateValidator.validate()) issues.push('Order dates');
                    if (!ProductManager.validate(true)) issues.push('Product information');
                    if (!ProductManager.hasValidProduct()) issues.push('At least one complete product');

                    e.preventDefault();

                    toastManager.warning('Please fix the following issues:\n- ' + issues.join('\n- '));
                    return false;
                }

                // Disable submit button to prevent double-submits
                const submitButton = document.getElementById('submit_order');
                submitButton.disabled = true;
                submitButton.innerHTML = '<i class="feather icon-loader"></i> Creating Order...';
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