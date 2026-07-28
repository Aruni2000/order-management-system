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
$tenant_id = $_SESSION['tenant_id'] ?? 0;

// Only process POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: complete_mark_upload.php");
    exit();
}

$co_id = intval($_POST['co_id'] ?? 0);

if ($co_id <= 0) {
    $_SESSION['import_error'] = "Invalid courier selection.";
    header("Location: complete_mark_upload.php");
    exit();
}

if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['import_error'] = "Please upload a valid CSV file.";
    header("Location: complete_mark_upload.php");
    exit();
}

// Read CSV file and output waybill IDs
$filename = $_FILES['csv_file']['tmp_name'];

$message = "";
$successCount = 0;
$errorCount = 0;
$errorMessages = [];
$infoMessages = [];

if (($handle = fopen($filename, "r")) !== false) {

    $row_num = 0;
    while (($data = fgetcsv($handle, 1000, ",")) !== false) {
        $row_num++;

        // Skip header row
        if ($row_num === 1) {
            continue;
        }

        $waybill_id = trim($data[0]);

        if (empty($waybill_id)) {
            continue;
        }

        //tracking_number and co_id check with order_header table
        $stmt = $conn->prepare("SELECT order_id, status, total_amount FROM order_header WHERE tracking_number = ? AND co_id = ? LIMIT 1");
        $stmt->bind_param("si", $waybill_id, $co_id);
        $stmt->execute();
        $result = $stmt->get_result();

        //if record found
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $order_id = $row['order_id'];
            $delivery_status = $row['status'];

            if ($delivery_status == 'delivered') {
                $updateStmt = $conn->prepare("UPDATE order_header SET status = 'done', pay_status = 'paid', pay_date = NOW() WHERE order_id = ?");
                $updateStmt->bind_param("i", $order_id);
                $updateStmt->execute();
                $successCount++;

            } else {
                $errorCount++;
                $errorMessages[] = "Row $row_num: Invalid delivery status for waybill ID: $waybill_id (Status: $delivery_status)";
            }

        } else {
            $errorCount++;
            $errorMessages[] = "Row $row_num: Waybill ID not found: $waybill_id";
        }

    }

    fclose($handle);
}

// Store results in session
$_SESSION['import_result'] = [
    'success' => $successCount,
    'errors' => $errorCount,
    'messages' => $errorMessages,
    'info' => $infoMessages
];

$conn->close();

// Redirect to avoid resubmission
header("Location: complete_mark_upload.php");
exit();
?>
