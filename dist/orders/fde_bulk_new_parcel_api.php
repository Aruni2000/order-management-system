<?php
/**
 * FDE Bulk New Parcel API Handler - FIXED VERSION
 * @version 3.0
 * @date 2025
 * 
 * CHANGES:
 * - Added tenant validation for selected orders
 * - Added courier-tenant relationship validation
 * - Enhanced co_id retrieval and update logic
 * - Added proper error handling for multi-tenant scenarios
 * - Improved logging and debugging
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
        CURLOPT_URL => "https://www.fdedomestic.com/api/parcel/new_api_v1.php",
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
        200 => 'Successfully insert the parcel', 201 => 'Incorrect waybill type. Only allow CRE or CCP',
        202 => 'The waybill is used', 203 => 'The waybill is not yet assigned', 204 => 'Inactive Client',
        205 => 'Invalid order id', 206 => 'Invalid weight', 207 => 'Empty or invalid parcel description',
        208 => 'Empty or invalid name', 209 => 'Invalid contact number 1', 210 => 'Invalid contact number 2',
        211 => 'Empty or invalid address', 212 => 'Empty or invalid amount', 213 => 'Invalid city',
        214 => 'Parcel insert unsuccessfully', 215 => 'Invalid or inactive client', 216 => 'Invalid API key',
        217 => 'Invalid exchange value', 218 => 'System maintain mode is activated'
    ];
    
    $status = $data['status'] ?? 999;
    return [
        'success' => $status == 200,
        'message' => $messages[$status] ?? "Unknown error (Code: $status)",
        'status_code' => $status,
        'data' => $data,
        'raw_response' => $response
    ];
}

// Get parcel description and weight
function getParcelData($orderId, $conn) {
    $stmt = $conn->prepare("SELECT SUM(quantity) as total_qty FROM order_items WHERE order_id = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $totalItems = $result['total_qty'] ?? 0;
    $desc = "Order #$orderId - $totalItems items";
    $weight = 1.0;

    return [
        'description' => $desc,
        'weight' => number_format($weight, 1)
    ];
}

// Extract tracking number from API response
function extractTrackingNumber($apiResponse) {
    $data = $apiResponse['data'] ?? [];
    
    $possibleFields = [
        'tracking_number', 'waybill', 'waybill_no', 'tracking_no',
        'consignment_no', 'reference_no', 'parcel_no', 'order_reference'
    ];
    
    foreach ($possibleFields as $field) {
        if (isset($data[$field]) && !empty($data[$field])) {
            return trim($data[$field]);
        }
    }
    
    $message = $apiResponse['data']['message'] ?? '';
    if (preg_match('/(?:tracking|waybill|reference)[\s#:]*([A-Z0-9]+)/i', $message, $matches)) {
        return trim($matches[1]);
    }
    
    return null;
}

try {
    include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/connection/db_connection.php');
    
    // ==========================================
    // AUTHENTICATION & VALIDATION
    // ==========================================
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
    $isMainAdmin = isset($_SESSION['is_main_admin']) ? (int)$_SESSION['is_main_admin'] : 0;
    
    if (!is_array($orderIds) || empty($orderIds)) {
        throw new Exception('Invalid order IDs');
    }
    
    error_log("=== FDE API DISPATCH START ===");
    error_log("User ID: $userId, Is Main Admin: $isMainAdmin");
    error_log("Carrier ID: $carrierId");
    error_log("Order IDs: " . implode(', ', $orderIds));
    
    // ==========================================
    // STEP 1: VALIDATE TENANT CONSISTENCY
    // ==========================================
    $placeholders = str_repeat('?,', count($orderIds) - 1) . '?';
    $tenantCheckStmt = $conn->prepare("
        SELECT DISTINCT tenant_id 
        FROM order_header 
        WHERE order_id IN ($placeholders)
    ");
    $tenantCheckStmt->bind_param(str_repeat('i', count($orderIds)), ...$orderIds);
    $tenantCheckStmt->execute();
    $tenantResult = $tenantCheckStmt->get_result();
    
    $tenantIds = [];
    while ($row = $tenantResult->fetch_assoc()) {
        $tenantIds[] = (int)$row['tenant_id'];
    }
    $tenantCheckStmt->close();
    
    if (empty($tenantIds)) {
        throw new Exception('No valid orders found for the selected IDs');
    }
    
    if (count($tenantIds) > 1) {
        error_log("ERROR: Multiple tenants detected: " . implode(', ', $tenantIds));
        throw new Exception('Selected orders belong to different tenants (IDs: ' . implode(', ', $tenantIds) . '). Please select orders from the same tenant.');
    }
    
    $tenantId = $tenantIds[0];
    error_log("Validated Tenant ID: $tenantId");
    
    // ==========================================
    // STEP 2: GET COURIER DETAILS WITH TENANT VALIDATION
    // ==========================================
    $courierStmt = $conn->prepare("
        SELECT 
            courier_id,
            courier_name,
            co_id,
            api_key,
            client_id,
            tenant_id,
            status,
            has_api_new
        FROM couriers 
        WHERE courier_id = ? 
        AND status = 'active'
    ");
    $courierStmt->bind_param("i", $carrierId);
    $courierStmt->execute();
    $courier = $courierStmt->get_result()->fetch_assoc();
    $courierStmt->close();
    
    if (!$courier) {
        throw new Exception("Courier ID $carrierId not found or inactive");
    }
    
    error_log("Courier Details: " . json_encode($courier));
    
    // Validate courier belongs to the same tenant
    if ((int)$courier['tenant_id'] !== $tenantId) {
        error_log("ERROR: Courier tenant mismatch - Courier Tenant: {$courier['tenant_id']}, Order Tenant: $tenantId");
        throw new Exception("Selected courier does not belong to the same tenant as the orders. Courier Tenant: {$courier['tenant_id']}, Order Tenant: $tenantId");
    }
    
    // Validate API capabilities
    if ((int)$courier['has_api_new'] !== 1) {
        throw new Exception("Courier '{$courier['courier_name']}' does not support new parcel API");
    }
    
    if (empty($courier['api_key']) || empty($courier['client_id'])) {
        throw new Exception("Courier '{$courier['courier_name']}' is missing API credentials (API Key or Client ID)");
    }
    
    $courierCoId = $courier['co_id'];
    $courierName = $courier['courier_name'];
    
    error_log("✓ Courier validated: $courierName (co_id: $courierCoId)");
    
    // ==========================================
    // STEP 3: FETCH ORDER DETAILS
    // ==========================================
    $orderStmt = $conn->prepare("
        SELECT 
            oh.*, 
            COALESCE(NULLIF(oh.full_name, ''), c.name) as customer_name,
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
        AND oh.tenant_id = ?
    ");
    
    $bindParams = array_merge($orderIds, [$tenantId]);
    $orderStmt->bind_param(str_repeat('i', count($orderIds)) . 'i', ...$bindParams);
    $orderStmt->execute();
    $orders = $orderStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $orderStmt->close();
    
    if (empty($orders)) {
        throw new Exception('No valid pending orders found for tenant ID: ' . $tenantId);
    }
    
    error_log("Found " . count($orders) . " valid pending orders");
    
    // ==========================================
    // STEP 4: PROCESS ORDERS WITH API
    // ==========================================
    $conn->autocommit(false);
    $successCount = 0;
    $failedOrders = [];
    $processedOrders = [];
    
    foreach ($orders as $order) {
        $orderId = $order['order_id'];
        
        try {
            $parcelData = getParcelData($orderId, $conn);
            
            // Determine amount based on pay_status
            $apiAmount = ($order['pay_status'] === 'paid') ? 0 : $order['total_amount'];
            
            // Prepare phone numbers
            $recipientPhone1 = $order['mobile'] ?: $order['customer_phone'];
            $recipientPhone2 = !empty($order['customer_phone_2']) ? $order['customer_phone_2'] : '';
            
            // Build API payload
            $apiData = [
                'api_key' => $courier['api_key'],
                'client_id' => $courier['client_id'],
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
            
            error_log("Order $orderId - API Request: " . json_encode($apiData));
            
            // Call FDE API
            $result = callFdeApi($apiData);
            
            if ($result['success']) {
                $trackingNumber = extractTrackingNumber($result);
                
                if (empty($trackingNumber)) {
                    error_log("WARNING: No tracking number in response for Order $orderId");
                    $trackingNumber = "FDE-" . $orderId . "-" . date('Ymd');
                }
                
                error_log("Order $orderId - Success! Tracking: $trackingNumber");
                
                // ==========================================
                // CRITICAL: UPDATE DATABASE WITH co_id
                // ==========================================
                $updateStmt = $conn->prepare("
                    UPDATE order_header 
                    SET 
                        status = 'dispatch',
                        courier_id = ?,
                        co_id = ?,
                        tracking_number = ?,
                        dispatch_note = ?,
                        updated_at = NOW()
                    WHERE order_id = ?
                ");
                $updateStmt->bind_param("isssi", $carrierId, $courierCoId, $trackingNumber, $dispatchNotes, $orderId);
                
                if (!$updateStmt->execute()) {
                    throw new Exception("Database update failed: " . $updateStmt->error);
                }
                $updateStmt->close();
                
                // Update order items
                $itemsStmt = $conn->prepare("UPDATE order_items SET status = 'dispatch' WHERE order_id = ?");
                $itemsStmt->bind_param("i", $orderId);
                
                if (!$itemsStmt->execute()) {
                    throw new Exception("Order items update failed: " . $itemsStmt->error);
                }
                $itemsStmt->close();
                
                // Log success
                logAction($conn, $userId, 'api_new_dispatch', $orderId, 
                    "FDE API Dispatch - Order: $orderId, Tracking: $trackingNumber, co_id: $courierCoId, Tenant: $tenantId");
                
                $successCount++;
                $processedOrders[] = [
                    'order_id' => $orderId,
                    'tracking_number' => $trackingNumber,
                    'co_id' => $courierCoId,
                    'courier_name' => $courierName
                ];
                
            } else {
                error_log("Order $orderId - API Failed: " . $result['message']);
                
                $failedOrders[] = [
                    'order_id' => $orderId,
                    'tracking_number' => '',
                    'error' => $result['message'],
                    'status_code' => $result['status_code'] ?? null
                ];
                
                logAction($conn, $userId, 'api_new_dispatch_failed', $orderId,
                    "FDE API Failed - Error: {$result['message']}, Code: {$result['status_code']}");
            }
            
        } catch (Exception $e) {
            error_log("Order $orderId - Exception: " . $e->getMessage());
            
            $failedOrders[] = [
                'order_id' => $orderId,
                'tracking_number' => '',
                'error' => $e->getMessage()
            ];
            
            logAction($conn, $userId, 'api_new_dispatch_failed', $orderId,
                "FDE API Exception - Error: {$e->getMessage()}");
        }
    }
    
    // ==========================================
    // STEP 5: COMMIT OR ROLLBACK
    // ==========================================
    if ($successCount > 0) {
        $conn->commit();
        
        $trackingList = implode(', ', array_column($processedOrders, 'tracking_number'));
        $details = "FDE Bulk API Dispatch: $successCount/" . count($orderIds) . " orders, co_id: $courierCoId, Tenant: $tenantId, Tracking: $trackingList";
        
        if (!empty($failedOrders)) {
            $errorList = array_map(fn($f) => "Order {$f['order_id']}: {$f['error']}", $failedOrders);
            $details .= ". Failed: " . implode('; ', $errorList);
        }
        
        logAction($conn, $userId, 'bulk_api_new_dispatch', 0, $details);
        
        error_log("=== SUCCESS: $successCount orders dispatched ===");
    } else {
        $conn->rollback();
        
        $errorList = array_map(fn($f) => "Order {$f['order_id']}: {$f['error']}", $failedOrders);
        logAction($conn, $userId, 'bulk_api_new_dispatch_failed', 0, 
            "FDE Bulk API Failed: All " . count($orderIds) . " orders failed. Errors: " . implode('; ', $errorList));
        
        error_log("=== FAILURE: All orders failed ===");
    }
    
    // ==========================================
    // STEP 6: SEND RESPONSE
    // ==========================================
    $response = [
        'success' => $successCount > 0,
        'processed_count' => $successCount,
        'total_count' => count($orderIds),
        'failed_count' => count($failedOrders),
        'processed_orders' => $processedOrders,
        'courier_name' => $courierName,
        'courier_co_id' => $courierCoId,
        'tenant_id' => $tenantId
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
    if (isset($conn)) $conn->rollback();
    
    error_log("=== CRITICAL ERROR ===");
    error_log($e->getMessage());
    error_log($e->getTraceAsString());
    
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} finally {
    if (isset($conn)) {
        $conn->autocommit(true);
        $conn->close();
    }
    ob_end_flush();
}
?>