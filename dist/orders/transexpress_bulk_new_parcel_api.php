<?php
/**
 * Transexpress Bulk New Parcel API Handler - FIXED VERSION with co_id Support
 * @version 2.3
 * @date 2025-01-08
 * 
 * CHANGES:
 * - Added co_id field retrieval from couriers table
 * - Added co_id update in order_header
 * - Added co_id to response payload
 * - Added co_id to logging messages
 * - Added tenant validation
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

// Call Transexpress bulk API
function callTransexpressBulkApi($apiData, $apiKey) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://portal.transexpress.lk/api/orders/upload/auto",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($apiData),
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) return ['success' => false, 'message' => "Connection error: $error"];
    if ($httpCode !== 200) return ['success' => false, 'message' => "Server error: $httpCode", 'raw_response' => $response];

    $data = json_decode($response, true);
    if (!$data) return ['success' => false, 'message' => 'Invalid JSON response', 'raw_response' => $response];

    return ['success' => true, 'data' => $data, 'raw_response' => $response];
}

// Extract waybills from API response
function extractTransexpressTracking($responseData) {
    $trackingNumbers = [];
    if (!empty($responseData['orders']) && is_array($responseData['orders'])) {
        foreach ($responseData['orders'] as $orderResult) {
            $orderNo = (string)$orderResult['order_no'];
            $waybill = $orderResult['waybill_id'] ?? null;
            if ($waybill) {
                $trackingNumbers[$orderNo] = $waybill;
            }
        }
    }
    return $trackingNumbers;
}

try {
    include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/connection/db_connection.php');

    // Validations
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

    if (!is_array($orderIds) || empty($orderIds)) {
        throw new Exception('Invalid order IDs');
    }

    // ==========================================
    // ✅ FIX 1: Get courier details INCLUDING co_id
    // ==========================================
    $stmt = $conn->prepare("
        SELECT courier_id, courier_name, co_id, api_key, tenant_id 
        FROM couriers 
        WHERE courier_id = ? 
        AND status = 'active' 
        AND has_api_new = 1
    ");
    $stmt->bind_param("i", $carrierId);
    $stmt->execute();
    $courier = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$courier || empty($courier['api_key'])) {
        throw new Exception('Invalid courier or missing API credentials');
    }

    // ==========================================
    // ✅ FIX 2: Store co_id for later use
    // ==========================================
    $courierCoId = $courier['co_id'];
    $courierTenantId = $courier['tenant_id'];

    // Get pending orders with district information
    $placeholders = str_repeat('?,', count($orderIds) - 1) . '?';
    $stmt = $conn->prepare("
        SELECT 
            oh.order_id, 
            oh.total_amount, 
            oh.pay_status, 
            oh.tenant_id,
            c.name as customer_name, 
            c.phone as customer_phone, 
            c.address_line1, 
            c.address_line2, 
            ct.city_id, 
            dt.district_id
        FROM order_header oh
        LEFT JOIN customers c ON oh.customer_id = c.customer_id
        LEFT JOIN city_table ct ON c.city_id = ct.city_id
        LEFT JOIN district_table dt ON ct.district_id = dt.district_id
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

    // Verify all orders belong to the same tenant as the courier
    foreach ($orders as $order) {
        if ($order['tenant_id'] != $courierTenantId) {
            throw new Exception("Order {$order['order_id']} belongs to tenant {$order['tenant_id']}, but courier belongs to tenant {$courierTenantId}");
        }
    }

    // Prepare API payload with correct field names
    $payload = [];
    foreach ($orders as $order) {
        $apiAmount = ($order['pay_status'] === 'paid') ? 0 : $order['total_amount'];
        $cleanPhone = preg_replace('/[^0-9]/', '', $order['customer_phone']);
        
        // Ensure phone number is properly formatted
        if (strlen($cleanPhone) === 9) {
            $cleanPhone = '0' . $cleanPhone; // Add leading 0 if missing
        }
        
        $payload[] = [
            'order_id' => (string)$order['order_id'], // Convert to string as per API example
            'customer_name' => $order['customer_name'] ?? 'Customer',
            'address' => trim(($order['address_line1'] ?? '') . ' ' . ($order['address_line2'] ?? '')),
            'order_description' => 'Order #' . $order['order_id'],
            'customer_phone' => $cleanPhone,
            'customer_phone2' => '', // Optional field
            'cod_amount' => (float)$apiAmount,
            'district' => (int)($order['district_id'] ?? 1),
            'city' => (int)($order['city_id'] ?? 1),
            'remarks' => $dispatchNotes
        ];
    }

    // Log the payload for debugging
    error_log("Transexpress API Payload: " . json_encode($payload));

    // Call Transexpress API
    $apiResult = callTransexpressBulkApi($payload, $courier['api_key']);
    
    if (!$apiResult['success']) {
        error_log("Transexpress API Error: " . $apiResult['message']);
        if (isset($apiResult['raw_response'])) {
            error_log("Raw Response: " . $apiResult['raw_response']);
        }
        throw new Exception($apiResult['message']);
    }

    // Extract tracking numbers from response
    $trackingNumbers = extractTransexpressTracking($apiResult['data']);

    // Process orders
    $conn->begin_transaction();
    
    $successCount = 0;
    $failedOrders = [];
    $processedOrders = [];

    foreach ($orders as $order) {
        $orderId = $order['order_id'];
        
        if (!isset($trackingNumbers[(string)$orderId])) {
            // Fail order if no waybill returned
            $failedOrders[] = [
                'order_id' => $orderId, 
                'error' => 'No waybill returned from Transexpress'
            ];
            logAction($conn, $userId, 'transexpress_bulk_new_dispatch_failed', $orderId, 
                "No waybill returned from API");
            continue;
        }

        $tracking = $trackingNumbers[(string)$orderId];

        try {
            // ==========================================
            // ✅ FIX 3: Update order_header with co_id
            // ==========================================
            $stmtUpdate = $conn->prepare("
                UPDATE order_header 
                SET status = 'dispatch', 
                    courier_id = ?, 
                    co_id = ?,
                    tracking_number = ?, 
                    dispatch_note = ?, 
                    updated_at = NOW() 
                WHERE order_id = ?
            ");
            $stmtUpdate->bind_param("iissi", $carrierId, $courierCoId, $tracking, $dispatchNotes, $orderId);
            
            if (!$stmtUpdate->execute()) {
                throw new Exception("Database update failed: " . $stmtUpdate->error);
            }
            $stmtUpdate->close();

            // Update order items
            $stmtUpdateItems = $conn->prepare("UPDATE order_items SET status = 'dispatch' WHERE order_id = ?");
            $stmtUpdateItems->bind_param("i", $orderId);
            
            if (!$stmtUpdateItems->execute()) {
                throw new Exception("Order items update failed: " . $stmtUpdateItems->error);
            }
            $stmtUpdateItems->close();

            // ==========================================
            // ✅ FIX 4: Enhanced logging with co_id
            // ==========================================
            logAction($conn, $userId, 'transexpress_bulk_new_dispatch', $orderId, 
                "Order $orderId dispatched via Transexpress (co_id: $courierCoId) - Tracking: $tracking");
            
            $successCount++;
            $processedOrders[] = [
                'order_id' => $orderId,
                'tracking_number' => $tracking,
                'co_id' => $courierCoId
            ];
            
        } catch (Exception $e) {
            $failedOrders[] = [
                'order_id' => $orderId, 
                'error' => $e->getMessage()
            ];
            logAction($conn, $userId, 'transexpress_bulk_new_dispatch_failed', $orderId, 
                "Error: " . $e->getMessage());
        }
    }

    // Commit or rollback
    if ($successCount > 0) {
        $conn->commit();
        
        // ==========================================
        // ✅ FIX 5: Include co_id in bulk log details
        // ==========================================
        $trackingList = implode(', ', array_column($processedOrders, 'tracking_number'));
        $details = "Transexpress bulk dispatch (co_id: $courierCoId): $successCount/" . count($orderIds) . 
                   " orders dispatched, Tracking: $trackingList";
        
        if (!empty($failedOrders)) {
            $errorList = array_map(fn($f) => "Order {$f['order_id']}: {$f['error']}", $failedOrders);
            $details .= ". Failed: " . implode('; ', $errorList);
        }
        
        logAction($conn, $userId, 'bulk_transexpress_new_dispatch', 0, $details);
    } else {
        $conn->rollback();
        
        $errorList = array_map(fn($f) => "Order {$f['order_id']}: {$f['error']}", $failedOrders);
        logAction($conn, $userId, 'bulk_transexpress_new_dispatch_failed', 0, 
            "Transexpress bulk dispatch failed: All " . count($orderIds) . " orders failed. Errors: " . implode('; ', $errorList));
    }

    // ==========================================
    // ✅ FIX 6: Include co_id in response
    // ==========================================
    $response = [
        'success' => $successCount > 0,
        'processed_count' => $successCount,
        'total_count' => count($orderIds),
        'failed_count' => count($failedOrders),
        'processed_orders' => $processedOrders,
        'courier_co_id' => $courierCoId,
        'tenant_id' => $courierTenantId
    ];
    
    if (!empty($failedOrders)) {
        $response['failed_orders'] = $failedOrders;
        $response['message'] = "Processed $successCount orders successfully, " . count($failedOrders) . " failed";
    } else {
        $response['message'] = "All $successCount orders processed successfully via Transexpress (co_id: $courierCoId)";
    }

    ob_clean();
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    
    ob_clean();
    error_log("Transexpress Bulk API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} finally {
    if (isset($conn)) {
        $conn->autocommit(true);
    }
    ob_end_flush();
}
?>