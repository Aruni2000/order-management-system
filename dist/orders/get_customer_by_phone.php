<?php
// get_customer_by_phone.php - Retrieve full customer details by phone
session_start();

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

header('Content-Type: application/json');

// Get phone from query string
$phone = isset($_GET['phone']) ? trim($_GET['phone']) : '';

// Get tenant_id from GET parameter (for admin switching) or session
$tenant_id = isset($_GET['tenant_id']) ? intval($_GET['tenant_id']) : ($_SESSION['tenant_id'] ?? 0);

// Validate phone and tenant_id
if (empty($phone)) {
    echo json_encode(['exists' => false, 'error' => 'Invalid phone']);
    exit();
}

if ($tenant_id === 0) {
    echo json_encode(['exists' => false, 'error' => 'Invalid tenant']);
    exit();
}

// Query to get customer by phone (check both phone and phone_2 columns)
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
        WHERE (c.phone = ? OR c.phone_2 = ?) 
        AND c.tenant_id = ? 
        AND c.status = 'Active'
        LIMIT 1";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode(['exists' => false, 'error' => 'Database error']);
    exit();
}

$stmt->bind_param("ssi", $phone, $phone, $tenant_id);
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
