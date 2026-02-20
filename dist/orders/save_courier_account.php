<?php
// Start session
session_start();

// Set header for JSON response
header('Content-Type: application/json');

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Initialize response array
$response = [
    'success' => false,
    'message' => '',
    'errors' => []
];

try {
    // Check if user is logged in
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        $response['message'] = 'Unauthorized access. Please login.';
        echo json_encode($response);
        exit();
    }

    // Verify user is admin
    $user_id = $_SESSION['user_id'];
    $role_check_sql = "SELECT role_id FROM users WHERE id = ? AND status = 'active'";
    $role_stmt = $conn->prepare($role_check_sql);
    $role_stmt->bind_param("i", $user_id);
    $role_stmt->execute();
    $role_result = $role_stmt->get_result();
    
    if ($role_result->num_rows === 0) {
        $response['message'] = 'User not found or inactive.';
        echo json_encode($response);
        exit();
    }
    
    $user_data = $role_result->fetch_assoc();
    if ($user_data['role_id'] != 1) {
        $response['message'] = 'Access denied. Admin privileges required.';
        echo json_encode($response);
        exit();
    }

    // Check if user is Main Admin
    if (!isset($_SESSION['is_main_admin']) || $_SESSION['is_main_admin'] != 1) {
        $response['message'] = 'Access denied. Main Admin privileges required.';
        echo json_encode($response);
        exit();
    }

    // Check if request method is POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $response['message'] = 'Invalid request method.';
        echo json_encode($response);
        exit();
    }

    // Validate and sanitize input
    $tenant_id = isset($_POST['tenant_id']) ? intval($_POST['tenant_id']) : 0;
    $courier_id = isset($_POST['courier_id']) ? intval($_POST['courier_id']) : 0;
    $origin_city_name = isset($_POST['origin_city_name']) ? trim($_POST['origin_city_name']) : null;
    $origin_state_name = isset($_POST['origin_state_name']) ? trim($_POST['origin_state_name']) : null;
    $api_key = isset($_POST['api_key']) ? trim($_POST['api_key']) : null;
    $client_id = isset($_POST['client_id']) ? trim($_POST['client_id']) : null;
    $has_api_new = isset($_POST['has_api_new']) ? 1 : 0;
    $has_api_existing = isset($_POST['has_api_existing']) ? 1 : 0;

    // Validation
    $errors = [];

    if ($tenant_id <= 0) {
        $errors['tenant_id'] = 'Please select a valid tenant.';
    }

    if ($courier_id <= 0) {
        $errors['courier_id'] = 'Please select a valid courier company.';
    }

    // Validate API integration - at least one must be selected
    if ($has_api_new == 0 && $has_api_existing == 0) {
        $errors['api_integration'] = 'Please select at least one API integration type.';
    }

    // If there are validation errors, return them
    if (!empty($errors)) {
        $response['message'] = 'Please fix the validation errors.';
        $response['errors'] = $errors;
        echo json_encode($response);
        exit();
    }

    // Check if tenant exists and is active
    $tenant_check_sql = "SELECT tenant_id, company_name FROM tenants WHERE tenant_id = ? AND status = 'active'";
    $tenant_stmt = $conn->prepare($tenant_check_sql);
    $tenant_stmt->bind_param("i", $tenant_id);
    $tenant_stmt->execute();
    $tenant_result = $tenant_stmt->get_result();

    if ($tenant_result->num_rows === 0) {
        $response['message'] = 'Selected tenant is invalid or inactive.';
        $response['errors']['tenant_id'] = 'Invalid tenant selection.';
        echo json_encode($response);
        exit();
    }

    // Fetch courier company details from courier_company table
    $courier_check_sql = "SELECT courier_id, courier_name, phone_number, email, address_line1, address_line2, city, status 
                          FROM courier_company 
                          WHERE courier_id = ?";
    $courier_stmt = $conn->prepare($courier_check_sql);
    $courier_stmt->bind_param("i", $courier_id);
    $courier_stmt->execute();
    $courier_result = $courier_stmt->get_result();

    if ($courier_result->num_rows === 0) {
        $response['message'] = 'Selected courier company not found.';
        $response['errors']['courier_id'] = 'Invalid courier company selection.';
        echo json_encode($response);
        exit();
    }

    $courier_data = $courier_result->fetch_assoc();

    // Check if courier company is active
    if ($courier_data['status'] !== 'active') {
        $response['message'] = 'Selected courier company is inactive.';
        $response['errors']['courier_id'] = 'Courier company is not active.';
        echo json_encode($response);
        exit();
    }

    // Check if this courier account already exists for this tenant
    $duplicate_check_sql = "SELECT co_id FROM couriers WHERE tenant_id = ? AND courier_id = ?";
    $duplicate_stmt = $conn->prepare($duplicate_check_sql);
    $duplicate_stmt->bind_param("ii", $tenant_id, $courier_id);
    $duplicate_stmt->execute();
    $duplicate_result = $duplicate_stmt->get_result();

    if ($duplicate_result->num_rows > 0) {
        $response['message'] = 'This courier account already exists for the selected tenant.';
        $response['errors']['courier_id'] = 'Duplicate courier account.';
        echo json_encode($response);
        exit();
    }

    // Special validation for Royal Express (courier_id = 14)
    if ($courier_id == 14) {
        if (empty($origin_city_name)) {
            $errors['origin_city_name'] = 'Origin city name is required for Royal Express.';
        }
        if (empty($origin_state_name)) {
            $errors['origin_state_name'] = 'Origin state name is required for Royal Express.';
        }
        
        if (!empty($errors)) {
            $response['message'] = 'Please provide required Royal Express details.';
            $response['errors'] = $errors;
            echo json_encode($response);
            exit();
        }
    }

// Prepare data for insertion
$courier_name = $courier_data['courier_name'];
$phone_number = $courier_data['phone_number'];
$email = !empty($courier_data['email']) ? $courier_data['email'] : null;
$address_line1 = $courier_data['address_line1'];
$address_line2 = !empty($courier_data['address_line2']) ? $courier_data['address_line2'] : null;
$city = $courier_data['city'];
$notes = !empty($courier_data['notes']) ? $courier_data['notes'] : null;
$date_joined = date('Y-m-d');
$status = 'active';
$is_default = 0; // Default to 0 (Not Default)

    // Empty API key and client_id if they are empty strings
    $api_key = !empty($api_key) ? $api_key : null;
    $client_id = !empty($client_id) ? $client_id : null;
    
    // Empty origin fields if not Royal Express
    if ($courier_id != 14) {
        $origin_city_name = null;
        $origin_state_name = null;
    }

    // Begin transaction
    $conn->begin_transaction();

    try {
        // Insert into couriers table
   // Insert into couriers table
$insert_sql = "INSERT INTO couriers (
                courier_id, 
                tenant_id, 
                courier_name, 
                phone_number, 
                email, 
                address_line1, 
                address_line2, 
                origin_city_name,
                origin_state_name,
                city, 
                notes,
                status, 
                is_default, 
                date_joined,
                api_key,
                client_id,
                has_api_new,
                has_api_existing,
                return_fee_value,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, NOW())";

$insert_stmt = $conn->prepare($insert_sql);

if (!$insert_stmt) {
    throw new Exception('Failed to prepare insert statement: ' . $conn->error);
}

$insert_stmt->bind_param(
    "iissssssssssissiii",
    $courier_id,
    $tenant_id,
    $courier_name,
    $phone_number,
    $email,
    $address_line1,
    $address_line2,
    $origin_city_name,
    $origin_state_name,
    $city,
    $notes,
    $status,
    $is_default,
    $date_joined,
    $api_key,
    $client_id,
    $has_api_new,
    $has_api_existing
);

        if (!$insert_stmt->execute()) {
            throw new Exception('Failed to insert courier account: ' . $insert_stmt->error);
        }

        $new_co_id = $conn->insert_id;

        // Commit transaction
        $conn->commit();

        $response['success'] = true;
        $response['message'] = 'Courier account added successfully!';
        $response['data'] = [
            'co_id' => $new_co_id,
            'courier_name' => $courier_name,
            'tenant_id' => $tenant_id
        ];

    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = 'An error occurred: ' . $e->getMessage();
    
    // Log error for debugging (optional)
    error_log('Courier Account Error: ' . $e->getMessage());
}

// Close database connection
if (isset($conn)) {
    $conn->close();
}

// Return JSON response
echo json_encode($response);
exit();
?>