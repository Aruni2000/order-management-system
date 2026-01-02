<?php
// Start session at the very beginning
session_start();

// Set JSON content type
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access. Please login again.',
        'redirect' => '/order_management/dist/pages/login.php'
    ]);
    exit();
}

// Include the database connection file
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/connection/db_connection.php');

// Check database connection
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed. Please try again later.'
    ]);
    exit();
}

// Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method.'
    ]);
    exit();
}

// CSRF token validation
if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || 
    $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid security token. Please refresh the page and try again.'
    ]);
    exit();
}

// Function to log user actions
function logUserAction($conn, $userId, $actionType, $targetId, $details = '') {
    try {
        $logSql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, ?, ?, ?, NOW())";
        $logStmt = $conn->prepare($logSql);
        
        if (!$logStmt) {
            error_log("Failed to prepare user log statement: " . $conn->error);
            return false;
        }
        
        $logStmt->bind_param("isis", $userId, $actionType, $targetId, $details);
        $result = $logStmt->execute();
        
        if (!$result) {
            error_log("Failed to log user action: " . $logStmt->error);
        }
        
        $logStmt->close();
        return $result;
    } catch (Exception $e) {
        error_log("Exception in logUserAction: " . $e->getMessage());
        return false;
    }
}

// Initialize response array
$response = [
    'success' => false,
    'message' => '',
    'errors' => []
];

try {
    // Get current user ID for logging
    $currentUserId = $_SESSION['user_id'] ?? null;
    if (!$currentUserId) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'User session not found. Please login again.',
            'redirect' => '/order_management/dist/pages/login.php'
        ]);
        exit();
    }

    // Get and sanitize form data
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $phone_2 = trim($_POST['phone_2'] ?? '');
    $status = trim($_POST['status'] ?? 'Active');
    $address_line1 = trim($_POST['address_line1'] ?? '');
    $address_line2 = trim($_POST['address_line2'] ?? '');
    $city_id = intval($_POST['city_id'] ?? 0);

    // Clean phone numbers (remove non-digits)
    $phone = preg_replace('/\D/', '', $phone);
    $phone_2 = preg_replace('/\D/', '', $phone_2);

    // Validation errors array
    $errors = [];

    // ============================================
    // COMPREHENSIVE VALIDATION
    // ============================================

    // 1. NAME VALIDATION
    if (empty($name)) {
        $errors['name'] = 'Customer name is required';
    } elseif (strlen($name) < 2) {
        $errors['name'] = 'Name must be at least 2 characters';
    } elseif (strlen($name) > 255) {
        $errors['name'] = 'Name is too long (maximum 255 characters)';
    } elseif (!preg_match('/^[a-zA-Z\s.\-\']+$/', $name)) {
        $errors['name'] = 'Name contains invalid characters';
    }

    // 2. EMAIL VALIDATION
    if (!empty($email)) {
        if (strlen($email) > 100) {
            $errors['email'] = 'Email is too long (maximum 100 characters)';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email format';
        }
    } else {
        $email = null; // Set to null for database
    }

    // 3. PRIMARY PHONE VALIDATION
    if (empty($phone)) {
        $errors['phone'] = 'Primary phone number is required';
    } elseif (strlen($phone) !== 10) {
        $errors['phone'] = 'Phone must be exactly 10 digits';
    } elseif (!preg_match('/^0[1-9][0-9]{8}$/', $phone)) {
        $errors['phone'] = 'Invalid phone format. Must start with 0 followed by 9 digits';
    }

    // 4. SECONDARY PHONE VALIDATION (if provided)
    if (!empty($phone_2)) {
        if (strlen($phone_2) !== 10) {
            $errors['phone_2'] = 'Phone 2 must be exactly 10 digits';
        } elseif (!preg_match('/^0[1-9][0-9]{8}$/', $phone_2)) {
            $errors['phone_2'] = 'Invalid phone 2 format. Must start with 0 followed by 9 digits';
        } elseif ($phone === $phone_2) {
            // Check if phone_2 is same as primary phone
            $errors['phone_2'] = 'Phone 2 cannot be the same as the primary phone number';
        }
    } else {
        $phone_2 = null; // Set to null for database
    }

    // 5. ADDRESS VALIDATION
    if (empty($address_line1)) {
        $errors['address_line1'] = 'Address Line 1 is required';
    } elseif (strlen($address_line1) < 3) {
        $errors['address_line1'] = 'Address too short (minimum 3 characters)';
    } elseif (strlen($address_line1) > 255) {
        $errors['address_line1'] = 'Address is too long (maximum 255 characters)';
    }

    // 6. CITY VALIDATION
    if (empty($city_id) || $city_id <= 0) {
        $errors['city_id'] = 'City selection is required';
    }

    // 7. STATUS VALIDATION
    if (!in_array($status, ['Active', 'Inactive'])) {
        $status = 'Active'; // Default to Active if invalid
    }

    // ============================================
    // DATABASE DUPLICATE CHECKS
    // ============================================

    // Only proceed with duplicate checks if basic validation passed
    if (empty($errors)) {

        // 8. CHECK DUPLICATE EMAIL
        if (!empty($email)) {
            $emailCheckStmt = $conn->prepare("SELECT customer_id, name FROM customers WHERE email = ?");
            $emailCheckStmt->bind_param("s", $email);
            $emailCheckStmt->execute();
            $emailCheckResult = $emailCheckStmt->get_result();
            
            if ($emailCheckResult->num_rows > 0) {
                $existingCustomer = $emailCheckResult->fetch_assoc();
                $errors['email'] = 'Email already registered to ' . htmlspecialchars($existingCustomer['name']);
            }
            $emailCheckStmt->close();
        }

        // 9. CHECK PRIMARY PHONE AS PRIMARY NUMBER
        if (!empty($phone)) {
            $phoneCheckStmt = $conn->prepare("SELECT customer_id, name FROM customers WHERE phone = ?");
            $phoneCheckStmt->bind_param("s", $phone);
            $phoneCheckStmt->execute();
            $phoneCheckResult = $phoneCheckStmt->get_result();
            
            if ($phoneCheckResult->num_rows > 0) {
                $existingCustomer = $phoneCheckResult->fetch_assoc();
                $errors['phone'] = 'This number is already registered as primary phone for ' . htmlspecialchars($existingCustomer['name']);
            }
            $phoneCheckStmt->close();
        }

        // 10. CHECK PRIMARY PHONE AS SECONDARY NUMBER
        if (!empty($phone) && !isset($errors['phone'])) {
            $phoneAsPhone2Stmt = $conn->prepare("SELECT customer_id, name FROM customers WHERE phone_2 = ?");
            $phoneAsPhone2Stmt->bind_param("s", $phone);
            $phoneAsPhone2Stmt->execute();
            $phoneAsPhone2Result = $phoneAsPhone2Stmt->get_result();
            
            if ($phoneAsPhone2Result->num_rows > 0) {
                $existingCustomer = $phoneAsPhone2Result->fetch_assoc();
                $errors['phone'] = 'This number is already registered as secondary phone for ' . htmlspecialchars($existingCustomer['name']);
            }
            $phoneAsPhone2Stmt->close();
        }

        // 11. CHECK SECONDARY PHONE AS PRIMARY NUMBER (if phone_2 is provided)
        if (!empty($phone_2)) {
            $phone2AsPrimaryStmt = $conn->prepare("SELECT customer_id, name FROM customers WHERE phone = ?");
            $phone2AsPrimaryStmt->bind_param("s", $phone_2);
            $phone2AsPrimaryStmt->execute();
            $phone2AsPrimaryResult = $phone2AsPrimaryStmt->get_result();
            
            if ($phone2AsPrimaryResult->num_rows > 0) {
                $existingCustomer = $phone2AsPrimaryResult->fetch_assoc();
                $errors['phone_2'] = 'This number is already registered as primary phone for ' . htmlspecialchars($existingCustomer['name']);
            }
            $phone2AsPrimaryStmt->close();
        }

        // 12. CHECK SECONDARY PHONE AS SECONDARY NUMBER (if phone_2 is provided)
        if (!empty($phone_2) && !isset($errors['phone_2'])) {
            $phone2AsPhone2Stmt = $conn->prepare("SELECT customer_id, name FROM customers WHERE phone_2 = ?");
            $phone2AsPhone2Stmt->bind_param("s", $phone_2);
            $phone2AsPhone2Stmt->execute();
            $phone2AsPhone2Result = $phone2AsPhone2Stmt->get_result();
            
            if ($phone2AsPhone2Result->num_rows > 0) {
                $existingCustomer = $phone2AsPhone2Result->fetch_assoc();
                $errors['phone_2'] = 'This number is already registered as secondary phone for ' . htmlspecialchars($existingCustomer['name']);
            }
            $phone2AsPhone2Stmt->close();
        }

        // 13. VALIDATE CITY EXISTS AND IS ACTIVE
        if ($city_id > 0 && !isset($errors['city_id'])) {
            $cityCheckStmt = $conn->prepare("SELECT city_id, city_name FROM city_table WHERE city_id = ? AND is_active = 1");
            $cityCheckStmt->bind_param("i", $city_id);
            $cityCheckStmt->execute();
            $cityCheckResult = $cityCheckStmt->get_result();
            
            if ($cityCheckResult->num_rows === 0) {
                $errors['city_id'] = 'Selected city is not valid or inactive';
            }
            $cityCheckStmt->close();
        }
    }

    // ============================================
    // RETURN ERRORS IF ANY
    // ============================================
    if (!empty($errors)) {
        $response['errors'] = $errors;
        $response['message'] = 'Please correct the errors and try again.';
        echo json_encode($response);
        exit();
    }

    // ============================================
    // INSERT CUSTOMER
    // ============================================

    // Start transaction
    $conn->begin_transaction();

    // Prepare and execute customer insert
    $insertStmt = $conn->prepare("
        INSERT INTO customers (name, email, phone, phone_2, status, address_line1, address_line2, city_id, created_at, updated_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");

    $insertStmt->bind_param("sssssssi", $name, $email, $phone, $phone_2, $status, $address_line1, $address_line2, $city_id);

    if ($insertStmt->execute()) {
        $customer_id = $conn->insert_id;
        
        // Log customer creation action
        $phone2Display = $phone_2 ? $phone_2 : 'N/A';
        $emailDisplay = $email ? $email : 'N/A';
        $logDetails = "New customer added - Name: {$name}, Email: {$emailDisplay}, Primary Phone: {$phone}, Phone 2: {$phone2Display}, Status: {$status}";
        $logResult = logUserAction($conn, $currentUserId, 'customer_create', $customer_id, $logDetails);
        
        if (!$logResult) {
            error_log("Failed to log customer creation action for customer ID: $customer_id");
        }
        
        // Commit transaction
        $conn->commit();
        
        // Success response
        $response['success'] = true;
        $response['message'] = 'Customer "' . htmlspecialchars($name) . '" has been successfully added to the system.';
        $response['customer_id'] = $customer_id;
        $response['data'] = [
            'id' => $customer_id,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'phone_2' => $phone_2,
            'status' => $status
        ];
        
        // Log success
        error_log("Customer added successfully - ID: $customer_id, Name: $name, Email: {$emailDisplay}, Primary Phone: {$phone}, Phone 2: {$phone2Display}, Added by User ID: $currentUserId");
        
    } else {
        // Rollback transaction
        $conn->rollback();
        
        // Database error
        error_log("Failed to insert customer: " . $insertStmt->error);
        $response['message'] = 'Failed to add customer. Please try again.';
    }

    $insertStmt->close();

} catch (Exception $e) {
    // Rollback transaction if it was started
    if (isset($conn) && $conn->ping()) {
        $conn->rollback();
    }
    
    // Log error
    error_log("Error adding customer: " . $e->getMessage());
    
    // Return error response
    $response['message'] = 'An unexpected error occurred. Please try again.';
    http_response_code(500);
    
} finally {
    // Close database connection
    if (isset($conn)) {
        $conn->close();
    }
}
// Return JSON response
echo json_encode($response);
exit();
?>