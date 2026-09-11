<?php
// check_phone.php - Check if phone number already exists
session_start();
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

header('Content-Type: application/json');

$phone = isset($_GET['phone']) ? trim($_GET['phone']) : '';
$currentCustomerId = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;

// UPDATED: Get tenant_id from GET parameter (for admin switching) or session
$tenant_id = isset($_GET['tenant_id']) ? intval($_GET['tenant_id']) : ($_SESSION['tenant_id'] ?? 0);

// Return false if phone is empty or tenant_id is invalid
if (empty($phone) || $tenant_id === 0) {
    echo json_encode(['exists' => false]);
    exit();
}

// Check if phone exists in BOTH phone and phone_2 columns within the same tenant
$sql = "SELECT customer_id, phone, phone_2 
        FROM customers 
        WHERE (phone = ? OR phone_2 = ?) 
        AND tenant_id = ? 
        AND customer_id != ? 
        AND status = 'Active'
        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ssii", $phone, $phone, $tenant_id, $currentCustomerId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $customer = $result->fetch_assoc();
    
    // Determine if it matched in primary phone or phone_2
    $type = ($customer['phone'] === $phone) ? 'primary' : 'secondary';
    
    echo json_encode([
        'exists' => true,
        'type' => $type,
        'customer_id' => $customer['customer_id']
    ]);
} else {
    echo json_encode(['exists' => false]);
}

$stmt->close();
$conn->close();
?>