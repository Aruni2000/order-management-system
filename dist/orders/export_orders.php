<?php
/**
 * Export Orders to CSV with Products
 * Exports filtered orders based on search parameters including product details
 * UPDATED: Prioritizes order_header fields over customers table
 */
session_start();

// Authentication check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

// Check if this is an export request
if (!isset($_GET['export']) || $_GET['export'] != '1') {
    header("Location: order_list.php");
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Get current user's role information
$current_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$current_user_role = isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : 0;

// If still no user data, redirect to login
if ($current_user_id == 0) {
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

// Get filter parameters (EXACTLY same as main page)
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$order_id_filter = isset($_GET['order_id_filter']) ? trim($_GET['order_id_filter']) : '';
$customer_name_filter = isset($_GET['customer_name_filter']) ? trim($_GET['customer_name_filter']) : '';
$user_id_filter = isset($_GET['user_id_filter']) ? trim($_GET['user_id_filter']) : '';
$tracking_id = isset($_GET['tracking_id']) ? trim($_GET['tracking_id']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$status_filter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : '';
$pay_status_filter = isset($_GET['pay_status_filter']) ? trim($_GET['pay_status_filter']) : '';

// Role-based access control (EXACTLY same as main page)
$roleBasedCondition = "";
if ($current_user_role != 1) {
    $roleBasedCondition = " AND i.user_id = $current_user_id";
}

// Build SQL query with UPDATED customer data fields priority
// Prioritizes order_header fields (i) over customers table (c)
$sql = "SELECT i.order_id, 
               i.created_at,
               
               -- Customer info: Prioritize order_header, fallback to customers table
               COALESCE(NULLIF(i.full_name, ''), c.name) as customer_name,
               i.customer_id,
               c.email as customer_email,
               COALESCE(NULLIF(i.mobile, ''), c.phone) as customer_phone,
               c.phone_2 as customer_phone_2,
               COALESCE(
                   NULLIF(i.address_line1, ''), 
                   c.address_line1
               ) as customer_address_line1,
               COALESCE(
                   NULLIF(i.address_line2, ''), 
                   c.address_line2
               ) as customer_address_line2,
               
               -- Order details
               i.total_amount,
               i.status,
               i.pay_status,
               i.tracking_number,
               i.subtotal,
               i.discount,
               i.delivery_fee,
               i.interface,
               
               -- User info
               u1.name as paid_by_name,
               u2.name as user_name,
               u2.id as user_id,
               
               -- Payment info
               p.amount_paid,
               p.payment_date
               
        FROM order_header i 
        LEFT JOIN payments p ON i.order_id = p.order_id
        LEFT JOIN users u1 ON p.pay_by = u1.id
        LEFT JOIN users u2 ON i.user_id = u2.id
        LEFT JOIN customers c ON i.customer_id = c.customer_id
        WHERE i.interface IN ('individual', 'leads') 
        AND i.status NOT IN ('pending', 'cancel')$roleBasedCondition";

// Build search conditions (EXACTLY same logic as main page)
$searchConditions = [];

// General search condition - UPDATED to use order_header fields
if (!empty($search)) {
    $searchTerm = $conn->real_escape_string($search);
    $searchConditions[] = "(
                        i.order_id LIKE '%$searchTerm%' OR 
                        i.full_name LIKE '%$searchTerm%' OR 
                        i.issue_date LIKE '%$searchTerm%' OR 
                        i.due_date LIKE '%$searchTerm%' OR 
                        i.total_amount LIKE '%$searchTerm%' OR
                        i.status LIKE '%$searchTerm%' OR 
                        i.tracking_number LIKE '%$searchTerm%' OR
                        i.pay_status LIKE '%$searchTerm%' OR
                        i.created_at LIKE '%$searchTerm%' OR
                        u2.name LIKE '%$searchTerm%')";
}

// Specific Order ID filter
if (!empty($order_id_filter)) {
    $orderIdTerm = $conn->real_escape_string($order_id_filter);
    $searchConditions[] = "i.order_id LIKE '%$orderIdTerm%'";
}

// Specific Customer Name filter - UPDATED to use order_header full_name
if (!empty($customer_name_filter)) {
    $customerNameTerm = $conn->real_escape_string($customer_name_filter);
    $searchConditions[] = "i.full_name LIKE '%$customerNameTerm%'";
}

// Specific User ID filter - with role-based restrictions
if (!empty($user_id_filter)) {
    $userIdTerm = $conn->real_escape_string($user_id_filter);
    if ($current_user_role == 1) {
        // Admin can filter by any user
        $searchConditions[] = "i.user_id = '$userIdTerm'";
    } else {
        // Non-admin can only filter by their own user ID
        if ($userIdTerm == $current_user_id) {
            $searchConditions[] = "i.user_id = '$userIdTerm'";
        }
    }
}

// Tracking ID filter
if (!empty($tracking_id)) {
    $trackingTerm = $conn->real_escape_string($tracking_id);
    $searchConditions[] = "i.tracking_number LIKE '%$trackingTerm%'";
}

// Date range filter
if (!empty($date_from)) {
    $dateFromTerm = $conn->real_escape_string($date_from);
    $searchConditions[] = "DATE(i.created_at) >= '$dateFromTerm'";
}

if (!empty($date_to)) {
    $dateToTerm = $conn->real_escape_string($date_to);
    $searchConditions[] = "DATE(i.created_at) <= '$dateToTerm'";
}

// Status filter
if (!empty($status_filter)) {
    $statusTerm = $conn->real_escape_string($status_filter);
    $searchConditions[] = "i.status = '$statusTerm'";
}

// Payment Status filter
if (!empty($pay_status_filter)) {
    $payStatusTerm = $conn->real_escape_string($pay_status_filter);
    $searchConditions[] = "i.pay_status = '$payStatusTerm'";
}

// Apply all search conditions (EXACTLY same as main page)
if (!empty($searchConditions)) {
    $finalSearchCondition = " AND (" . implode(' AND ', $searchConditions) . ")";
    $sql .= $finalSearchCondition;
}

// Add ordering (same as main page)
$sql .= " ORDER BY i.updated_at DESC, i.order_id DESC";

// Execute query
$result = $conn->query($sql);

// Check for query errors
if (!$result) {
    die("Query Error: " . $conn->error);
}

// Set headers for CSV download
$filename = "orders_export_" . date('Y-m-d_H-i-s') . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Create output stream
$output = fopen('php://output', 'w');

// Add BOM for proper UTF-8 encoding in Excel
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// CSV Headers - Reordered as requested
$headers = [
    'Order ID',
    'Tracking Number',
    'Created Date',
    'Created Time',
    'Customer Name',
    'Phone',
    'Address Line 1',
    'Product Code',
    'Product Name',
    'Product Price',
    'Item Discount',
    'Subtotal'
];

fputcsv($output, $headers);

// Add data rows
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $orderId = $row['order_id'];
        
        // Fetch order items (products) for this order using correct table and column names
        $itemsQuery = "SELECT oi.product_id, p.product_code, p.name as product_name, 
                              oi.quantity, oi.unit_price, oi.discount as item_discount,
                              oi.total_amount as line_total
                       FROM order_items oi
                       LEFT JOIN products p ON oi.product_id = p.id
                       WHERE oi.order_id = ?
                       ORDER BY oi.item_id ASC";
        
        $stmtItems = $conn->prepare($itemsQuery);
        $stmtItems->bind_param("i", $orderId);
        $stmtItems->execute();
        $itemsResult = $stmtItems->get_result();
        
        // Format created_at
        $createdDate = '';
        $createdTime = '';
        if (!empty($row['created_at'])) {
            $createdDateTime = new DateTime($row['created_at']);
            $createdDate = $createdDateTime->format('Y-m-d');
            $createdTime = $createdDateTime->format('H:i:s');
        }
        
        // Base order data - Reordered to match headers
        $baseData = [
            $row['order_id'],
            $row['tracking_number'] ?? '',
            $createdDate,
            $createdTime,
            $row['customer_name'] ?? 'N/A',
            $row['customer_phone'] ?? '',
            $row['customer_address_line1'] ?? ''
        ];
        
        // If order has products, add a row for each product
        if ($itemsResult && $itemsResult->num_rows > 0) {
            while ($item = $itemsResult->fetch_assoc()) {
                $data = array_merge($baseData, [
                    $item['product_code'] ?? '',
                    $item['product_name'] ?? '',
                    number_format((float)$item['unit_price'], 2, '.', ''),
                    number_format((float)$item['item_discount'], 2, '.', ''),
                    number_format((float)$row['subtotal'], 2, '.', '')
                ]);
                fputcsv($output, $data);
            }
        } else {
            // If no products, still add the order row with empty product fields
            $data = array_merge($baseData, ['', '', '', '', '']);
            fputcsv($output, $data);
        }
        
        $stmtItems->close();
    }
} else {
    // If no results, add a row indicating this
    $noDataRow = array_fill(0, count($headers), 'No data found');
    fputcsv($output, $noDataRow);
}

fclose($output);
$conn->close();
exit();
?>