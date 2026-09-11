<?php
// check_email.php - Check if email already exists (duplicate validation)
session_start();
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

header('Content-Type: application/json');

$email = isset($_GET['email']) ? trim($_GET['email']) : '';
$currentCustomerId = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;

// UPDATED: Get tenant_id from GET parameter (for admin switching) or session
$tenant_id = isset($_GET['tenant_id']) ? intval($_GET['tenant_id']) : ($_SESSION['tenant_id'] ?? 0);

// Return false if email is empty or tenant_id is invalid
if (empty($email) || $tenant_id === 0) {
    echo json_encode(['exists' => false]);
    exit();
}

// Check if email exists within the same tenant (excluding current customer if editing)
$sql = "SELECT customer_id, name 
        FROM customers 
        WHERE email = ? 
        AND tenant_id = ? 
        AND customer_id != ? 
        AND status = 'Active'
        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("sii", $email, $tenant_id, $currentCustomerId);
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