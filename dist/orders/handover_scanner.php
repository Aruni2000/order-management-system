<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) { ob_end_clean(); }
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Process AJAX Request - Log Batch Handover
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'log_handover_batch') {
    header('Content-Type: application/json');
    
    $success_trackings = isset($_POST['success_trackings']) ? trim($_POST['success_trackings']) : '';
    $failed_trackings = isset($_POST['failed_trackings']) ? trim($_POST['failed_trackings']) : '';
    $success_count = isset($_POST['success_count']) ? (int)$_POST['success_count'] : 0;
    $error_count = isset($_POST['error_count']) ? (int)$_POST['error_count'] : 0;
    $total_count = isset($_POST['total_count']) ? (int)$_POST['total_count'] : 0;
    
    if (empty($success_trackings) && empty($failed_trackings)) {
        echo json_encode(['success' => false, 'message' => 'No tracking numbers provided']);
        exit();
    }
    
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
    $action_type = 'handover_to_courier_scan';
    
    $details = "Handover Scan - Total: {$total_count}, Successful: {$success_count}, Failed: {$error_count}.";
    if (!empty($success_trackings)) {
        $details .= " Success: {$success_trackings}.";
    }
    if (!empty($failed_trackings)) {
        $details .= " Failed: {$failed_trackings}.";
    }
    
    $logSql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, ?, 0, ?, NOW())";
    $logStmt = $conn->prepare($logSql);
    $logStmt->bind_param("iss", $user_id, $action_type, $details);
    
    if ($logStmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Batch handover log recorded', 'log_id' => $logStmt->insert_id]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to record batch log']);
    }
    
    $logStmt->close();
    exit();
}

// Process AJAX Request - Process Single Handover
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_handover') {
    header('Content-Type: application/json');
    $tracking_number = isset($_POST['tracking_number']) ? trim($_POST['tracking_number']) : '';
    
    if (empty($tracking_number)) {
        echo json_encode(['success' => false, 'message' => 'Tracking number is required']);
        exit();
    }
    
    // Check if it exists and is in dispatch status (with tenant isolation)
    $session_tenant_id = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
    $is_main_admin = isset($_SESSION['is_main_admin']) ? (int)$_SESSION['is_main_admin'] : 0;
    $current_role_id = isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : 0;
    $is_super_admin = ($is_main_admin === 1 && $current_role_id === 1);
    
    if ($is_super_admin) {
        $checkSql = "SELECT order_id, status FROM order_header WHERE tracking_number = ?";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("s", $tracking_number);
    } else {
        $checkSql = "SELECT order_id, status FROM order_header WHERE tracking_number = ? AND tenant_id = ?";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("si", $tracking_number, $session_tenant_id);
    }
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Tracking number not found in system', 'tracking_number' => $tracking_number]);
        exit();
    }
    
    $order = $result->fetch_assoc();
    
    if ($order['status'] !== 'dispatch') {
        echo json_encode(['success' => false, 'message' => 'Order must be in "dispatch" status. Current status: ' . $order['status'], 'tracking_number' => $tracking_number]);
        exit();
    }
    
    // Update with tenant isolation
    if ($is_super_admin) {
        $updateHeaderSql = "UPDATE order_header SET handover_to_courier = 1, handover_time = NOW(), updated_at = updated_at WHERE tracking_number = ?";
        $updateHeaderStmt = $conn->prepare($updateHeaderSql);
        $updateHeaderStmt->bind_param("s", $tracking_number);
    } else {
        $updateHeaderSql = "UPDATE order_header SET handover_to_courier = 1, handover_time = NOW(), updated_at = updated_at WHERE tracking_number = ? AND tenant_id = ?";
        $updateHeaderStmt = $conn->prepare($updateHeaderSql);
        $updateHeaderStmt->bind_param("si", $tracking_number, $session_tenant_id);
    }
    
    if ($updateHeaderStmt->execute()) {
        // Insert user log for this individual handover
        $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
        $log_action = 'handover_to_courier_scan';
        $log_details = "Handover scan: Tracking #{$tracking_number} (Order #{$order['order_id']}) marked as handed over to courier.";
        $logSql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, ?, ?, ?, NOW())";
        $logStmt = $conn->prepare($logSql);
        $logStmt->bind_param("isis", $user_id, $log_action, $order['order_id'], $log_details);
        $logStmt->execute();
        $logStmt->close();

        echo json_encode([
            'success' => true,
            'message' => 'Handover to rider marked successfully',
            'order_info' => 'Order #' . $order['order_id'],
            'tracking_number' => $tracking_number,
            'order_id' => $order['order_id'],
            'previous_status' => $order['status']
        ]);
    } else {
         echo json_encode(['success' => false, 'message' => 'Failed to update order.', 'tracking_number' => $tracking_number]);
    }
    exit();
}
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Handover Scanner | <?= htmlspecialchars($_SESSION['company_name'] ?? '') ?></title>
    
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
        height: 60px;
    }

    .input-group textarea {
        min-height: 300px;
        resize: vertical;
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
                        <h5 class="mb-0 font-medium">Handover to Rider Scanner</h5>
                    </div>
                </div>
            </div>

            <div class="main-content-wrapper">
                
                <!-- Scanner Container -->
                <div class="scanner-container">
                    <div class="scanner-content">
                        
                        <div class="scanner-section">
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
        function processTracking() {
            const trackingInput = document.getElementById('trackingInput').value.trim();
            if (!trackingInput) {
                alert('Please enter at least one tracking number');
                return;
            }

            const trackingNumbers = trackingInput.split('\n').filter(num => num.trim() !== '');
            const total = trackingNumbers.length;
            let successCount = 0;
            let errorCount = 0;
            let successfulTrackings = [];
            let failedTrackings = [];

            // Show progress bar and status
            document.getElementById('progressBar').style.display = 'block';
            document.getElementById('processingStatus').style.display = 'block';
            document.getElementById('statsContainer').style.display = 'none';

            // Clear previous results
            document.getElementById('results').innerHTML = '';

            // Disable button during processing
            document.getElementById('processBtn').disabled = true;

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
                                successfulTrackings.push(trackingNumber.trim());
                            } else {
                                errorCount++;
                                failedTrackings.push(trackingNumber.trim());
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

                            // Show stats and log batch when complete
                            if (successCount + errorCount === total) {
                                document.getElementById('statsContainer').style.display = 'flex';
                                document.getElementById('processingStatus').style.display = 'none';
                                document.getElementById('progressBar').style.display = 'none';
                                document.getElementById('processBtn').disabled = false;
                                
                                // Create batch log entry
                                logBatchHandover(successfulTrackings.join(', '), failedTrackings.join(', '), successCount, errorCount, total);
                            }
                        } catch (e) {
                            console.error('Error parsing response:', e);
                            errorCount++;
                            failedTrackings.push(trackingNumber.trim());
                            
                            const resultDiv = document.createElement('div');
                            resultDiv.className = 'result-item result-error';
                            resultDiv.innerHTML = `
                                <div class="tracking-number">${trackingNumber}</div>
                                <div>Error processing request</div>
                            `;
                            document.getElementById('results').appendChild(resultDiv);
                            
                            document.getElementById('errorCount').textContent = errorCount;
                            document.getElementById('totalCount').textContent = total;
                            
                            if (successCount + errorCount === total) {
                                document.getElementById('statsContainer').style.display = 'flex';
                                document.getElementById('processingStatus').style.display = 'none';
                                document.getElementById('progressBar').style.display = 'none';
                                document.getElementById('processBtn').disabled = false;
                                
                                // Create batch log entry
                                logBatchHandover(successfulTrackings.join(', '), failedTrackings.join(', '), successCount, errorCount, total);
                            }
                        }
                    }
                };
                xhr.send(`action=process_handover&tracking_number=${encodeURIComponent(trackingNumber.trim())}`);
            });
        }

        function logBatchHandover(successTrackings, failedTrackings, successCount, errorCount, totalCount) {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        console.log('Batch log result:', response);
                    } catch (e) {
                        console.error('Error logging batch:', e);
                    }
                }
            };
            xhr.send(`action=log_handover_batch&success_trackings=${encodeURIComponent(successTrackings)}&failed_trackings=${encodeURIComponent(failedTrackings)}&success_count=${successCount}&error_count=${errorCount}&total_count=${totalCount}`);
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
        }
    </script>
</body>
</html>
