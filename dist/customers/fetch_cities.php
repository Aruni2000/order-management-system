<?php
session_start();

// Security check - ensure user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Check database connection
if ($conn->connect_error) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

// Set content type to JSON
header('Content-Type: application/json');

// Get search term from query parameter
$searchTerm = isset($_GET['term']) ? trim($_GET['term']) : '';

// Validate search term
if (empty($searchTerm)) {
    echo json_encode([]);
    exit();
}

// Minimum 2 characters for search
if (strlen($searchTerm) < 2) {
    echo json_encode([]);
    exit();
}

// Sanitize search term for SQL LIKE
$searchTerm = $conn->real_escape_string($searchTerm);

// Prepare SQL query with LIKE for partial matching
// Search in city_name field, order by relevance
$sql = "SELECT 
            city_id,
            city_name,
            postal_code
        FROM city_table 
        WHERE is_active = 1 
        AND city_name LIKE ?
        ORDER BY 
            CASE 
                WHEN city_name LIKE ? THEN 1
                WHEN city_name LIKE ? THEN 2
                ELSE 3
            END,
            city_name ASC
        LIMIT 20";

// Prepare statement
$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Query preparation failed']);
    exit();
}

// Bind parameters
// First parameter: general search (contains)
// Second parameter: starts with (higher priority)
// Third parameter: contains (lower priority)
$searchPattern = '%' . $searchTerm . '%';
$startsWithPattern = $searchTerm . '%';
$containsPattern = '%' . $searchTerm . '%';

$stmt->bind_param('sss', $searchPattern, $startsWithPattern, $containsPattern);

// Execute query
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['error' => 'Query execution failed']);
    $stmt->close();
    $conn->close();
    exit();
}

// Get results
$result = $stmt->get_result();
$cities = [];

while ($row = $result->fetch_assoc()) {
    $cities[] = [
        'city_id' => (int)$row['city_id'],
        'city_name' => htmlspecialchars($row['city_name'], ENT_QUOTES, 'UTF-8'),
        'postal_code' => $row['postal_code'] ? htmlspecialchars($row['postal_code'], ENT_QUOTES, 'UTF-8') : null
    ];
}

// Close statement and connection
$stmt->close();
$conn->close();

// Return JSON response
echo json_encode($cities);
exit();
?>