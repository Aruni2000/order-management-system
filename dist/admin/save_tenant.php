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
    $delivery_fee = trim($_POST['delivery_fee'] ?? '0.00');
    $address = trim($_POST['address'] ?? '');
    $status = 'active';
    $is_main_admin = isset($_POST['is_main_admin']) ? (int)$_POST['is_main_admin'] : 0;

    // Validation
    $errors = [];

    // Validate Tenant Name
    if (empty($company_name)) {
        $errors['company_name'] = 'Tenant Name is required';
    } elseif (strlen($company_name) < 2) {
        $errors['company_name'] = 'Tenant Name must be at least 2 characters long';
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

    // Validate email (optional)
    if (!empty($email)) {
        if (strlen($email) > 100) {
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
    }

    // Validate address
    if (empty($address)) {
        $errors['address'] = 'Company address is required';
    }

    // Validate phone
    if (empty($phone)) {
        $errors['phone'] = 'Phone number is required';
    } else {
        $clean_phone = preg_replace('/\s+/', '', $phone);
        if (!preg_match("/^(0|94|\+94)?[1-9][0-9]{8}$/", $clean_phone)) {
            $errors['phone'] = 'Please enter a valid Sri Lankan phone number';
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

    // Validate delivery fee
    if (!empty($delivery_fee) && (!is_numeric($delivery_fee) || $delivery_fee < 0)) {
        $errors['delivery_fee'] = 'Please enter a valid delivery fee (non-negative number)';
    }

    // Validate logo file type and size
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
            $errors['logo'] = 'Invalid logo file type. Allowed: JPG, PNG, GIF';
        }
        if ($_FILES['logo']['size'] > 5 * 1024 * 1024) {
            $errors['logo'] = 'Logo file too large. Maximum size: 5MB';
        }
    }

    // Validate favicon file type and size
    if (isset($_FILES['fav_icon']) && $_FILES['fav_icon']['error'] == 0) {
        $ext = strtolower(pathinfo($_FILES['fav_icon']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'ico'])) {
            $errors['fav_icon'] = 'Invalid favicon file type. Allowed: ICO, PNG, JPG';
        }
        if ($_FILES['fav_icon']['size'] > 5 * 1024 * 1024) {
            $errors['fav_icon'] = 'Favicon file too large. Maximum size: 5MB';
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
    // TENANT CREATION LIMIT CHECK
    // Fetch the tenant_limit from the global branding table
    $tenantLimit = 0;
    $limitSql = "SELECT tenant_limit FROM branding WHERE active = 1 LIMIT 1";
    $limitResult = $conn->query($limitSql);
    if ($limitResult && $limitResult->num_rows > 0) {
        $limitRow = $limitResult->fetch_assoc();
        $tenantLimit = (int)$limitRow['tenant_limit'];
    }

    if ($tenantLimit > 0) {
        // Count ALL existing tenants (active + inactive) toward the limit
        $countSql = "SELECT COUNT(*) as total FROM tenants";
        $countResult = $conn->query($countSql);
        $currentCount = 0;
        if ($countResult && $countResult->num_rows > 0) {
            $countRow = $countResult->fetch_assoc();
            $currentCount = (int)$countRow['total'];
        }

        if ($currentCount >= $tenantLimit) {
            $response['message'] = 'Tenant creation limit reached. Maximum ' . $tenantLimit . ' tenant(s) allowed. Please contact your administrator to increase the limit.';
            $response['errors'] = ['tenant_limit' => 'Tenant limit of ' . $tenantLimit . ' reached. Cannot create more tenants.'];
            echo json_encode($response);
            exit();
        }
    }

    // Helper: sanitize Tenant Name for use in filenames
    $name_slug = strtolower(trim($company_name));
    $name_slug = preg_replace('/[^a-z0-9]+/', '_', $name_slug);
    $name_slug = trim($name_slug, '_');
    $name_slug = substr($name_slug, 0, 40);

    // Handle logo upload
    $logo_url = '';
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/uploads/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $new_name = $name_slug . '_logo_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_dir . $new_name)) {
                $logo_url = '/OMS/dist/uploads/' . $new_name;
            }
        }
    }

    // Handle favicon upload
    $fav_icon_url = '';
    if (isset($_FILES['fav_icon']) && $_FILES['fav_icon']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'ico'];
        $ext = strtolower(pathinfo($_FILES['fav_icon']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/uploads/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $new_name = $name_slug . '_favicon_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['fav_icon']['tmp_name'], $upload_dir . $new_name)) {
                $fav_icon_url = '/OMS/dist/uploads/' . $new_name;
            }
        }
    }

    // Begin transaction
    $conn->begin_transaction();

    // Prepare insert statement
    $insert_sql = "INSERT INTO tenants (company_name, contact_person, email, phone, address, delivery_fee, logo_url, fav_icon_url, status, is_main_admin, created_at, updated_at) 
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
    
    $stmt = $conn->prepare($insert_sql);
    
    if (!$stmt) {
        throw new Exception('Failed to prepare statement: ' . $conn->error);
    }

    // Bind parameters
    $stmt->bind_param("sssssdsssi", $company_name, $contact_person, $email, $phone, $address, $delivery_fee, $logo_url, $fav_icon_url, $status, $is_main_admin);

    // Execute statement
    if ($stmt->execute()) {
        $tenant_id = $conn->insert_id;
        
        // Log tenant creation in user_logs
        $logDetails = 'New tenant created - Company: ' . $company_name . ', Contact: ' . $contact_person . ', Email: ' . $email . ', Phone: ' . $phone;
        $logSql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, ?, ?, ?, NOW())";
        $logStmt = $conn->prepare($logSql);
        if ($logStmt) {
            $actionType = 'tenant_create';
            $logStmt->bind_param("isis", $user_id, $actionType, $tenant_id, $logDetails);
            $logStmt->execute();
            $logStmt->close();
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