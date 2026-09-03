<?php
/**
 * Cron Job: Sync Order Count from OMS to fe_it_db
 * 
 * Logic:
 * 1. Fetch customer_id and billing_date from branding table in OMS DB.
 *    NOTE: Requires a 'branding' table with columns: customer_id (int), billing_date (int = day of month), active (tinyint).
 * 2. If today matches billing_date:
 *    a. Get start date from Admin API.
 *    b. Count ALL orders in order_header since that date.
 *    c. Submit order_count to Admin API for processing (package logic and DB update).
 *    d. Log the action locally.
 */

// Set time zone
date_default_timezone_set('Asia/Colombo');

// Define connection paths
$base_dir = dirname(__DIR__); // Go up from cron to dist
require_once $base_dir . '/connection/db_connection.php'; // $conn

// Admin API Config
$admin_api_url = "http://localhost/admin/sync_order_count_api.php";

/**
 * Helper to call Admin API
 */
function call_admin_api($action, $data) {
    global $admin_api_url;
    $data['action'] = $action;
    
    $ch = curl_init($admin_api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($error) {
        return ['status' => 'error', 'message' => "cURL Error: $error"];
    }
    
    if ($http_code !== 200) {
        return ['status' => 'error', 'message' => "HTTP Error Code: $http_code. Response: $response"];
    }
    
    $result = json_decode($response, true);
    if (!$result) {
        return ['status' => 'error', 'message' => "Invalid API response (JSON decode failed): $response"];
    }
    
    return $result;
}

try {
    // Fetch branding settings
    $branding_query = "SELECT customer_id, billing_date FROM branding WHERE active = 1 LIMIT 1";
    $branding_res = $conn->query($branding_query);

    if (!$branding_res || $branding_res->num_rows === 0) {
        exit("Error: No active branding settings found.\n");
    }

    $branding = $branding_res->fetch_assoc();
    $target_customer_id = (int)$branding['customer_id'];
    $billing_date = (int)$branding['billing_date'];
    $today_day = (int)date('j');

    // Check if it's billing day
    if ($today_day !== $billing_date) {
        exit("Not billing day. Skipping sync.\n");
    }

    // Check customer status in Admin before proceeding
    $status_res = call_admin_api('check_status', ['customer_id' => $target_customer_id]);
    if ($status_res['status'] !== 'success') {
        throw new Exception("API Error (check_status): " . $status_res['message']);
    }

    if ($status_res['customer_status'] !== 'Active') {
        exit("Customer is not Active in Admin. Skipping sync.\n");
    }

    // Calculate start date 
    $start_date = date('Y-m-d', mktime(0, 0, 0, (int)date('m') - 1, $billing_date, (int)date('Y')));

    // Count orders
    $order_count_query = "SELECT COUNT(*) as total FROM order_header WHERE created_at >= ?";
    $stmt_order = $conn->prepare($order_count_query);
    $stmt_order->bind_param("s", $start_date);
    $stmt_order->execute();
    $order_res = $stmt_order->get_result();
    $order_data = $order_res->fetch_assoc();
    $total_orders = (int)$order_data['total'];

    // Submit sync request to Admin API for final processing (DB update & package logic)
    $sync_res = call_admin_api('process_sync', [
        'customer_id' => $target_customer_id,
        'total_orders' => $total_orders
    ]);

    if ($sync_res['status'] !== 'success') {
        throw new Exception("API Error (process_sync): " . $sync_res['message']);
    }
    echo "Cron completed successfully. Order count: $total_orders\n";

} catch (Exception $e) {
    echo "CRITICAL ERROR: " . $e->getMessage() . "\n";
}

$conn->close();
?>
