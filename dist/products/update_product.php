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
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Verify CSRF token
if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode([
        'success' => false,
        'message' => 'Security token mismatch. Please refresh the page and try again.'
    ]);
    exit();
}

// User role (role_id 2) cannot edit products
if (isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] == 2) {
    echo json_encode([
        'success' => false,
        'message' => 'You do not have permission to edit products.'
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

    // Get product ID
    $product_id = intval($_POST['product_id'] ?? 0);

    if ($product_id <= 0) {
        $response['message'] = 'Invalid product ID.';
        echo json_encode($response);
        exit();
    }

    // Check if product exists and enforce tenant isolation
    $is_main_admin = isset($_SESSION['is_main_admin']) ? (int)$_SESSION['is_main_admin'] : 0;
    $session_tenant_id = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;

    if ($is_main_admin && $_SESSION['role_id'] == 1) {
        $checkQuery = "SELECT * FROM products WHERE id = ? LIMIT 1";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bind_param("i", $product_id);
    } else {
        $checkQuery = "SELECT * FROM products WHERE id = ? AND tenant_id = ? LIMIT 1";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bind_param("ii", $product_id, $session_tenant_id);
    }
    
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows === 0) {
        $response['message'] = 'Product not found.';
        echo json_encode($response);
        exit();
    }

    $originalProduct = $checkResult->fetch_assoc();
    $checkStmt->close();

    // Get and sanitize form data
    $name = sanitizeInput($_POST['name'] ?? '');
    $status = sanitizeInput($_POST['status'] ?? '');
    $product_code = sanitizeInput($_POST['product_code'] ?? '');
    $description = sanitizeInput($_POST['description'] ?? '');
    
    // Price and stock are not editable in the product form; preserve existing values.
    // Stock is managed via GRN, orders and stock updates.
    
    // Default values for stock if inventory
    $allow_inventory = isset($_SESSION['allow_inventory']) && $_SESSION['allow_inventory'] == 1;
    $stock_quantity = intval($originalProduct['stock_quantity']);
    $low_stock_threshold = $allow_inventory ? intval($_POST['low_stock_threshold'] ?? $originalProduct['low_stock_threshold']) : intval($originalProduct['low_stock_threshold']);
    $category_id = intval($_POST['category_id'] ?? $originalProduct['category_id']);
    
    if ($is_main_admin && $_SESSION['role_id'] == 1) {
        $tenant_id = intval($_POST['tenant_id'] ?? $originalProduct['tenant_id']);
    } else {
        $tenant_id = intval($originalProduct['tenant_id']);
    }

    // Server-side validation
    $errors = [];

    // Validate name
    if (empty($name)) {
        $errors['name'] = 'Product name is required';
    } elseif (strlen($name) < 2) {
        $errors['name'] = 'Product name must be at least 2 characters long';
    } elseif (strlen($name) > 255) {
        $errors['name'] = 'Product name is too long (maximum 255 characters)';
    }

    // Validate status
    if (empty($status) || !in_array($status, ['active', 'inactive'])) {
        $errors['status'] = 'Please select a valid status';
    }

    // Validate product code
    if (empty($product_code)) {
        $errors['product_code'] = 'Product code is required';
    } elseif (strlen($product_code) < 2) {
        $errors['product_code'] = 'Product code must be at least 2 characters long';
    } elseif (strlen($product_code) > 50) {
        $errors['product_code'] = 'Product code is too long (maximum 50 characters)';
    } elseif (!preg_match('/^[a-zA-Z0-9\-_]+$/', $product_code)) {
        $errors['product_code'] = 'Product code can only contain letters, numbers, hyphens, and underscores';
    }

    // ⭐ MAKE DESCRIPTION **REQUIRED**
    if (empty($description)) {
        $errors['description'] = 'Description is required';
    } elseif (strlen($description) > 65535) {
        $errors['description'] = 'Description is too long (maximum 65,535 characters)';
    }

    // Validate stock fields
    if ($low_stock_threshold < 0) {
        $errors['low_stock_threshold'] = 'Stock Warning Level cannot be negative';
    }
    if ($category_id <= 0) {
        $errors['category_id'] = 'Category is required';
    }

    // Check for duplicate product code (excluding current product) per tenant
    if (empty($errors['product_code'])) {
        $checkCodeQuery = "SELECT id FROM products WHERE product_code = ? AND tenant_id = ? AND id != ? LIMIT 1";
        $checkCodeStmt = $conn->prepare($checkCodeQuery);
        $checkCodeStmt->bind_param("sii", $product_code, $tenant_id, $product_id);
        $checkCodeStmt->execute();
        $codeResult = $checkCodeStmt->get_result();

        if ($codeResult->num_rows > 0) {
            $errors['product_code'] = 'A product with this code already exists';
        }
        $checkCodeStmt->close();
    }

    // If errors exist → stop and return
    if (!empty($errors)) {
        $response['errors'] = $errors;
        $response['message'] = 'Please correct the errors below';
        echo json_encode($response);
        exit();
    }

    // Prepare update query
    $updateQuery = "UPDATE products 
                    SET name = ?, description = ?, status = ?, product_code = ?, stock_quantity = ?, low_stock_threshold = ?, category_id = ?, tenant_id = ?
                    WHERE id = ?";

    $updateStmt = $conn->prepare($updateQuery);

    if (!$updateStmt) {
        throw new Exception("Database prepare error: " . $conn->error);
    }

    // Bind parameters
    $updateStmt->bind_param("ssssiiiii", $name, $description, $status, $product_code, $stock_quantity, $low_stock_threshold, $category_id, $tenant_id, $product_id);

    // Execute the update
    if ($updateStmt->execute()) {

        if ($updateStmt->affected_rows > 0) {

            // Write to logs
            if (isset($_SESSION['user_id'])) {

                $user_id = $_SESSION['user_id'];
                $action_type = 'product_update';
                $changes = [];

                if ($originalProduct['name'] !== $name) {
                    $changes[] = "Name: '{$originalProduct['name']}' → '{$name}'";
                }
                if ($originalProduct['status'] !== $status) {
                    $changes[] = "Status: '{$originalProduct['status']}' → '{$status}'";
                }
                if ($originalProduct['product_code'] !== $product_code) {
                    $changes[] = "Code: '{$originalProduct['product_code']}' → '{$product_code}'";
                }
                if (($originalProduct['description'] ?? '') !== ($description ?? '')) {
                    $changes[] = "Description updated";
                }
                if (intval($originalProduct['category_id'] ?? 0) !== $category_id) {
                    $changes[] = "Category ID: {$originalProduct['category_id']} to {$category_id}";
                }
                if (intval($originalProduct['low_stock_threshold'] ?? 10) !== $low_stock_threshold) {
                    $changes[] = "Threshold: {$originalProduct['low_stock_threshold']} to {$low_stock_threshold}";
                }

                $details = "Updated Product '{$name}': " . implode(', ', $changes);

                $logQuery = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at)
                             VALUES (?, ?, ?, ?, NOW())";

                $logStmt = $conn->prepare($logQuery);
                if ($logStmt) {
                    $logStmt->bind_param("isis", $user_id, $action_type, $product_id, $details);
                    $logStmt->execute();
                    $logStmt->close();
                }
            }

            $response['success'] = true;
            $response['message'] = "Product '{$name}' has been successfully updated!";

        } else {
            $response['success'] = true;
            $response['message'] = "No changes were made to the product.";
        }

        $updateStmt->close();

    } else {
        throw new Exception("Database execution error: " . $updateStmt->error);
    }

} catch (Exception $e) {

    error_log("Product update error: " . $e->getMessage());

    if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
        if (strpos($e->getMessage(), 'product_code') !== false) {
            $response['errors']['product_code'] = 'A product with this code already exists';
        }
        $response['message'] = 'Please correct the errors below';
    } else {
        $response['message'] = 'An error occurred while updating the product. Please try again.';
    }

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
