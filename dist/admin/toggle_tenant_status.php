<?php
// Start session at the very beginning
session_start();

// Set header for JSON response
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Include the database connection file
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/connection/db_connection.php');

// Check if user has admin role (role_id = 1)
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User ID not found']);
    exit();
}

// Get user's role from database
$user_id = $_SESSION['user_id'];
$role_check_sql = "SELECT role_id FROM users WHERE id = ? AND status = 'active'";
$role_stmt = $conn->prepare($role_check_sql);
$role_stmt->bind_param("i", $user_id);
$role_stmt->execute();
$role_result = $role_stmt->get_result();

if ($role_result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'User not found or inactive']);
    exit();
}

$user_role = $role_result->fetch_assoc();

// Check if user is admin (role_id = 1)
if ($user_role['role_id'] != 1) {
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit();
}

// Get the raw POST data
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Validate input
if (!isset($data['tenant_id']) || !isset($data['new_status'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}

$tenant_id = intval($data['tenant_id']);
$new_status = $data['new_status'];

// Validate status
if (!in_array($new_status, ['active', 'inactive'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid status value']);
    exit();
}

// Update tenant status
$update_sql = "UPDATE tenants SET status = ?, updated_at = NOW() WHERE tenant_id = ?";
$update_stmt = $conn->prepare($update_sql);

if (!$update_stmt) {
    echo json_encode(['success' => false, 'message' => 'Database prepare error: ' . $conn->error]);
    exit();
}

$update_stmt->bind_param("si", $new_status, $tenant_id);

if ($update_stmt->execute()) {
    if ($update_stmt->affected_rows > 0) {
        echo json_encode([
            'success' => true, 
            'message' => 'Tenant status updated successfully',
            'new_status' => $new_status
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'No changes made or tenant not found'
        ]);
    }
} else {
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . $update_stmt->error
    ]);
}

$update_stmt->close();
$conn->close();
?>