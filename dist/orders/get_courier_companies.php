<?php
session_start();

// Check login
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/connection/db_connection.php');

header('Content-Type: application/json');

// Fetch active courier companies
$sql = "SELECT courier_id, courier_name FROM courier_company WHERE status = 'active' ORDER BY courier_name ASC";
$result = $conn->query($sql);

$companies = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $companies[] = $row;
    }
}

echo json_encode([
    'success' => true,
    'companies' => $companies
]);

$conn->close();
?>