<?php
// Start session
session_start();

// Set header for JSON response
header('Content-Type: application/json');

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Check if user is logged in and is admin
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access. Please login.'
    ]);
    exit();
}

// Check if user is admin
$user_id = $_SESSION['user_id'];
$role_check_sql = "SELECT role_id FROM users WHERE id = ? AND status = 'active'";
$role_stmt = $conn->prepare($role_check_sql);
$role_stmt->bind_param("i", $user_id);
$role_stmt->execute();
$role_result = $role_stmt->get_result();

if ($role_result->num_rows === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'User not found or inactive.'
    ]);
    exit();
}

$user_role = $role_result->fetch_assoc();
if ($user_role['role_id'] != 1) {
    echo json_encode([
        'success' => false,
        'message' => 'Access denied. Admin privileges required.'
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

// Initialize response array
$response = [
    'success' => false,
    'message' => '',
    'errors' => []
];

try {
    // Get and sanitize input data
    $company_name = trim($_POST['company_name'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $email = trim(strtolower($_POST['email'] ?? ''));
    $phone = trim($_POST['phone'] ?? '');
    $status = trim($_POST['status'] ?? 'active');
    $is_main_admin = isset($_POST['is_main_admin']) ? (int)$_POST['is_main_admin'] : 0;

    // Validation
    $errors = [];

    // Validate company name
    if (empty($company_name)) {
        $errors['company_name'] = 'Company name is required';
    } elseif (strlen($company_name) < 2) {
        $errors['company_name'] = 'Company name must be at least 2 characters long';
    } elseif (strlen($company_name) > 255) {
        $errors['company_name'] = 'Company name is too long (maximum 255 characters)';
    }

    // Validate contact person
    if (empty($contact_person)) {
        $errors['contact_person'] = 'Contact person name is required';
    } elseif (strlen($contact_person) < 2) {
        $errors['contact_person'] = 'Contact person name must be at least 2 characters long';
    } elseif (strlen($contact_person) > 255) {
        $errors['contact_person'] = 'Contact person name is too long (maximum 255 characters)';
    } elseif (!preg_match("/^[a-zA-Z\s.\-']+$/", $contact_person)) {
        $errors['contact_person'] = 'Contact person name can only contain letters, spaces, dots, hyphens, and apostrophes';
    }

    // Validate email
    if (empty($email)) {
        $errors['email'] = 'Email address is required';
    } elseif (strlen($email) > 100) {
        $errors['email'] = 'Email address is too long (maximum 100 characters)';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address';
    } else {
        // Check if email already exists
        $check_email_sql = "SELECT tenant_id FROM tenants WHERE email = ?";
        $check_stmt = $conn->prepare($check_email_sql);
        $check_stmt->bind_param("s", $email);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $errors['email'] = 'This email address is already registered';
        }
        $check_stmt->close();
    }

    // Validate phone
    if (empty($phone)) {
        $errors['phone'] = 'Phone number is required';
    } else {
        $clean_phone = preg_replace('/\s+/', '', $phone);
        if (!preg_match("/^(0|94|\+94)?[1-9][0-9]{8}$/", $clean_phone)) {
            $errors['phone'] = 'Please enter a valid Sri Lankan phone number (e.g., 0771234567)';
        } else {
            // Check if phone already exists
            $check_phone_sql = "SELECT tenant_id FROM tenants WHERE phone = ?";
            $check_stmt = $conn->prepare($check_phone_sql);
            $check_stmt->bind_param("s", $phone);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $errors['phone'] = 'This phone number is already registered';
            }
            $check_stmt->close();
        }
    }

    // Validate status
    if (!in_array($status, ['active', 'inactive'])) {
        $errors['status'] = 'Invalid status value';
    }

    // Validate is_main_admin
    if (!in_array($is_main_admin, [0, 1])) {
        $errors['is_main_admin'] = 'Invalid main admin value';
    }

    // If there are validation errors, return them
    if (!empty($errors)) {
        $response['message'] = 'Please correct the errors in the form';
        $response['errors'] = $errors;
        echo json_encode($response);
        exit();
    }

    // Begin transaction
    $conn->begin_transaction();

    // Prepare insert statement
    $insert_sql = "INSERT INTO tenants (company_name, contact_person, email, phone, status, is_main_admin, created_at, updated_at) 
                   VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())";
    
    $stmt = $conn->prepare($insert_sql);
    
    if (!$stmt) {
        throw new Exception('Failed to prepare statement: ' . $conn->error);
    }

    // Bind parameters
    $stmt->bind_param("sssssi", $company_name, $contact_person, $email, $phone, $status, $is_main_admin);

    // Execute statement
    if ($stmt->execute()) {
        $tenant_id = $conn->insert_id;
        
        // Create initial branding record for the new tenant
        $branding_sql = "INSERT INTO branding (company_name, email, hotline, tenant_id, active, created_at, updated_at) 
                         VALUES (?, ?, ?, ?, 1, NOW(), NOW())";
        $branding_stmt = $conn->prepare($branding_sql);
        if ($branding_stmt) {
            $branding_stmt->bind_param("sssi", $company_name, $email, $phone, $tenant_id);
            if (!$branding_stmt->execute()) {
                // error_log("Failed to create initial branding for tenant ID $tenant_id: " . $branding_stmt->error);
            }
            $branding_stmt->close();
        }

        
        // Commit transaction
        $conn->commit();

        
        $response['success'] = true;
        $response['message'] = 'Tenant "' . htmlspecialchars($company_name) . '" has been successfully added!';
        $response['tenant_id'] = $tenant_id;
    } else {
        throw new Exception('Failed to insert tenant: ' . $stmt->error);
    }

    $stmt->close();

} catch (Exception $e) {
    // Rollback transaction on error
    if ($conn) {
        $conn->rollback();
    }
    
    $response['success'] = false;
    $response['message'] = 'Error: ' . $e->getMessage();
    
    // Log error for debugging (optional)
    error_log('Tenant creation error: ' . $e->getMessage());
}

// Close connection
if (isset($conn)) {
    $conn->close();
}

// Return JSON response
echo json_encode($response);
exit();
?>