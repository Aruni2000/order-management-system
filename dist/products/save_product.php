<?php
// Start session at the very beginning
session_start();

// Set content type for JSON response
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access. Please log in again.'
    ]);
    exit();
}

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method.'
    ]);
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/connection/db_connection.php');

// Verify CSRF token
if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode([
        'success' => false,
        'message' => 'Security token mismatch. Please refresh the page and try again.'
    ]);
    exit();
}

// User role (role_id 2) cannot add products
if (isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] == 2) {
    echo json_encode([
        'success' => false,
        'message' => 'You do not have permission to add products.'
    ]);
    exit();
}

// Initialize response array
$response = [
    'success' => false,
    'message' => '',
    'errors' => []
];

// Function to sanitize input
function sanitizeInput($input) {
    return trim(htmlspecialchars($input, ENT_QUOTES, 'UTF-8'));
}

try {
    // Get and sanitize form data
    $name = sanitizeInput($_POST['name'] ?? '');
    $status = sanitizeInput($_POST['status'] ?? '');
    $product_code = sanitizeInput($_POST['product_code'] ?? '');
    $description = sanitizeInput($_POST['description'] ?? '');
    
    // Default values for stock if inventory management is disabled
    $allow_inventory = isset($_SESSION['allow_inventory']) && $_SESSION['allow_inventory'] == 1;
    $stock_quantity = $allow_inventory ? intval($_POST['stock_quantity'] ?? 0) : 0;
    $low_stock_threshold = $allow_inventory ? intval($_POST['low_stock_threshold'] ?? 0) : 0;
    $category_id = intval($_POST['category_id'] ?? 0);
    
    // Handle tenant_id based on role
    $is_main_admin = isset($_SESSION['is_main_admin']) ? (int)$_SESSION['is_main_admin'] : 0;
    if ($is_main_admin && $_SESSION['role_id'] == 1) {
        $tenant_id = isset($_POST['tenant_id']) ? intval($_POST['tenant_id']) : 0;
        if ($tenant_id <= 0) {
            $response['errors']['tenant_id'] = 'Tenant is required';
            $response['message'] = 'Required fields are missing';
            echo json_encode($response);
            exit();
        }
    } else {
        $tenant_id = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
    }

    // -------------------------------------------------------------------------
    // CATEGORY TENANT VALIDATION
    // -------------------------------------------------------------------------
    if ($category_id > 0) {
        $catTenantStmt = $conn->prepare("SELECT id FROM categories WHERE id = ? AND tenant_id = ?");
        $catTenantStmt->bind_param("ii", $category_id, $tenant_id);
        $catTenantStmt->execute();
        if ($catTenantStmt->get_result()->num_rows === 0) {
            $response['errors']['category_id'] = 'Selected category is not available for the target company';
            $response['message'] = 'Please correct the errors below.';
            echo json_encode($response);
            exit();
        }
        $catTenantStmt->close();
    }

    // -------------------------------------------------------------------------
    // REQUIRED FIELDS VALIDATION
    // -------------------------------------------------------------------------
    if (empty($name) || empty($status) || empty($product_code) || empty($description) || $category_id <= 0) {
        $response['message'] = 'Required fields are missing';

        if (empty($description)) {
            $response['errors']['description'] = 'Description is required';
        }
        
        if ($category_id <= 0) {
            $response['errors']['category_id'] = 'Category is required';
        }

        echo json_encode($response);
        exit();
    }

    // Validate description minimum length (server-side match to JS)
    if (strlen($description) < 5) {
        $response['errors']['description'] = 'Description must be at least 5 characters long';
        $response['message'] = 'Please correct the errors below.';
        echo json_encode($response);
        exit();
    }

    // Check for duplicate product code per tenant
    if (!empty($product_code)) {
        $checkCodeQuery = "SELECT id FROM products WHERE product_code = ? AND tenant_id = ? LIMIT 1";
        $checkCodeStmt = $conn->prepare($checkCodeQuery);
        $checkCodeStmt->bind_param("si", $product_code, $tenant_id);
        $checkCodeStmt->execute();
        $codeResult = $checkCodeStmt->get_result();

        if ($codeResult->num_rows > 0) {
            $response['errors']['product_code'] = 'A product with this code already exists';
            $response['message'] = 'Please correct the errors below';
            echo json_encode($response);
            exit();
        }
        $checkCodeStmt->close();
    }

    // Prepare insert query
    $insertQuery = "INSERT INTO products (name, description, status, product_code, stock_quantity, low_stock_threshold, category_id, tenant_id) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $insertStmt = $conn->prepare($insertQuery);

    if (!$insertStmt) {
        throw new Exception("Database prepare error: " . $conn->error);
    }

    // Bind parameters
    $insertStmt->bind_param("ssssiiii", $name, $description, $status, $product_code, $stock_quantity, $low_stock_threshold, $category_id, $tenant_id);

    // Execute the query
    if ($insertStmt->execute()) {
        $product_id = $conn->insert_id;

        // Log the action in user_logs table
        if (isset($_SESSION['user_id'])) {
            $user_id = $_SESSION['user_id'];
            $action_type = 'product_create';
            // Fetch category name
            $catName = '';
            $catStmt = $conn->prepare("SELECT name FROM categories WHERE id = ?");
            if ($catStmt) {
                $catStmt->bind_param("i", $category_id);
                $catStmt->execute();
                $catResult = $catStmt->get_result();
                if ($catResult && $catRow = $catResult->fetch_assoc()) {
                    $catName = $catRow['name'];
                }
                $catStmt->close();
            }
            $details = "Created Product - Name: {$name}, Code: {$product_code}, Status: {$status}, Stock: {$stock_quantity}, Stock Warning Level: {$low_stock_threshold}, Category: '{$catName}'";

            $logQuery = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) 
                         VALUES (?, ?, ?, ?, NOW())";
            $logStmt = $conn->prepare($logQuery);

            if ($logStmt) {
                $logStmt->bind_param("isis", $user_id, $action_type, $product_id, $details);
                $logStmt->execute();
                $logStmt->close();
            }
        }

        // Close prepared statements
        $insertStmt->close();

        // Success response
        $response['success'] = true;
        $response['message'] = "Product '{$name}' has been successfully added to the system!";
        $response['product_id'] = $product_id;

    } else {
        throw new Exception("Database execution error: " . $insertStmt->error);
    }

} catch (Exception $e) {
    error_log("Product creation error: " . $e->getMessage());

    $response['success'] = false;
    $response['message'] = 'An error occurred while adding the product. Please try again.';

    if (defined('DEBUG_MODE') && DEBUG_MODE === true) {
        $response['debug_message'] = $e->getMessage();
    }

} finally {
    if (isset($conn)) {
        $conn->close();
    }
}

echo json_encode($response);
exit();
?>
