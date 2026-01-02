<?php
// Start session
session_start();

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/connection/db_connection.php');

// Get email from query string
$email = isset($_GET['email']) ? trim($_GET['email']) : '';

// Validate email format
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['exists' => false, 'error' => 'Invalid email format']);
    exit();
}

// Query to get customer by email
$sql = "SELECT 
            c.customer_id, 
            c.name, 
            c.email, 
            c.phone, 
            c.phone_2,
            c.address_line1, 
            c.address_line2, 
            c.city_id,
            ct.city_name
        FROM customers c
        LEFT JOIN city_table ct ON c.city_id = ct.city_id
        WHERE c.email = ? 
        AND c.status = 'Active'
        LIMIT 1";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode(['exists' => false, 'error' => 'Database error']);
    exit();
}

$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    // Customer exists
    $customer = $result->fetch_assoc();
    
    echo json_encode([
        'exists' => true,
        'customer' => [
            'customer_id' => $customer['customer_id'],
            'name' => $customer['name'],
            'email' => $customer['email'],
            'phone' => $customer['phone'],
            'phone_2' => $customer['phone_2'] ?? '',
            'address_line1' => $customer['address_line1'] ?? '',
            'address_line2' => $customer['address_line2'] ?? '',
            'city_id' => $customer['city_id'],
            'city_name' => $customer['city_name'] ?? ''
        ]
    ]);
} else {
    // Customer does not exist
    echo json_encode(['exists' => false]);
}

$stmt->close();
$conn->close();
?>