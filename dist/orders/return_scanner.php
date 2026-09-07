<?php
/**
 * Return Complete Scanner System
 * This page allows scanning tracking numbers to update return_handover status
 * Includes batch processing and status tracking functionality
 * Updated to handle both order_header and order_items tables
 */

// Start session management
session_start();

// Authentication check - redirect if not logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // Clear output buffers before redirect
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Session role context (main admin vs tenant-restricted users)
$is_main_admin = isset($_SESSION['is_main_admin']) ? (int)$_SESSION['is_main_admin'] : 0;
$role_id = isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : 0;
$session_tenant_id = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;

/**
 * FETCH COURIERS AJAX REQUEST (for the courier dropdown)
 */
if (isset($_GET['action']) && $_GET['action'] === 'get_couriers' && isset($_GET['tenant_id'])) {
    header('Content-Type: application/json');
    $tenantId = intval($_GET['tenant_id']);
    $sql = "SELECT co_id, courier_id, courier_name 
            FROM couriers 
            WHERE tenant_id = ? AND status = 'active' 
            ORDER BY courier_name ASC";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Database error']);
        exit();
    }
    
    $stmt->bind_param("i", $tenantId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $couriers = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $couriers[] = [
                'co_id' => $row['co_id'],
                'courier_id' => $row['courier_id'],
                'courier_name' => $row['courier_name'],
                'display_name' => $row['courier_name'] . ' (ID: ' . $row['courier_id'] . ')'
            ];
        }
    }
    $stmt->close();
    echo json_encode(['success' => true, 'couriers' => $couriers]);
    exit();
}

/**
 * PROCESS TRACKING NUMBERS AJAX REQUEST
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_tracking') {
    header('Content-Type: application/json');
    
    $tracking_number = isset($_POST['tracking_number']) ? trim($_POST['tracking_number']) : '';
    $scan_mode = isset($_POST['scan_mode']) ? trim($_POST['scan_mode']) : 'return complete';
    $co_id = isset($_POST['co_id']) ? (int)$_POST['co_id'] : 0;
    $tenant_id = isset($_POST['tenant_id']) ? (int)$_POST['tenant_id'] : 0;
    
    if (empty($tracking_number)) {
        echo json_encode(['success' => false, 'message' => 'Tracking number is required']);
        exit();
    }
    
    if (empty($co_id)) {
        echo json_encode(['success' => false, 'message' => 'Please select a courier first']);
        exit();
    }
    
    try {
        if ($scan_mode === 'test_mode') {
            // Test mode - simulate processing without database changes
            sleep(1); // Simulate processing time
            echo json_encode([
                'success' => true,
                'message' => 'Test mode - No database changes made',
                'order_info' => 'Order #' . rand(1000, 9999) . ' - Items: ' . rand(1, 5),
                'tracking_number' => $tracking_number
            ]);
        } else {
            // Live mode - update database
            
            // First, check if tracking number exists in orders (scoped to courier + tenant)
            if ($is_main_admin === 1 && $role_id === 1) {
                $checkSql = "SELECT order_id, status, tracking_number FROM order_header WHERE tracking_number = ? AND co_id = ? LIMIT 1";
                $checkStmt = $conn->prepare($checkSql);
                $checkStmt->bind_param("si", $tracking_number, $co_id);
            } else {
                $checkSql = "SELECT order_id, status, tracking_number FROM order_header WHERE tracking_number = ? AND co_id = ? AND tenant_id = ? LIMIT 1";
                $checkStmt = $conn->prepare($checkSql);
                $checkStmt->bind_param("sii", $tracking_number, $co_id, $session_tenant_id);
            }
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            
            if ($result->num_rows === 0) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Tracking number not found in system',
                    'tracking_number' => $tracking_number
                ]);
                exit();
            }
            
            $order = $result->fetch_assoc();
            
            // Check if order is eligible for return_handover status
            if ($order['status'] !== 'return complete') {
                echo json_encode([
                    'success' => false,
                    'message' => 'Order status must be "return complete" to update to "return_handover". Current status: ' . $order['status'],
                    'tracking_number' => $tracking_number
                ]);
                exit();
            }
            
            // Start transaction for data integrity
            $conn->begin_transaction();
            
            try {
                // Update order_header status to return_handover (scoped to the found order)
                $updateHeaderSql = "UPDATE order_header SET status = 'return_handover', updated_at = NOW() WHERE order_id = ? AND status = 'return complete'";
                $updateHeaderStmt = $conn->prepare($updateHeaderSql);
                $updateHeaderStmt->bind_param("i", $order['order_id']);
                
                if (!$updateHeaderStmt->execute()) {
                    throw new Exception("Failed to update order_header: " . $conn->error);
                }
                
                // Update all order_items for this order to return_handover status
                $updateItemsSql = "UPDATE order_items SET status = 'return_handover', updated_at = NOW() WHERE order_id = ?";
                $updateItemsStmt = $conn->prepare($updateItemsSql);
                $updateItemsStmt->bind_param("i", $order['order_id']);
                
                if (!$updateItemsStmt->execute()) {
                    throw new Exception("Failed to update order_items: " . $conn->error);
                }
                
                // Get updated counts (Header update)
                // INVENTORY IMPLEMENTATION
                $inventoryUpdatedCount = 0;
                if (isset($_SESSION['allow_inventory']) && $_SESSION['allow_inventory'] == 1) {
                    // Get order items to update inventory
                    $getItemsSql = "SELECT product_id, quantity, item_id FROM order_items WHERE order_id = ?";
                    $itemsStmt = $conn->prepare($getItemsSql);
                    $itemsStmt->bind_param("i", $order['order_id']);
                    $itemsStmt->execute();
                    $itemsResult = $itemsStmt->get_result();

                    // Check whether this order used batch-level deduction
                    $checkAutoBatchesSql = "SELECT 1 FROM order_item_batches WHERE order_id = ? LIMIT 1";
                    $checkAutoBatchesStmt = $conn->prepare($checkAutoBatchesSql);
                    $checkAutoBatchesStmt->bind_param("i", $order['order_id']);
                    $checkAutoBatchesStmt->execute();
                    $isBatchAware = $checkAutoBatchesStmt->get_result()->num_rows > 0;
                    $checkAutoBatchesStmt->close();

                    $updateBatch = null;
                    if ($isBatchAware) {
                        if ($is_main_admin) {
                            $updateBatch = $conn->prepare("UPDATE batches SET remaining_qty = remaining_qty + ? WHERE batch_id = ?");
                        } else {
                            $updateBatch = $conn->prepare("UPDATE batches SET remaining_qty = remaining_qty + ? WHERE batch_id = ? AND tenant_id = ?");
                        }
                    }

                    while ($item = $itemsResult->fetch_assoc()) {
                        $productId = $item['product_id'];
                        $quantity = $item['quantity'];

                        // Restore specific batches that fulfilled this item
                        if ($isBatchAware) {
                            $oibSql = "SELECT batch_id, quantity FROM order_item_batches WHERE order_item_id = ?";
                            $oibStmt = $conn->prepare($oibSql);
                            $oibStmt->bind_param("i", $item['item_id']);
                            $oibStmt->execute();
                            $oibResult = $oibStmt->get_result();
                            while ($oib = $oibResult->fetch_assoc()) {
                                if ($is_main_admin) {
                                    $updateBatch->bind_param("ii", $oib['quantity'], $oib['batch_id']);
                                } else {
                                    $updateBatch->bind_param("iii", $oib['quantity'], $oib['batch_id'], $session_tenant_id);
                                }
                                if (!$updateBatch->execute()) {
                                    throw new Exception("Failed to restore batch stock.");
                                }
                            }
                            $oibStmt->close();
                        }

                        // Update stock - Increment stock for returned items (with tenant isolation)
                        $updateStockSql = "UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ? AND tenant_id = ?";
                        $stockStmt = $conn->prepare($updateStockSql);
                        $stockStmt->bind_param("iii", $quantity, $productId, $tenant_id);
                        
                        if (!$stockStmt->execute()) {
                             throw new Exception("Failed to update stock for product ID: " . $productId);
                        }
                        $inventoryUpdatedCount++;
                        $stockStmt->close();
                    }
                    if ($updateBatch) $updateBatch->close();
                    $itemsStmt->close();
                }
                
                // Get updated counts
                $itemsUpdated = $updateItemsStmt->affected_rows;
                
                // Log user action
                $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
                $action_type = 'return_handover_scan';
                $inquiry_id = $order['order_id']; // Using order_id as inquiry_id
                $details = json_encode([
                    'tracking_number' => $tracking_number,
                    'previous_status' => 'return complete',
                    'new_status' => 'return_handover',
                    'items_updated' => $itemsUpdated,
                    'inventory_updated_count' => $inventoryUpdatedCount,
                    'scan_method' => 'bulk_scanner',
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
                ]);
                
                $logSql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, ?, ?, ?, NOW())";
                $logStmt = $conn->prepare($logSql);
                $logStmt->bind_param("isis", $user_id, $action_type, $inquiry_id, $details);
                
                if (!$logStmt->execute()) {
                    throw new Exception("Failed to insert user log: " . $conn->error);
                }
                
                // Commit transaction
                $conn->commit();
                
                // Get order details for response
                $orderDetailsSql = "SELECT o.order_id, o.total_amount, c.name as customer_name,
                                           COUNT(oi.item_id) as total_items
                                   FROM order_header o 
                                   LEFT JOIN customers c ON o.customer_id = c.customer_id 
                                   LEFT JOIN order_items oi ON o.order_id = oi.order_id
                                   WHERE o.order_id = ?
                                   GROUP BY o.order_id";
                $detailsStmt = $conn->prepare($orderDetailsSql);
                $detailsStmt->bind_param("i", $order['order_id']);
                $detailsStmt->execute();
                $orderDetails = $detailsStmt->get_result()->fetch_assoc();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Status updated to return_handover successfully',
                    'order_info' => sprintf(
                        'Order #%d - Customer: %s - Amount: Rs%s - Items Updated: %d - Action Logged',
                        $orderDetails['order_id'],
                        $orderDetails['customer_name'] ?: 'N/A',
                        number_format($orderDetails['total_amount'], 2),
                        $itemsUpdated
                    ),
                    'tracking_number' => $tracking_number,
                    'items_updated' => $itemsUpdated,
                    'action_logged' => true
                ]);
                
            } catch (Exception $e) {
                // Rollback transaction on error
                $conn->rollback();
                throw $e;
            }
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'System error: ' . $e->getMessage(),
            'tracking_number' => $tracking_number
        ]);
    }
    
    exit();
}

// Fetch tenants for the dropdown
$tenants = [];
if ($is_main_admin === 1 && $role_id === 1) {
    // Main Admin gets all active tenants
    $tenantResult = $conn->query("SELECT tenant_id, company_name FROM tenants WHERE status = 'active' ORDER BY company_name");
} else {
    // Others get only their assigned tenant
    $tenantStmt = $conn->prepare("SELECT tenant_id, company_name FROM tenants WHERE tenant_id = ? AND status = 'active' LIMIT 1");
    $tenantStmt->bind_param("i", $session_tenant_id);
    $tenantStmt->execute();
    $tenantResult = $tenantStmt->get_result();
}
if ($tenantResult && $tenantResult->num_rows > 0) {
    while ($row = $tenantResult->fetch_assoc()) {
        $tenants[] = $row;
    }
}
$restricted_tenant_id = (count($tenants) === 1 && !($is_main_admin === 1 && $role_id === 1)) ? $tenants[0]['tenant_id'] : 0;

?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Return Scanner | <?= htmlspecialchars($_SESSION['company_name'] ?? '') ?></title>
    
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="../assets/css/orders.css" />
</head>

<style>
    /* Scanner-specific styling */
    .scanner-container {
        background: white;
        border-radius: 15px;
        box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        overflow: hidden;
        margin-bottom: 30px;
    }

    .scanner-content {
        padding: 40px;
    }

    .input-group {
        margin-bottom: 20px;
    }

    .input-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: #333;
    }

    .input-group textarea, .input-group select {
        width: 100%;
        padding: 15px;
        border: 2px solid #e9ecef;
        border-radius: 8px;
        font-size: 16px;
        transition: border-color 0.3s ease;
    }

    .input-group select {
        height: 42px;
        padding: 8px 12px;
        font-size: 14px;
        cursor: pointer;
    }

    .input-group textarea {
        min-height: 300px;
        resize: vertical;
    }

    /* Tenant + Courier side by side */
    .tenant-courier-row {
        display: flex;
        gap: 20px;
    }

    .tenant-courier-row .input-group {
        flex: 1;
        min-width: 0;
    }

    .input-group textarea:focus, .input-group select:focus {
        outline: none;
        border-color: #4facfe;
        box-shadow: 0 0 0 3px rgba(79, 172, 254, 0.1);
    }

    .scan-btn {
        background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
        color: white;
        border: none;
        padding: 10px 21px;
        border-radius: 8px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        width: 25%;
        margin-bottom: 20px;
    }

    .scan-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
    }

    .scan-btn:disabled {
        background: #6c757d;
        cursor: not-allowed;
        transform: none;
    }

    .clear-btn {
        background: #6c757d;
        color: white;
        border: none;
        padding: 10px 21px;
        border-radius: 8px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        width: 15%;
        margin-bottom: 20px;
    }

    .clear-btn:hover {
        background: #5a6268;
        transform: translateY(-2px);
    }

    /* Progress bar styling */
    .progress-bar {
        width: 100%;
        height: 8px;
        background-color: #e9ecef;
        border-radius: 4px;
        overflow: hidden;
        margin-bottom: 20px;
        display: none;
    }

    .progress-fill {
        height: 100%;
        background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
        border-radius: 4px;
        transition: width 0.3s ease;
        width: 0%;
    }

    /* Results styling */
    .results {
        margin-top: 20px;
    }

    .result-item {
        padding: 15px;
        margin: 10px 0;
        border-radius: 8px;
        border-left: 4px solid;
        background: #f8f9fa;
    }

    .result-success {
        border-color: #28a745;
        background: #d4edda;
        color: #155724;
    }

    .result-error {
        border-color: #dc3545;
        background: #f8d7da;
        color: #721c24;
    }

    .result-info {
        border-color: #17a2b8;
        background: #d1ecf1;
        color: #0c5460;
    }

    .tracking-number {
        font-weight: bold;
        font-family: monospace;
    }

    .order-info {
        font-size: 0.9em;
        margin-top: 5px;
        opacity: 0.8;
    }

    .processing-status {
        text-align: center;
        padding: 20px;
        background: #f8f9fa;
        border-radius: 8px;
        margin: 20px 0;
        display: none;
    }

    .stats-container {
        display: flex;
        justify-content: space-around;
        margin: 20px 0;
        padding: 20px;
        background: #f8f9fa;
        border-radius: 8px;
    }

    .stat-item {
        text-align: center;
    }

    .stat-number {
        font-size: 2em;
        font-weight: bold;
        color: #667eea;
    }

    .stat-label {
        font-size: 0.9em;
        color: #666;
    }

    @media (max-width: 575.98px) {
        .scanner-content {
            padding: 20px;
        }

        .tenant-courier-row {
            flex-direction: column;
        }

        .scan-btn {
            width: 100%;
        }

        .clear-btn {
            width: 100%;
        }
    }
</style>

<body>
    <!-- Page Loader -->
    <?php 
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/loader.php');
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');
    ?>

    <div class="pc-container">
        <div class="pc-content">
            
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">Return Scanner</h5>
                    </div>
                </div>
            </div>

            <div class="main-content-wrapper">
                
                <!-- Scanner Container -->
                <div class="scanner-container">
                    <div class="scanner-content">
                        
                        <div class="scanner-section">
                            <div class="tenant-courier-row">
                                <?php if ($restricted_tenant_id > 0): ?>
                                    <input type="hidden" id="tenant_id" name="tenant_id" value="<?php echo $restricted_tenant_id; ?>">
                                <?php else: ?>
                                <div class="input-group">
                                    <label for="tenant_id">Select Tenant <span style="color: #dc3545;">*</span></label>
                                    <select id="tenant_id" name="tenant_id" required>
                                        <option value="">Select Tenant</option>
                                        <?php foreach ($tenants as $tenant): ?>
                                            <option value="<?php echo $tenant['tenant_id']; ?>">
                                                <?php echo htmlspecialchars($tenant['company_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>

                                <div class="input-group">
                                    <label for="co_id">Select Courier <span style="color: #dc3545;">*</span></label>
                                    <select id="co_id" name="co_id" required disabled>
                                        <option value=""><?php echo $restricted_tenant_id > 0 ? 'Loading Couriers...' : 'Select Tenant First'; ?></option>
                                    </select>
                                </div>
                            </div>

                            <div class="input-group">
                                <label for="trackingInput">Enter Tracking Numbers</label>
                                <textarea id="trackingInput" rows="12" placeholder="Enter tracking numbers here (one per line or scan multiple)..."></textarea>
                            </div>
                            
                            <div style="display: flex; gap: 15px; justify-content: flex-end;">
                                <button class="scan-btn" id="processBtn" onclick="processTracking()">
                                Process Tracking Numbers
                                </button>
                                <button class="clear-btn" id="clearBtn" onclick="clearTracking()">
                                Clear
                                </button>
                            </div>

                            <div class="progress-bar" id="progressBar">
                                <div class="progress-fill" id="progressFill"></div>
                            </div>

                            <div class="processing-status" id="processingStatus">
                                <div>Processing tracking numbers...</div>
                                <div id="currentTracking"></div>
                            </div>

                            <div class="stats-container" id="statsContainer" style="display: none;">
                                <div class="stat-item">
                                    <div class="stat-number" id="successCount">0</div>
                                    <div class="stat-label">Successful</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-number" id="errorCount">0</div>
                                    <div class="stat-label">Errors</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-number" id="totalCount">0</div>
                                    <div class="stat-label">Total</div>
                                </div>
                            </div>
                        </div>
                        <div class="results" id="results"></div>
                    </div>
                </div>
                
            </div>
        </div>
    </div>

    <!-- Footer -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php'); ?>

    <!-- Include JavaScript files -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php'); ?>
    
    <script>
        // JavaScript functions for scanner functionality
        function processTracking() {
            const courierSelect = document.getElementById('co_id');
            if (!courierSelect.value) {
                alert('Please select a courier before scanning.');
                courierSelect.focus();
                return;
            }
            const tenantSelect = document.getElementById('tenant_id');

            const trackingInput = document.getElementById('trackingInput').value.trim();
            if (!trackingInput) {
                alert('Please enter at least one tracking number');
                return;
            }

            const trackingNumbers = trackingInput.split('\n').filter(num => num.trim() !== '');
            const total = trackingNumbers.length;
            let successCount = 0;
            let errorCount = 0;

            // Lock the tracking input & process button while scanning
            // (tenant/courier selections stay as they are)
            document.getElementById('trackingInput').disabled = true;
            document.getElementById('processBtn').disabled = true;

            // Show progress bar and status
            document.getElementById('progressBar').style.display = 'block';
            document.getElementById('processingStatus').style.display = 'block';
            document.getElementById('statsContainer').style.display = 'none';

            // Clear previous results
            document.getElementById('results').innerHTML = '';

            // Process each tracking number
            trackingNumbers.forEach((trackingNumber, index) => {
                const currentTrackingElement = document.getElementById('currentTracking');
                currentTrackingElement.textContent = `Processing ${index + 1} of ${total}: ${trackingNumber}`;

                // Update progress bar
                const progress = ((index + 1) / total) * 100;
                document.getElementById('progressFill').style.width = `${progress}%`;

                // Make AJAX request
                const xhr = new XMLHttpRequest();
                xhr.open('POST', '', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                successCount++;
                            } else {
                                errorCount++;
                            }

                            // Update results
                            const resultDiv = document.createElement('div');
                            resultDiv.className = `result-item ${response.success ? 'result-success' : 'result-error'}`;
                            resultDiv.innerHTML = `
                                <div class="tracking-number">${trackingNumber}</div>
                                <div>${response.message}</div>
                                ${response.order_info ? `<div class="order-info">${response.order_info}</div>` : ''}
                            `;
                            document.getElementById('results').appendChild(resultDiv);

                            // Update counters
                            document.getElementById('successCount').textContent = successCount;
                            document.getElementById('errorCount').textContent = errorCount;
                            document.getElementById('totalCount').textContent = total;

                            // Show stats when complete
                            if (successCount + errorCount === total) {
                                document.getElementById('statsContainer').style.display = 'flex';
                                document.getElementById('processingStatus').style.display = 'none';
                                document.getElementById('progressBar').style.display = 'none';
                                // Unlock inputs for the next batch
                                document.getElementById('trackingInput').disabled = false;
                                document.getElementById('processBtn').disabled = false;
                            }
                        } catch (e) {
                            console.error('Error parsing response:', e);
                            errorCount++;
                            
                            // Update error result
                            const resultDiv = document.createElement('div');
                            resultDiv.className = 'result-item result-error';
                            resultDiv.innerHTML = `
                                <div class="tracking-number">${trackingNumber}</div>
                                <div>Error processing request</div>
                            `;
                            document.getElementById('results').appendChild(resultDiv);
                            
                            // Update counters
                            document.getElementById('errorCount').textContent = errorCount;
                            document.getElementById('totalCount').textContent = total;
                            
                            // Show stats when complete
                            if (successCount + errorCount === total) {
                                document.getElementById('statsContainer').style.display = 'flex';
                                document.getElementById('processingStatus').style.display = 'none';
                                document.getElementById('progressBar').style.display = 'none';
                                // Unlock inputs for the next batch
                                document.getElementById('trackingInput').disabled = false;
                                document.getElementById('processBtn').disabled = false;
                            }
                        }
                    }
                };
                xhr.send(`action=process_tracking&tracking_number=${encodeURIComponent(trackingNumber.trim())}&co_id=${encodeURIComponent(courierSelect.value)}&tenant_id=${encodeURIComponent(tenantSelect.value)}`);
            });
        }

        function clearTracking() {
            document.getElementById('trackingInput').value = '';
            document.getElementById('results').innerHTML = '';
            document.getElementById('progressBar').style.display = 'none';
            document.getElementById('processingStatus').style.display = 'none';
            document.getElementById('statsContainer').style.display = 'none';

            document.getElementById('successCount').textContent = '0';
            document.getElementById('errorCount').textContent = '0';
            document.getElementById('totalCount').textContent = '0';

            if (document.getElementById('processBtn')) {
                document.getElementById('processBtn').disabled = false;
            }
            if (document.getElementById('trackingInput')) {
                document.getElementById('trackingInput').disabled = false;
            }
        }

        // Load couriers when tenant is selected
        document.getElementById('tenant_id').addEventListener('change', function() {
            const tenantId = this.value;
            const courierSelect = document.getElementById('co_id');

            courierSelect.innerHTML = '<option value="">Loading Couriers...</option>';
            courierSelect.disabled = true;

            if (tenantId) {
                fetch('return_scanner.php?action=get_couriers&tenant_id=' + tenantId)
                    .then(response => response.json())
                    .then(data => {
                        courierSelect.innerHTML = '<option value="">Select Courier</option>';

                        if (data.success && data.couriers.length > 0) {
                            data.couriers.forEach(courier => {
                                const option = document.createElement('option');
                                option.value = courier.co_id;
                                option.textContent = courier.display_name;
                                courierSelect.appendChild(option);
                            });
                            courierSelect.disabled = false;
                        } else {
                            courierSelect.innerHTML = '<option value="">No Couriers Available</option>';
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching couriers:', error);
                        courierSelect.innerHTML = '<option value="">Error Loading Couriers</option>';
                    });
            } else {
                courierSelect.innerHTML = '<option value="">Select Tenant First</option>';
            }
        });

        // Auto-load couriers if tenant is pre-selected (restricted users)
        document.addEventListener('DOMContentLoaded', function() {
            const tenantSelect = document.getElementById('tenant_id');
            if (tenantSelect && tenantSelect.value) {
                tenantSelect.dispatchEvent(new Event('change'));
            }
        });
    </script>
</body>
</html>