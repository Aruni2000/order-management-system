<?php
// Start output buffering to prevent header issues
ob_start();

// Start session at the very beginning
session_start();

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    ob_end_clean();
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

// Include the database connection file early
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Check if user is main admin
$is_main_admin = $_SESSION['is_main_admin'];
$teanent_id = $_SESSION['tenant_id'];
$co_id = $_POST['co_id'];

//function for tenant name
function TenantName($tenant_id) {
    global $conn;
    $sql = "SELECT company_name FROM tenants WHERE tenant_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['company_name'];
    }
    return "Unknown Tenant";
}

// Read CSV file and output waybill IDs
$filename = $_FILES['csv_file']['tmp_name'];



// if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
//     // Check if file was uploaded without errors
//     if ($_FILES['csv_file']['error'] == UPLOAD_ERR_OK) {
//         $csvFile = $_FILES['csv_file']['tmp_name'];

$message = "";

if (($handle = fopen($filename, "r")) !== false) {

    while (($data = fgetcsv($handle, 1000, ",")) !== false) {

        // Access columns using index
        $waybill_id = $data[0]; // First column

        // "Waybill ID: $waybill_id <br>";

        //tracking_number and co_id cheack with order_header table
        $sql = "SELECT order_id, status, total_amount FROM order_header WHERE tracking_number = '$waybill_id' AND co_id = '$co_id' LIMIT 1";
        $result = $conn->query($sql);

        //if record found 
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $order_id = $row['order_id'];
            $delivery_status = $row['status'];


            if ($delivery_status == 'delivered') {
                //if delivery status is deilvered --- status = done, paid status = paid, pay_date.
                $sql = "UPDATE order_header SET status = 'done', pay_status = 'paid', pay_date = NOW() WHERE order_id = $order_id";
                $result = $conn->query($sql);
                $message .= "Waybill ID: $waybill_id updated successfully.<br>";

            } else {
                //else deilvery satus is not equal deliveres --- satus invalid
                //invalid status message 
                $message .= "Invalid delivery status for waybill ID: $waybill_id <br>";
            }


        } else {
                
                //not found record --- message(not found message)
                $message .= "Waybill ID not found: $waybill_id <br>";
        }
        


    }

    fclose($handle);
}
echo json_encode([
    "status" => "success",
    "message" => $message
]);
?>