<?php
session_start();
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/connection/db_connection.php');

$email = $_GET['email'] ?? '';
$customerId = intval($_GET['customer_id'] ?? 0);

$response = ['exists' => false];

if ($email) {
    $stmt = $conn->prepare("
        SELECT customer_id 
        FROM customers 
        WHERE email = ? AND customer_id != ?
        LIMIT 1
    ");
    $stmt->bind_param("si", $email, $customerId);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $response['exists'] = true;
    }
}

echo json_encode($response);