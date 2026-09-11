<?php
/**
 * Koombiyo Bulk Existing Parcel API Handler - CORRECTED VERSION
 * @version 3.1
 * @date 2025
 * 
 * FEATURES:
 * - ✅ CO_ID support with fallback to courier table
 * - ✅ Tenant-based tracking number filtering
 * - ✅ Multi-tenant validation
 * - ✅ Enhanced error handling and logging
 * - ✅ Proper transaction management
 * - ✅ Correct city/district from order header (oh.city_id, oh.district_id)
 * - ✅ Proper address formatting with comma+space
 * - ✅ Order sequence preservation
 * - ✅ str_replace on description/special notes
 * - ✅ Graceful fallback for insufficient tracking
 */

session_start();
header('Content-Type: application/json');
ob_start();

// Logging function
function logAction($conn, $user_id, $action, $order_id, $details) {
    $stmt = $conn->prepare("INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, ?, ?, ?, NOW())");
    if ($stmt) {
        $stmt->bind_param("isis", $user_id, $action, $order_id, $details);
        $stmt->execute();
        $stmt->close();
    }
}

// API submission function
function addKoombiyoOrder($orderData, $apiKey) {
    $url = 'https://application.koombiyodelivery.lk/api/Addorders/users';
    
    // Required fields validation
    $required = ['orderWaybillid', 'receiverName', 'receiverStreet', 'receiverDistrict', 'receiverCity', 'receiverPhone'];
    foreach ($required as $field) {
        if (empty($orderData[$field])) {
            return ['success' => false, 'error' => "Missing required field: $field"];
        }
    }
    
    $postData = [
        'apikey' => $apiKey,
        'orderWaybillid' => $orderData['orderWaybillid'],
        'orderNo' => $orderData['orderNo'],
        'receiverName' => $orderData['receiverName'],
        'receiverStreet' => $orderData['receiverStreet'],
        'receiverDistrict' => $orderData['receiverDistrict'],
        'receiverCity' => $orderData['receiverCity'],
        'receiverPhone' => $orderData['receiverPhone'],
        'description' => str_replace('#', 'No.', $orderData['description'] ?? ''),
        'spclNote' => str_replace('#', 'No.', $orderData['spclNote'] ?? ''),
        'getCod' => $orderData['getCod'] ?? '0'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'error' => 'Connection error: ' . $error];
    }
    
    $data = json_decode($response, true);
    
    // Check if API returned success
    if (isset($data['status']) && $data['status'] === 'success') {
        return ['success' => true, 'data' => $data, 'message' => 'Order successfully added'];
    } else {
        return [
            'success' => false, 
            'error' => $data['message'] ?? 'Unknown API error',
            'message' => $data['message'] ?? 'Unknown API error',
            'http_code' => $httpCode,
            'full_response' => $data
        ];
    }
}

// Get parcel description and weight
function getParcelData($orderId, $conn) {
    $stmt = $conn->prepare("SELECT GROUP_CONCAT(description SEPARATOR ', ') as description_text, SUM(quantity) as total_qty FROM order_items WHERE order_id = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    $totalItems = $result['total_qty'] ?? 1;
    $desc = "Order #$orderId - $totalItems item" . ($totalItems == 1 ? '' : 's');
    $weight = max(0.5, min(10, $totalItems * 0.5));
    
    return ['description' => $desc, 'weight' => number_format($weight, 1)];
}

try {
    include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/connection/db_connection.php');
    
    // ============================================
    // AUTHENTICATION & VALIDATION
    // ============================================
    if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
        throw new Exception('Authentication required');
    }
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method allowed');
    }
    
    if (!isset($_POST['order_ids']) || !isset($_POST['carrier_id'])) {
        throw new Exception('Missing required parameters');
    }
    
    $orderIds = json_decode($_POST['order_ids'], true);
    $carrierId = (int)$_POST['carrier_id'];
    $dispatchNotes = $_POST['dispatch_notes'] ?? '';
    $userId = $_SESSION['user_id'] ?? 0;
    
    // ============================================
    // GET CO_ID FROM POST DATA
    // ============================================
    $coId = isset($_POST['co_id']) ? trim($_POST['co_id']) : null;
    
    // Debug logging
    error_log("=== KOOMBIYO API DEBUG ===");
    error_log("Carrier ID: $carrierId");
    error_log("CO_ID received: " . ($coId ?? 'NULL'));
    error_log("Order IDs: " . json_encode($orderIds));
    
    if (!is_array($orderIds) || empty($orderIds)) {
        throw new Exception('Invalid order IDs');
    }
    
    // ============================================
    // STEP 1: GET TENANT_ID FROM FIRST ORDER
    // ============================================
    $firstOrderId = $orderIds[0];
    $stmt = $conn->prepare("SELECT tenant_id FROM order_header WHERE order_id = ?");
    $stmt->bind_param("s", $firstOrderId);
    $stmt->execute();
    $tenantResult = $stmt->get_result();
    
    if ($tenantResult->num_rows === 0) {
        throw new Exception("Order not found: $firstOrderId");
    }
    
    $tenantData = $tenantResult->fetch_assoc();
    $tenantId = (int)$tenantData['tenant_id'];
    $stmt->close();
    
    error_log("Tenant ID from order: $tenantId");

    // ============================================
    // STEP 2: GET COURIER DETAILS (USING CO_ID OR TENANT_ID)
    // ============================================
    $courier = null;
    if (!empty($coId)) {
        $stmt = $conn->prepare("
            SELECT courier_id, courier_name, co_id, api_key, client_id, tenant_id 
            FROM couriers 
            WHERE co_id = ? 
            AND status = 'active' 
            AND has_api_existing = 1
        ");
        $stmt->bind_param("i", $coId);
        $stmt->execute();
        $courier = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$courier && !empty($tenantId)) {
        $stmt = $conn->prepare("
            SELECT courier_id, courier_name, co_id, api_key, client_id, tenant_id 
            FROM couriers 
            WHERE courier_id = ? 
            AND tenant_id = ? 
            AND status = 'active' 
            AND has_api_existing = 1
        ");
        $stmt->bind_param("ii", $carrierId, $tenantId);
        $stmt->execute();
        $courier = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$courier) {
        $stmt = $conn->prepare("
            SELECT courier_id, courier_name, co_id, api_key, client_id, tenant_id 
            FROM couriers 
            WHERE courier_id = ? 
            AND status = 'active' 
            AND has_api_existing = 1
        ");
        $stmt->bind_param("i", $carrierId);
        $stmt->execute();
        $courier = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    
    if (!$courier || empty($courier['api_key'])) {
        throw new Exception('Invalid courier or missing API credentials');
    }

    // Ensure co_id and carrier_id are set from the retrieved courier
    $coId = $courier['co_id'];
    $carrierId = (int)$courier['courier_id'];
    error_log("Using Courier: {$courier['courier_name']}, CO_ID: $coId, Tenant: " . ($courier['tenant_id'] ?? 'NULL'));
    
    // ============================================
    // STEP 2: GET TRACKING NUMBERS FOR THIS TENANT ONLY
    // ============================================
    $orderCount = count($orderIds);
    $stmt = $conn->prepare("
        SELECT tracking_id 
        FROM tracking 
        WHERE courier_id = ? 
        AND tenant_id = ? 
        AND status = 'unused' 
        ORDER BY id ASC 
        LIMIT ?
    ");
    $stmt->bind_param("iii", $carrierId, $tenantId, $orderCount);
    $stmt->execute();
    $tracking = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    error_log("Found " . count($tracking) . " tracking numbers for tenant $tenantId, courier $carrierId");
    
    // Graceful fallback if insufficient tracking numbers
    $failedOrders = [];
    if (count($tracking) === 0) {
        throw new Exception("No unused tracking numbers available for this courier and tenant");
    }
    
    if (count($tracking) < $orderCount) {
        $availableCount = count($tracking);
        $skippedOrderIds = array_slice($orderIds, $availableCount);
        $orderIds = array_slice($orderIds, 0, $availableCount);
        $orderCount = count($orderIds);
        
        foreach ($skippedOrderIds as $skippedId) {
            $failedOrders[] = [
                'order_id' => $skippedId,
                'tracking_number' => 'N/A',
                'error' => 'Insufficient tracking numbers available'
            ];
            logAction($conn, $userId, 'api_existing_dispatch_failed', $skippedId,
                "Order $skippedId skipped - Insufficient unused tracking numbers");
        }
    }
    
    // ============================================
    // STEP 3: GET ORDERS (corrected joins)
    // ============================================
    $placeholders = str_repeat('?,', $orderCount - 1) . '?';
    $stmt = $conn->prepare("
        SELECT oh.*, 
               c.name as customer_name, 
               c.phone as customer_phone, 
               c.address_line1 as customer_address1, 
               c.address_line2 as customer_address2, 
               ct.city_name,
               dt.district_name
        FROM order_header oh 
        LEFT JOIN customers c ON oh.customer_id = c.customer_id 
        LEFT JOIN city_table ct ON oh.city_id = ct.city_id
        LEFT JOIN district_table dt ON oh.district_id = dt.district_id
        WHERE oh.order_id IN ($placeholders) 
        AND oh.status = 'pending'
    ");
    $stmt->bind_param(str_repeat('i', $orderCount), ...$orderIds);
    $stmt->execute();
    $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    if (empty($orders)) {
        throw new Exception('No valid pending orders found');
    }
    
    // ============================================
    // STEP 4: PRESERVE ORDER SEQUENCE
    // ============================================
    $orderedResults = [];
    $orderMap = [];
    foreach ($orders as $order) {
        $orderMap[$order['order_id']] = $order;
    }
    foreach ($orderIds as $id) {
        if (isset($orderMap[$id])) {
            $orderedResults[] = $orderMap[$id];
        }
    }
    $orders = $orderedResults;
    
    // ============================================
    // STEP 5: VERIFY ALL ORDERS BELONG TO SAME TENANT
    // ============================================
    foreach ($orders as $order) {
        if ((int)$order['tenant_id'] !== $tenantId) {
            throw new Exception("Order {$order['order_id']} belongs to different tenant ({$order['tenant_id']} vs $tenantId). Cannot process orders from multiple tenants.");
        }
    }
    
    error_log("All orders verified for tenant $tenantId");
    
    // ============================================
    // PROCESS ORDERS
    // ============================================
    $conn->begin_transaction();
    
    $successCount = 0;
    $processedOrders = [];
    
    foreach ($orders as $index => $order) {
        $orderId = $order['order_id'];
        $trackingNumber = $tracking[$index]['tracking_id'];
        
        try {
            $parcelData = getParcelData($orderId, $conn);
            
            $districtName = trim($order['district_name'] ?? '');
            $cityName = trim($order['city_name'] ?? '');
            
            if (empty($districtName)) $districtName = 'Colombo';
            if (empty($cityName)) $cityName = 'Colombo';
            
            // Determine COD amount based on pay_status
            $codAmount = ($order['pay_status'] === 'paid') ? '0' : (string)$order['total_amount'];
            
            // Prepare full address with comma+space separator
            $addr1 = trim($order['address_line1'] ?? '');
            $addr2 = trim($order['address_line2'] ?? '');
            if (empty($addr1)) {
                $addr1 = trim($order['customer_address1'] ?? '');
                if (empty($addr2)) {
                    $addr2 = trim($order['customer_address2'] ?? '');
                }
            }
            
            $fullAddress = trim($addr1 . ($addr2 ? ', ' . $addr2 : ''));
            if ($cityName && strpos($fullAddress, $cityName) === false) {
                $fullAddress .= ', ' . $cityName;
            }
            
            $orderData = [
                'orderWaybillid' => $trackingNumber,
                'orderNo' => (string)$orderId,
                'receiverName' => $order['full_name'] ?: $order['customer_name'],
                'receiverStreet' => $fullAddress,
                'receiverDistrict' => $districtName,
                'receiverCity' => $cityName,
                'receiverPhone' => $order['mobile'] ?: $order['customer_phone'],
                'description' => $parcelData['description'],
                'spclNote' => $dispatchNotes ?: 'Bulk dispatch order',
                'getCod' => $codAmount
            ];
            
            error_log("DEBUG - Order $orderId: Tracking=$trackingNumber, COD=$codAmount, District=$districtName, City=$cityName");
            
            // Submit to Koombiyo API
            $result = addKoombiyoOrder($orderData, $courier['api_key']);
            
            if ($result['success']) {
                // ============================================
                // UPDATE ORDER_HEADER WITH CO_ID
                // ============================================
                $stmt = $conn->prepare("
                    UPDATE order_header 
                    SET status = 'dispatch', 
                        courier_id = ?, 
                        co_id = ?,
                        tracking_number = ?, 
                        dispatch_note = ?, 
                        updated_at = NOW() 
                    WHERE order_id = ?
                ");
                $stmt->bind_param("isssi", $carrierId, $coId, $trackingNumber, $dispatchNotes, $orderId);
                $stmt->execute();
                $stmt->close();
                
                error_log("Order $orderId updated - CO_ID: $coId, Tracking: $trackingNumber");
                
                // Update tracking status
                $stmt = $conn->prepare("
                    UPDATE tracking 
                    SET status = 'used', 
                        updated_at = NOW() 
                    WHERE tracking_id = ? 
                    AND courier_id = ?
                ");
                $stmt->bind_param("si", $trackingNumber, $carrierId);
                $stmt->execute();
                $stmt->close();
                
                // Update order items
                $stmt = $conn->prepare("UPDATE order_items SET status = 'dispatch' WHERE order_id = ?");
                $stmt->bind_param("i", $orderId);
                $stmt->execute();
                $stmt->close();
                
                logAction($conn, $userId, 'api_existing_dispatch', $orderId, 
                    "Order $orderId dispatched via Koombiyo - Tenant: $tenantId, CO_ID: $coId, Tracking: $trackingNumber, Status: {$result['message']}");
                
                $successCount++;
                $processedOrders[] = [
                    'order_id' => $orderId, 
                    'tracking_number' => $trackingNumber,
                    'co_id' => $coId,
                    'tenant_id' => $tenantId
                ];
                
            } else {
                $failedOrders[] = [
                    'order_id' => $orderId,
                    'tracking_number' => $trackingNumber,
                    'error' => $result['error'] ?? $result['message'] ?? 'Unknown error'
                ];
                
                logAction($conn, $userId, 'api_existing_dispatch_failed', $orderId,
                    "Order $orderId failed via Koombiyo - Error: " . ($result['error'] ?? $result['message'] ?? 'Unknown error'));
            }
            
        } catch (Exception $e) {
            $failedOrders[] = [
                'order_id' => $orderId,
                'tracking_number' => $trackingNumber,
                'error' => $e->getMessage()
            ];
            
            logAction($conn, $userId, 'api_existing_dispatch_failed', $orderId,
                "Order $orderId exception via Koombiyo - Error: {$e->getMessage()}");
        }
    }
    
    // ============================================
    // COMMIT OR ROLLBACK
    // ============================================
    if ($successCount > 0) {
        $conn->commit();
        
        $trackingList = implode(', ', array_column($processedOrders, 'tracking_number'));
        $details = "Koombiyo bulk dispatch: $successCount/" . $orderCount . " orders dispatched, Tenant: $tenantId, CO_ID: $coId, Tracking: $trackingList";
        
        if (!empty($failedOrders)) {
            $errorList = array_map(fn($f) => "Order {$f['order_id']}: {$f['error']}", $failedOrders);
            $details .= ". Failed: " . implode('; ', $errorList);
        }
        
        logAction($conn, $userId, 'bulk_api_existing_dispatch', 0, $details);
    } else {
        $conn->rollback();
        
        $errorList = array_map(fn($f) => "Order {$f['order_id']}: {$f['error']}", $failedOrders);
        logAction($conn, $userId, 'bulk_api_existing_dispatch_failed', 0, 
            "Koombiyo bulk dispatch failed: All " . $orderCount . " orders failed. Errors: " . implode('; ', $errorList));
    }
    
    // ============================================
    // BUILD RESPONSE
    // ============================================
    $response = [
        'success' => $successCount > 0,
        'processed_count' => $successCount,
        'total_count' => $orderCount,
        'failed_count' => count($failedOrders),
        'processed_orders' => $processedOrders,
        'tenant_id' => $tenantId,
        'co_id' => $coId
    ];
    
    if (!empty($failedOrders)) {
        $response['failed_orders'] = $failedOrders;
        $response['message'] = "Processed $successCount orders successfully, " . count($failedOrders) . " failed";
    } else {
        $response['message'] = "All $successCount orders processed successfully via Koombiyo API";
    }
    
    ob_clean();
    echo json_encode($response);
    
} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    
    error_log("Koombiyo API Error: " . $e->getMessage());
    
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} finally {
    if (isset($conn)) {
        $conn->autocommit(true);
    }
    ob_end_flush();
}
?>