<?php
/**
 * Koombiyo Bulk Existing Parcel API Handler - COMPLETE FIXED VERSION
 * @version 3.0
 * @date 2025
 * 
 * FEATURES:
 * - ✅ CO_ID support with fallback to courier table
 * - ✅ Tenant-based tracking number filtering
 * - ✅ Multi-tenant validation
 * - ✅ Enhanced error handling and logging
 * - ✅ Proper transaction management
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
function addKoombiyoOrder($orderData, $apiKey = 'muABqMKZgkaZDAnbBWev') {
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
        'description' => $orderData['description'] ?? '',
        'spclNote' => $orderData['spclNote'] ?? '',
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
    
    $desc = $result['description_text'] ?? 'General Items';
    $desc = strlen($desc) > 100 ? substr($desc, 0, 97) . '...' : $desc;
    $weight = max(0.5, min(10, ($result['total_qty'] ?? 1) * 0.5));
    
    return ['description' => $desc, 'weight' => number_format($weight, 1)];
}

// Get district and city for Koombiyo
function getKoombiyoLocation($cityName) {
    $districtMap = [
        'colombo' => 1, 'gampaha' => 2, 'kalutara' => 3, 'kandy' => 4,
        'matale' => 5, 'nuwara eliya' => 6, 'galle' => 7, 'matara' => 8,
        'hambantota' => 9, 'jaffna' => 10, 'kilinochchi' => 11,
        'mannar' => 12, 'vavuniya' => 13, 'mullaitivu' => 14,
        'batticaloa' => 15, 'ampara' => 16, 'trincomalee' => 17,
        'kurunegala' => 18, 'puttalam' => 19, 'anuradhapura' => 20,
        'polonnaruwa' => 21, 'badulla' => 22, 'moneragala' => 23,
        'ratnapura' => 24, 'kegalle' => 25
    ];
    
    $cityLower = strtolower(trim($cityName));
    
    $district = 1; // Default to Colombo
    foreach ($districtMap as $districtName => $districtId) {
        if (strpos($cityLower, $districtName) !== false) {
            $district = $districtId;
            break;
        }
    }
    
    return [
        'district' => $district,
        'city' => $cityName ?: 'Colombo'
    ];
}

try {
    include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/connection/db_connection.php');
    
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
    // GET COURIER DETAILS (INCLUDING CO_ID)
    // ============================================
    $stmt = $conn->prepare("
        SELECT courier_id, courier_name, co_id, api_key, client_id 
        FROM couriers 
        WHERE courier_id = ? 
        AND status = 'active' 
        AND has_api_existing = 1
    ");
    $stmt->bind_param("i", $carrierId);
    $stmt->execute();
    $courier = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$courier || empty($courier['api_key'])) {
        throw new Exception('Invalid courier or missing API credentials');
    }
    
    // ============================================
    // FALLBACK: Use co_id from courier table if not provided
    // ============================================
    if (empty($coId)) {
        $coId = $courier['co_id'];
        error_log("CO_ID fallback from courier table: " . ($coId ?? 'NULL'));
    }
    
    // Validate co_id exists
    if (empty($coId)) {
        throw new Exception('CO_ID is required but not found in request or courier configuration');
    }
    
    // ============================================
    // STEP 1: GET TENANT_ID FROM FIRST ORDER
    // ============================================
    $firstOrderId = $orderIds[0];
    $stmt = $conn->prepare("SELECT tenant_id FROM order_header WHERE order_id = ?");
    $stmt->bind_param("i", $firstOrderId);
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
    
    if (count($tracking) < $orderCount) {
        throw new Exception("Need $orderCount tracking numbers for tenant $tenantId, only " . count($tracking) . " available");
    }
    
    // ============================================
    // STEP 3: GET ORDERS
    // ============================================
    $placeholders = str_repeat('?,', count($orderIds) - 1) . '?';
    $stmt = $conn->prepare("
        SELECT oh.*, 
               c.name as customer_name, 
               c.phone as customer_phone, 
               c.address_line1 as customer_address1, 
               c.address_line2 as customer_address2, 
               ct.city_name
        FROM order_header oh 
        LEFT JOIN customers c ON oh.customer_id = c.customer_id 
        LEFT JOIN city_table ct ON c.city_id = ct.city_id
        WHERE oh.order_id IN ($placeholders) 
        AND oh.status = 'pending'
    ");
    $stmt->bind_param(str_repeat('i', count($orderIds)), ...$orderIds);
    $stmt->execute();
    $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    if (empty($orders)) {
        throw new Exception('No valid pending orders found');
    }
    
    // ============================================
    // STEP 4: VERIFY ALL ORDERS BELONG TO SAME TENANT
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
    $failedOrders = [];
    $processedOrders = [];
    
    foreach ($orders as $index => $order) {
        $orderId = $order['order_id'];
        $trackingNumber = $tracking[$index]['tracking_id'];
        
        try {
            $parcelData = getParcelData($orderId, $conn);
            $location = getKoombiyoLocation($order['city_name'] ?? '');
            
            // Determine COD amount
            $codAmount = ($order['pay_status'] === 'paid') ? '0' : (string)$order['total_amount'];
            
            // Prepare full address
            $fullAddress = trim(($order['address_line1'] ?? $order['customer_address1'] ?? '') . ' ' . 
                               ($order['address_line2'] ?? $order['customer_address2'] ?? ''));
            
            $orderData = [
                'orderWaybillid' => $trackingNumber,
                'orderNo' => (string)$orderId,
                'receiverName' => $order['full_name'] ?: $order['customer_name'],
                'receiverStreet' => $fullAddress,
                'receiverDistrict' => $location['district'],
                'receiverCity' => $location['city'],
                'receiverPhone' => $order['mobile'] ?: $order['customer_phone'],
                'description' => $parcelData['description'],
                'spclNote' => $dispatchNotes ?: 'Bulk dispatch order',
                'getCod' => $codAmount
            ];
            
            error_log("DEBUG - Order $orderId: Tracking=$trackingNumber, COD=$codAmount, City={$location['city']}");
            
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
        $details = "Koombiyo bulk dispatch: $successCount/" . count($orderIds) . " orders dispatched, Tenant: $tenantId, CO_ID: $coId, Tracking: $trackingList";
        
        if (!empty($failedOrders)) {
            $errorList = array_map(fn($f) => "Order {$f['order_id']}: {$f['error']}", $failedOrders);
            $details .= ". Failed: " . implode('; ', $errorList);
        }
        
        logAction($conn, $userId, 'bulk_api_existing_dispatch', 0, $details);
    } else {
        $conn->rollback();
        
        $errorList = array_map(fn($f) => "Order {$f['order_id']}: {$f['error']}", $failedOrders);
        logAction($conn, $userId, 'bulk_api_existing_dispatch_failed', 0, 
            "Koombiyo bulk dispatch failed: All " . count($orderIds) . " orders failed. Errors: " . implode('; ', $errorList));
    }
    
    // ============================================
    // BUILD RESPONSE
    // ============================================
    $response = [
        'success' => $successCount > 0,
        'processed_count' => $successCount,
        'total_count' => count($orderIds),
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