<?php
// Include the database connection file
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/connection/db_connection.php');

// Set content type to JSON
header('Content-Type: application/json');

// Allow access from courier domain
header('Access-Control-Allow-Origin: https://www.fdedomestic.com');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Get the courier callback parameters
$waybill_id = isset($_POST["waybill_id"]) ? trim($_POST["waybill_id"]) : '';
$delivery_status = isset($_POST["current_status"]) ? trim($_POST["current_status"]) : '';
$last_update_time = isset($_POST["last_update_time"]) ? trim($_POST["last_update_time"]) : date('Y-m-d H:i:s');

// Log the incoming data for debugging
error_log("Courier webhook received - Waybill: $waybill_id, Status: $delivery_status, Time: $last_update_time");

// Validate required fields
if (empty($waybill_id) || empty($delivery_status)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: waybill_id and delivery_status']);
    exit;
}

// Map courier statuses to system statuses
if ($delivery_status === 'Reschedule' || 
    $delivery_status === 'Date Changed') {

    $status_update = 'Pending to Deliver';

} elseif ($delivery_status === 'Dispatched') {

    $status_update = 'Courier Dispatch';

} else {
    $status_update = $delivery_status;
}

// Update the order_header table using waybill_id as tracking_number
$sql = "UPDATE order_header 
        SET status = ?, 
            updated_at = ? 
        WHERE tracking_number = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("sss", $status_update, $last_update_time, $waybill_id);

if ($stmt->execute()) {

    if ($stmt->affected_rows > 0) {
        echo json_encode([
            'success' => true,
            'message' => 'Order status updated successfully',
            'waybill_id' => $waybill_id,
            'delivery_status' => $delivery_status,
            'mapped_status' => $status_update,
            'updated_at' => $last_update_time
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'No order found with the provided waybill_id',
            'waybill_id' => $waybill_id
        ]);
    }

} else {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database update failed',
        'details' => $conn->error
    ]);
}

// Close the statement and connection
$stmt->close();
$conn->close();
?>
