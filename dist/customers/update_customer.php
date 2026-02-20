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
        'redirect' => '/OMS/dist/pages/login.php'
    ]);
    exit();
}

// Include the database connection file
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

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
            'redirect' => '/OMS/dist/pages/login.php'
        ]);
        exit();
    }

    // Get and sanitize form data
    $customer_id = intval($_POST['customer_id'] ?? 0);
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

    // Validate customer ID
    if ($customer_id <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid customer ID.'
        ]);
        exit();
    }

    // Check if customer exists and get all current data including city name for comparison
    $customerCheckStmt = $conn->prepare("
        SELECT c.customer_id, c.name, c.email, c.phone, c.phone_2, c.status, 
               c.address_line1, c.address_line2, c.city_id, ct.city_name 
        FROM customers c
        LEFT JOIN city_table ct ON c.city_id = ct.city_id
        WHERE c.customer_id = ?
    ");
    $customerCheckStmt->bind_param("i", $customer_id);
    $customerCheckStmt->execute();
    $customerCheckResult = $customerCheckStmt->get_result();
    
    if ($customerCheckResult->num_rows === 0) {
        $customerCheckStmt->close();
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Customer not found.'
        ]);
        exit();
    }
    
    $existingCustomer = $customerCheckResult->fetch_assoc();
    $customerCheckStmt->close();

    // ============================================
    // COMPREHENSIVE VALIDATION
    // ============================================
    $errors = [];

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

    // 2. EMAIL VALIDATION (Optional field)
    if (!empty($email)) {
        if (strlen($email) > 100) {
            $errors['email'] = 'Email is too long (maximum 100 characters)';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email format';
        }
    }

    // 3. PRIMARY PHONE VALIDATION
    if (empty($phone)) {
        $errors['phone'] = 'Primary phone number is required';
    } elseif (strlen($phone) !== 10) {
        $errors['phone'] = 'Phone must be exactly 10 digits';
    } elseif (!preg_match('/^0[1-9][0-9]{8}$/', $phone)) {
        $errors['phone'] = 'Invalid phone format. Must start with 0 followed by 9 digits';
    }

    // 4. SECONDARY PHONE VALIDATION (Optional field)
    if (!empty($phone_2)) {
        if (strlen($phone_2) !== 10) {
            $errors['phone_2'] = 'Phone 2 must be exactly 10 digits';
        } elseif (!preg_match('/^0[1-9][0-9]{8}$/', $phone_2)) {
            $errors['phone_2'] = 'Invalid phone 2 format. Must start with 0 followed by 9 digits';
        } elseif ($phone === $phone_2) {
            $errors['phone_2'] = 'Phone 2 cannot be the same as the primary phone number';
        }
    }

    // 5. ADDRESS VALIDATION
    if (empty($address_line1)) {
        $errors['address_line1'] = 'Address Line 1 is required';
    } elseif (strlen($address_line1) < 3) {
        $errors['address_line1'] = 'Address too short (minimum 3 characters)';
    } elseif (strlen($address_line1) > 255) {
        $errors['address_line1'] = 'Address is too long (maximum 255 characters)';
    }

    if (!empty($address_line2) && strlen($address_line2) > 255) {
        $errors['address_line2'] = 'Address Line 2 is too long (maximum 255 characters)';
    }

    // 6. CITY VALIDATION
    if (empty($city_id) || $city_id <= 0) {
        $errors['city_id'] = 'City selection is required';
    }

    // 7. STATUS VALIDATION
    if (!in_array($status, ['Active', 'Inactive'])) {
        $status = 'Active';
    }

    // ============================================
    // DATABASE DUPLICATE CHECKS (excluding current customer)
    // ============================================

    if (empty($errors)) {

        // 8. CHECK DUPLICATE EMAIL (if changed)
        if (!empty($email) && $email !== ($existingCustomer['email'] ?? '')) {
            $emailCheckStmt = $conn->prepare("SELECT customer_id, name FROM customers WHERE email = ? AND customer_id != ?");
            $emailCheckStmt->bind_param("si", $email, $customer_id);
            $emailCheckStmt->execute();
            $emailCheckResult = $emailCheckStmt->get_result();
            
            if ($emailCheckResult->num_rows > 0) {
                $duplicateCustomer = $emailCheckResult->fetch_assoc();
                $errors['email'] = 'Email already registered to ' . htmlspecialchars($duplicateCustomer['name']);
            }
            $emailCheckStmt->close();
        }

        // 9. CHECK PRIMARY PHONE AS PRIMARY NUMBER (if changed)
        if (!empty($phone) && $phone !== $existingCustomer['phone']) {
            $phoneCheckStmt = $conn->prepare("SELECT customer_id, name FROM customers WHERE phone = ? AND customer_id != ?");
            $phoneCheckStmt->bind_param("si", $phone, $customer_id);
            $phoneCheckStmt->execute();
            $phoneCheckResult = $phoneCheckStmt->get_result();
            
            if ($phoneCheckResult->num_rows > 0) {
                $duplicateCustomer = $phoneCheckResult->fetch_assoc();
                $errors['phone'] = 'This number is already registered as primary phone for ' . htmlspecialchars($duplicateCustomer['name']);
            }
            $phoneCheckStmt->close();
        }

        // 10. CHECK PRIMARY PHONE AS SECONDARY NUMBER (if changed)
        if (!empty($phone) && $phone !== $existingCustomer['phone'] && !isset($errors['phone'])) {
            $phoneAsPhone2Stmt = $conn->prepare("SELECT customer_id, name FROM customers WHERE phone_2 = ? AND customer_id != ?");
            $phoneAsPhone2Stmt->bind_param("si", $phone, $customer_id);
            $phoneAsPhone2Stmt->execute();
            $phoneAsPhone2Result = $phoneAsPhone2Stmt->get_result();
            
            if ($phoneAsPhone2Result->num_rows > 0) {
                $duplicateCustomer = $phoneAsPhone2Result->fetch_assoc();
                $errors['phone'] = 'This number is already registered as secondary phone for ' . htmlspecialchars($duplicateCustomer['name']);
            }
            $phoneAsPhone2Stmt->close();
        }

        // 11. CHECK SECONDARY PHONE AS PRIMARY NUMBER (if changed and provided)
        if (!empty($phone_2) && $phone_2 !== ($existingCustomer['phone_2'] ?? '')) {
            $phone2AsPrimaryStmt = $conn->prepare("SELECT customer_id, name FROM customers WHERE phone = ? AND customer_id != ?");
            $phone2AsPrimaryStmt->bind_param("si", $phone_2, $customer_id);
            $phone2AsPrimaryStmt->execute();
            $phone2AsPrimaryResult = $phone2AsPrimaryStmt->get_result();
            
            if ($phone2AsPrimaryResult->num_rows > 0) {
                $duplicateCustomer = $phone2AsPrimaryResult->fetch_assoc();
                $errors['phone_2'] = 'This number is already registered as primary phone for ' . htmlspecialchars($duplicateCustomer['name']);
            }
            $phone2AsPrimaryStmt->close();
        }

        // 12. CHECK SECONDARY PHONE AS SECONDARY NUMBER (if changed and provided)
        if (!empty($phone_2) && $phone_2 !== ($existingCustomer['phone_2'] ?? '') && !isset($errors['phone_2'])) {
            $phone2AsPhone2Stmt = $conn->prepare("SELECT customer_id, name FROM customers WHERE phone_2 = ? AND customer_id != ?");
            $phone2AsPhone2Stmt->bind_param("si", $phone_2, $customer_id);
            $phone2AsPhone2Stmt->execute();
            $phone2AsPhone2Result = $phone2AsPhone2Stmt->get_result();
            
            if ($phone2AsPhone2Result->num_rows > 0) {
                $duplicateCustomer = $phone2AsPhone2Result->fetch_assoc();
                $errors['phone_2'] = 'This number is already registered as secondary phone for ' . htmlspecialchars($duplicateCustomer['name']);
            }
            $phone2AsPhone2Stmt->close();
        }

        // 13. VALIDATE CITY EXISTS AND IS ACTIVE
        if ($city_id > 0 && !isset($errors['city_id'])) {
            $cityCheckStmt = $conn->prepare("
                SELECT city_id, city_name, is_active 
                FROM city_table 
                WHERE city_id = ?
            ");
            $cityCheckStmt->bind_param("i", $city_id);
            $cityCheckStmt->execute();
            $cityCheckResult = $cityCheckStmt->get_result();
            
            if ($cityCheckResult->num_rows === 0) {
                $errors['city_id'] = 'Selected city does not exist. Please select a valid city.';
            } else {
                $cityData = $cityCheckResult->fetch_assoc();
                
                if ($cityData['is_active'] != 1) {
                    $errors['city_id'] = 'Selected city "' . htmlspecialchars($cityData['city_name']) . '" is inactive. Please select an active city.';
                }
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
    // CHECK FOR ACTUAL CHANGES
    // ============================================
    $hasChanges = false;
    $changes = [];

    if ($name !== $existingCustomer['name']) {
        $hasChanges = true;
        $changes[] = "Name: '{$existingCustomer['name']}' → '{$name}'";
    }
    
    $existingEmail = $existingCustomer['email'] ?? '';
    if ($email !== $existingEmail) {
        $hasChanges = true;
        $oldEmailDisplay = empty($existingEmail) ? '(empty)' : $existingEmail;
        $newEmailDisplay = empty($email) ? '(empty)' : $email;
        $changes[] = "Email: '{$oldEmailDisplay}' → '{$newEmailDisplay}'";
    }
    
    if ($phone !== $existingCustomer['phone']) {
        $hasChanges = true;
        $changes[] = "Phone: '{$existingCustomer['phone']}' → '{$phone}'";
    }
    
    $existingPhone2 = $existingCustomer['phone_2'] ?? '';
    if ($phone_2 !== $existingPhone2) {
        $hasChanges = true;
        $oldPhone2Display = empty($existingPhone2) ? '(empty)' : $existingPhone2;
        $newPhone2Display = empty($phone_2) ? '(empty)' : $phone_2;
        $changes[] = "Phone 2: '{$oldPhone2Display}' → '{$newPhone2Display}'";
    }
    
    if ($status !== $existingCustomer['status']) {
        $hasChanges = true;
        $changes[] = "Status: '{$existingCustomer['status']}' → '{$status}'";
    }
    
    if ($address_line1 !== $existingCustomer['address_line1']) {
        $hasChanges = true;
        $changes[] = "Address Line 1: '{$existingCustomer['address_line1']}' → '{$address_line1}'";
    }
    
    $existingAddress2 = $existingCustomer['address_line2'] ?? '';
    if ($address_line2 !== $existingAddress2) {
        $hasChanges = true;
        $oldAddr2Display = empty($existingAddress2) ? '(empty)' : $existingAddress2;
        $newAddr2Display = empty($address_line2) ? '(empty)' : $address_line2;
        $changes[] = "Address Line 2: '{$oldAddr2Display}' → '{$newAddr2Display}'";
    }
    
    if ($city_id != $existingCustomer['city_id']) {
        $hasChanges = true;
        
        $newCityStmt = $conn->prepare("SELECT city_name FROM city_table WHERE city_id = ?");
        $newCityStmt->bind_param("i", $city_id);
        $newCityStmt->execute();
        $newCityResult = $newCityStmt->get_result();
        $newCityName = $newCityResult->fetch_assoc()['city_name'] ?? 'Unknown';
        $newCityStmt->close();
        
        $changes[] = "City: '{$existingCustomer['city_name']}' → '{$newCityName}'";
    }

    // If no changes detected, return early
    if (!$hasChanges) {
        $response['success'] = true;
        $response['message'] = 'No changes were made to the customer.';
        $response['customer_id'] = $customer_id;
        $response['data'] = [
            'id' => $customer_id,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'phone_2' => $phone_2,
            'status' => $status,
            'city_id' => $city_id
        ];
        echo json_encode($response);
        exit();
    }

    // ============================================
    // UPDATE CUSTOMER
    // ============================================

    // Start transaction
    $conn->begin_transaction();

    // Prepare values - use NULL for empty optional fields
    $emailValue = !empty($email) ? $email : null;
    $phone2Value = !empty($phone_2) ? $phone_2 : null;
    $address2Value = !empty($address_line2) ? $address_line2 : null;

    // Prepare and execute customer update
    $updateStmt = $conn->prepare("
        UPDATE customers 
        SET name = ?, 
            email = ?, 
            phone = ?, 
            phone_2 = ?,
            status = ?, 
            address_line1 = ?, 
            address_line2 = ?, 
            city_id = ?, 
            updated_at = NOW()
        WHERE customer_id = ?
    ");

    $updateStmt->bind_param("sssssssii", $name, $emailValue, $phone, $phone2Value, $status, $address_line1, $address2Value, $city_id, $customer_id);

    if ($updateStmt->execute()) {
        if ($updateStmt->affected_rows > 0) {
            // Log customer update action with detailed changes
            $logDetails = "Customer updated - " . implode(', ', $changes);
            
            $logResult = logUserAction($conn, $currentUserId, 'customer_update', $customer_id, $logDetails);
            
            if (!$logResult) {
                error_log("Failed to log customer update action for customer ID: $customer_id");
            }
            
            // Commit transaction
            $conn->commit();
            
            // Get updated city name for response
            $cityNameStmt = $conn->prepare("SELECT city_name FROM city_table WHERE city_id = ?");
            $cityNameStmt->bind_param("i", $city_id);
            $cityNameStmt->execute();
            $cityNameResult = $cityNameStmt->get_result();
            $cityName = $cityNameResult->fetch_assoc()['city_name'] ?? '';
            $cityNameStmt->close();
            
            // Success response
            $response['success'] = true;
            $response['message'] = 'Customer "' . htmlspecialchars($name) . '" has been successfully updated.';
            $response['customer_id'] = $customer_id;
            $response['data'] = [
                'id' => $customer_id,
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'phone_2' => $phone_2,
                'status' => $status,
                'city_id' => $city_id,
                'city_name' => $cityName
            ];
            
            // Log success
            $phone2Display = $phone_2 ?: 'empty';
            $emailDisplay = $email ?: 'empty';
            error_log("Customer updated successfully - ID: $customer_id, Name: $name, Email: {$emailDisplay}, Phone: {$phone}, Phone 2: {$phone2Display}, City ID: $city_id, Updated by User ID: $currentUserId");
        } else {
            $conn->commit();
            $response['success'] = true;
            $response['message'] = 'No changes were made to the customer.';
            $response['customer_id'] = $customer_id;
        }
        
    } else {
        // Rollback transaction
        $conn->rollback();
        
        // Database error
        error_log("Failed to update customer: " . $updateStmt->error);
        $response['message'] = 'Failed to update customer. Please try again.';
    }

    $updateStmt->close();

} catch (Exception $e) {
    // Rollback transaction if it was started
    if (isset($conn) && $conn->ping()) {
        $conn->rollback();
    }
    
    // Log error
    error_log("Error updating customer: " . $e->getMessage());
    
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