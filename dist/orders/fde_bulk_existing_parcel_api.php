<?php
/**
 * FDE Bulk Existing Parcel API Handler - COMPLETE FIXED VERSION
 * @version 3.0
 * @date 2025
 * 
 * FEATURES:
 * - ✅ CO_ID support with fallback to courier table
 * - ✅ Tenant-based tracking number filtering
 * - ✅ Phone_2 field support
 * - ✅ Custom description format: "Order #XXX - Y items"
 * - ✅ Multi-tenant validation
 * - ✅ Enhanced error logging
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
function callFdeApi($apiData) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://www.fdedomestic.com/api/parcel/existing_waybill_api_v1.php",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $apiData,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) return ['success' => false, 'message' => "Connection error: $error"];
    if ($httpCode !== 200) return ['success' => false, 'message' => "Server error: $httpCode"];
    
    $data = json_decode($response, true);
    if (!$data) return ['success' => false, 'message' => 'Invalid response from API'];
    
    $messages = [
        200 => 'Successfully insert the parcel', 
        201 => 'Incorrect waybill type. Only allow CRE or CCP',
        202 => 'The waybill is used', 
        203 => 'The waybill is not yet assigned', 
        204 => 'Inactive Client',
        205 => 'Invalid order id', 
        206 => 'Invalid weight', 
        207 => 'Empty or invalid parcel description',
        208 => 'Empty or invalid name', 
        209 => 'Invalid contact number 1', 
        210 => 'Invalid contact number 2',
        211 => 'Empty or invalid address', 
        212 => 'Empty or invalid amount', 
        213 => 'Invalid city',
        214 => 'Parcel insert unsuccessfully', 
        215 => 'Invalid or inactive client', 
        216 => 'Invalid API key',
        217 => 'Invalid exchange value', 
        218 => 'System maintain mode is activated'
    ];
    
    $status = $data['status'] ?? 999;
    return [
        'success' => $status == 200,
        'message' => $messages[$status] ?? "Unknown error (Code: $status)",
        'status_code' => $status,
        'data' => $data
    ];
}

// Get parcel description and weight
function getParcelData($orderId, $conn) {
    $stmt = $conn->prepare("SELECT SUM(quantity) as total_qty FROM order_items WHERE order_id = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    // Create custom description
    $totalItems = $result['total_qty'] ?? 0;
    $desc = "Order #$orderId - $totalItems items";

    // Always use 1 kg as default weight
    $weight = 1.0;

    return [
        'description' => $desc,
        'weight' => number_format($weight, 1)
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
    error_log("=== FDE EXISTING API DEBUG ===");
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
        SELECT courier_name, co_id, api_key, client_id 
        FROM couriers 
        WHERE courier_id = ? 
        AND status = 'active' 
        AND has_api_existing = 1
    ");
    $stmt->bind_param("i", $carrierId);
    $stmt->execute();
    $courier = $stmt->get_result()->fetch_assoc();
    
    if (!$courier || empty($courier['api_key']) || empty($courier['client_id'])) {
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
        ORDER BY created_at ASC 
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
    // STEP 3: GET ORDERS WITH PHONE_2 FIELD
    // ============================================
    $placeholders = str_repeat('?,', count($orderIds) - 1) . '?';
    $stmt = $conn->prepare("
        SELECT 
            oh.*, 
            c.name as customer_name, 
            c.phone as customer_phone, 
            c.phone_2 as customer_phone_2,
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
    $conn->autocommit(false);
    $successCount = 0;
    $failedOrders = [];
    $processedOrders = [];
    
    foreach ($orders as $index => $order) {
        $orderId = $order['order_id'];
        $trackingNumber = $tracking[$index]['tracking_id'];
        
        try {
            $parcelData = getParcelData($orderId, $conn);
            
            // Determine amount based on pay_status
            $apiAmount = ($order['pay_status'] === 'paid') ? 0 : $order['total_amount'];
            
            // Prepare phone numbers with proper fallback
            $recipientPhone1 = $order['mobile'] ?: $order['customer_phone'];
            $recipientPhone2 = !empty($order['customer_phone_2']) ? $order['customer_phone_2'] : '';
            
            // Prepare API data
            $apiData = [
                'api_key' => $courier['api_key'],
                'client_id' => $courier['client_id'],
                'waybill_id' => $trackingNumber,
                'order_id' => $orderId,
                'parcel_weight' => $parcelData['weight'],
                'parcel_description' => $parcelData['description'],
                'recipient_name' => $order['full_name'] ?: $order['customer_name'],
                'recipient_contact_1' => $recipientPhone1,
                'recipient_contact_2' => $recipientPhone2,
                'recipient_address' => trim(($order['address_line1'] ?? $order['customer_address1'] ?? '') . ' ' . ($order['address_line2'] ?? $order['customer_address2'] ?? '')),
                'recipient_city' => $order['city_name'] ?: '',
                'amount' => $apiAmount,
                'exchange' => '0'
            ];
            
            error_log("DEBUG - Order $orderId: Phone1={$recipientPhone1}, Phone2={$recipientPhone2}, Desc={$parcelData['description']}");
            
            // Call FDE API
            $result = callFdeApi($apiData);
            
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
                
                if (!$stmt->execute()) {
                    throw new Exception("Database update failed: " . $stmt->error);
                }
                
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
                
                // Log success
                logAction($conn, $userId, 'api_existing_dispatch', $orderId, 
                    "Order $orderId dispatched - Tenant: $tenantId, CO_ID: $coId, Tracking: $trackingNumber, Desc: {$parcelData['description']}, Phone1: $recipientPhone1, Phone2: " . ($recipientPhone2 ?: 'N/A') . ", Status: {$result['message']}");
                
                $successCount++;
                $processedOrders[] = [
                    'order_id' => $orderId, 
                    'tracking_number' => $trackingNumber,
                    'co_id' => $coId,
                    'tenant_id' => $tenantId,
                    'description' => $parcelData['description'],
                    'weight' => $parcelData['weight'],
                    'phone_1' => $recipientPhone1,
                    'phone_2' => $recipientPhone2
                ];
                
            } else {
                $failedOrders[] = [
                    'order_id' => $orderId,
                    'tracking_number' => $trackingNumber,
                    'error' => $result['message'],
                    'status_code' => $result['status_code'] ?? null
                ];
                
                logAction($conn, $userId, 'api_existing_dispatch_failed', $orderId,
                    "Order $orderId failed - Error: {$result['message']}, Code: " . ($result['status_code'] ?? 'N/A'));
            }
            
        } catch (Exception $e) {
            $failedOrders[] = [
                'order_id' => $orderId,
                'tracking_number' => $trackingNumber,
                'error' => $e->getMessage()
            ];
            
            logAction($conn, $userId, 'api_existing_dispatch_failed', $orderId,
                "Order $orderId exception - Error: {$e->getMessage()}");
        }
    }
    
    // ============================================
    // COMMIT OR ROLLBACK
    // ============================================
    if ($successCount > 0) {
        $conn->commit();
        $trackingList = implode(', ', array_column($processedOrders, 'tracking_number'));
        $details = "Bulk dispatch: $successCount/" . count($orderIds) . " orders dispatched, Tenant: $tenantId, CO_ID: $coId, Tracking: $trackingList";
        
        if (!empty($failedOrders)) {
            $errorList = array_map(fn($f) => "Order {$f['order_id']}: {$f['error']}", $failedOrders);
            $details .= ". Failed: " . implode('; ', $errorList);
        }
        
        logAction($conn, $userId, 'bulk_api_existing_dispatch', 0, $details);
    } else {
        $conn->rollback();
        $errorList = array_map(fn($f) => "Order {$f['order_id']}: {$f['error']}", $failedOrders);
        logAction($conn, $userId, 'bulk_api_existing_dispatch_failed', 0, 
            "Bulk dispatch failed: All " . count($orderIds) . " orders failed. Errors: " . implode('; ', $errorList));
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
        $response['message'] = "All $successCount orders processed successfully via FDE API";
    }
    
    ob_clean();
    echo json_encode($response);
    
} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    
    error_log("FDE Existing API Error: " . $e->getMessage());
    
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