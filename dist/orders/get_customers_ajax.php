<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

header('Content-Type: application/json');

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 50;
$offset = ($page - 1) * $limit;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$tenant_id = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : 0;

// Get session info
$is_main_admin = isset($_SESSION['is_main_admin']) ? (int)$_SESSION['is_main_admin'] : 0;
$role_id = isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : 0;
$session_tenant_id = isset($_SESSION['tenant_id']) ? intval($_SESSION['tenant_id']) : 0;

// Determine which tenant to filter by
$filter_tenant_id = $tenant_id;
if (!($is_main_admin === 1 && $role_id === 1)) {
    $filter_tenant_id = $session_tenant_id;
}

$where = "WHERE c.status = 'Active'";

if ($filter_tenant_id > 0) {
    $where .= " AND c.tenant_id = ?";
}

if ($search !== '') {
    $searchEscaped = '%' . $conn->real_escape_string($search) . '%';
    $searchCondition = " AND (
        CAST(c.customer_id AS CHAR) LIKE ?
        OR c.name LIKE ?
        OR c.email LIKE ?
        OR c.phone LIKE ?
        OR c.phone_2 LIKE ?
        OR ct.city_name LIKE ?
    )";
    $where .= $searchCondition;
}

$countSql = "SELECT COUNT(*) as total
             FROM customers c
             LEFT JOIN city_table ct ON c.city_id = ct.city_id
             $where";

$dataSql = "SELECT c.*, ct.city_name
            FROM customers c
            LEFT JOIN city_table ct ON c.city_id = ct.city_id
            $where
            ORDER BY c.customer_id DESC
            LIMIT ? OFFSET ?";

// Prepare count statement
$countStmt = $conn->prepare($countSql);
$paramTypes = '';
$paramValues = [];

if ($filter_tenant_id > 0) {
    $paramTypes .= 'i';
    $paramValues[] = $filter_tenant_id;
}

if ($search !== '') {
    $paramTypes .= 'ssssss';
    for ($i = 0; $i < 6; $i++) {
        $paramValues[] = $searchEscaped;
    }
}

if ($paramTypes !== '') {
    $countStmt->bind_param($paramTypes, ...$paramValues);
}
$countStmt->execute();
$totalResult = $countStmt->get_result();
$totalRow = $totalResult->fetch_assoc();
$totalCustomers = $totalRow['total'];
$totalPages = ceil($totalCustomers / $limit);

// Prepare data statement
$dataStmt = $conn->prepare($dataSql);
if ($search !== '' && $filter_tenant_id > 0) {
    // tenant_id + search + limit + offset
    $allParams = array_merge([$filter_tenant_id], array_fill(0, 6, $searchEscaped), [$limit, $offset]);
    $dataStmt->bind_param('issssssii', ...$allParams);
} elseif ($search !== '') {
    // search + limit + offset
    $allParams = array_merge(array_fill(0, 6, $searchEscaped), [$limit, $offset]);
    $dataStmt->bind_param('ssssssii', ...$allParams);
} elseif ($filter_tenant_id > 0) {
    // tenant_id + limit + offset
    $dataStmt->bind_param('iii', $filter_tenant_id, $limit, $offset);
} else {
    // limit + offset
    $dataStmt->bind_param('ii', $limit, $offset);
}
$dataStmt->execute();
$result = $dataStmt->get_result();

$customers = [];
while ($row = $result->fetch_assoc()) {
    $customers[] = [
        'customer_id' => $row['customer_id'],
        'name' => $row['name'],
        'email' => $row['email'] ?? '',
        'phone' => $row['phone'] ?? '',
        'phone_2' => $row['phone_2'] ?? '',
        'address_line1' => $row['address_line1'] ?? '',
        'address_line2' => $row['address_line2'] ?? '',
        'city_name' => $row['city_name'] ?? '',
        'city_id' => $row['city_id'] ?? '',
    ];
}

echo json_encode([
    'customers' => $customers,
    'pagination' => [
        'current_page' => $page,
        'total_pages' => $totalPages,
        'total_customers' => $totalCustomers,
        'per_page' => $limit,
    ]
]);

$countStmt->close();
$dataStmt->close();
$conn->close();
