<?php
// check_email.php
session_start();
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/connection/db_connection.php');

header('Content-Type: application/json');

$email = isset($_GET['email']) ? trim($_GET['email']) : '';
$currentCustomerId = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;

// Return false if email is empty
if (empty($email)) {
    echo json_encode(['exists' => false]);
    exit();
}

// Check if email exists in database (excluding current customer if editing)
$sql = "SELECT customer_id, name FROM customers WHERE email = ? AND customer_id != ? AND status = 'Active'";
$stmt = $conn->prepare($sql);
$stmt->bind_param("si", $email, $currentCustomerId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $customer = $result->fetch_assoc();
    echo json_encode([
        'exists' => true,
        'customer_name' => $customer['name']
    ]);
} else {
    echo json_encode(['exists' => false]);
}

$stmt->close();
$conn->close();
?>