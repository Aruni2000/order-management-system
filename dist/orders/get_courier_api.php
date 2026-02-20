<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    // Get co_id from JSON or POST
    $input = json_decode(file_get_contents('php://input'), true);
    $co_id = 0;

    if (isset($input['co_id'])) {
        $co_id = intval($input['co_id']);
    } elseif (isset($_POST['co_id'])) {
        $co_id = intval($_POST['co_id']);
    }

    if ($co_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid courier ID']);
        exit();
    }

    // Fetch courier API data using co_id
    $sql = "SELECT co_id, courier_id, courier_name, client_id, api_key, origin_city_name, origin_state_name
            FROM couriers 
            WHERE co_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $co_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Courier not found']);
        exit();
    }

    $courier = $result->fetch_assoc();

    // Return JSON with both co_id and courier_id
    echo json_encode([
        'success' => true,
        'message' => 'API data retrieved successfully',
        'data' => [
            'co_id' => $courier['co_id'],
            'courier_id' => $courier['courier_id'],
            'courier_name' => $courier['courier_name'],
            'client_id' => $courier['client_id'] ?? '',
            'api_key' => $courier['api_key'] ?? '',
            'origin_city_name' => $courier['origin_city_name'] ?? '',
            'origin_state_name' => $courier['origin_state_name'] ?? ''
        ]
    ]);

    $stmt->close();
} catch (Exception $e) {
    error_log('Get API Data Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'An unexpected error occurred while retrieving API data'
    ]);
} finally {
    if (isset($conn)) $conn->close();
}
?>