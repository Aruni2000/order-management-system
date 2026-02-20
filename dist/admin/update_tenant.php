<?php
/**
 * update_tenant.php
 * Secure & robust tenant update handler (AJAX)
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');
ob_start();
session_start();

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

/* ================================
   Helper: JSON Response
================================ */
function jsonResponse($success, $message, $errors = null, $data = null)
{
    if (ob_get_level()) {
        ob_end_clean();
    }

    $response = [
        'success' => $success,
        'message' => $message
    ];

    if ($errors !== null) $response['errors'] = $errors;
    if ($data !== null)   $response['data']   = $data;

    echo json_encode($response);
    exit;
}

/* ================================
   Helper: Activity Log (SAFE)
================================ */
function logTenantAction($conn, $userId, $tenantId, $action, $description)
{
    try {
        $sql = "INSERT INTO activity_logs (user_id, action, description, created_at)
                VALUES (?, ?, ?, NOW())";

        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("iss", $userId, $action, $description);
            $stmt->execute();
            $stmt->close();
        } else {
            error_log("Tenant log prepare failed: " . $conn->error);
        }
    } catch (Exception $e) {
        error_log("Tenant log exception: " . $e->getMessage());
    }
}

/* ================================
   Basic Security Checks
================================ */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

if (!isset($_SESSION['logged_in'], $_SESSION['user_id']) || $_SESSION['logged_in'] !== true) {
    jsonResponse(false, 'Authentication required.');
}

if (
    !isset($_POST['csrf_token'], $_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
) {
    jsonResponse(false, 'Security token validation failed.');
}

/* ================================
   Admin Role Check
================================ */
$userId = (int)$_SESSION['user_id'];

$roleStmt = $conn->prepare(
    "SELECT role_id FROM users WHERE id = ? AND status = 'active'"
);

if (!$roleStmt) {
    jsonResponse(false, 'Authorization check failed.');
}

$roleStmt->bind_param("i", $userId);
$roleStmt->execute();
$userRole = $roleStmt->get_result()->fetch_assoc();
$roleStmt->close();

if (!$userRole || (int)$userRole['role_id'] !== 1) {
    jsonResponse(false, 'Access denied. Admin privileges required.');
}

/* ================================
   Input Sanitization
================================ */
$tenantId       = (int)($_POST['tenant_id'] ?? 0);
$companyName    = trim($_POST['company_name'] ?? '');
$contactPerson  = trim($_POST['contact_person'] ?? '');
$email          = strtolower(trim($_POST['email'] ?? ''));
$phone          = trim($_POST['phone'] ?? '');
$status         = $_POST['status'] ?? 'active';
$isMainAdmin    = (int)($_POST['is_main_admin'] ?? 0);

/* ================================
   Validation
================================ */
$errors = [];

if ($tenantId <= 0) $errors['tenant_id'] = 'Invalid tenant ID.';
if (strlen($companyName) < 2) $errors['company_name'] = 'Company name is required.';
if (strlen($contactPerson) < 2) $errors['contact_person'] = 'Contact person is required.';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Invalid email address.';
}

$cleanPhone = preg_replace('/\s+/', '', $phone);
if (!preg_match('/^(0|94|\+94)?[1-9][0-9]{8}$/', $cleanPhone)) {
    $errors['phone'] = 'Invalid Sri Lankan phone number.';
}

if (!in_array($status, ['active', 'inactive'])) {
    $errors['status'] = 'Invalid status.';
}

if (!in_array($isMainAdmin, [0, 1])) {
    $errors['is_main_admin'] = 'Invalid value.';
}

if (!empty($errors)) {
    jsonResponse(false, 'Please correct the errors.', $errors);
}

/* ================================
   Fetch Existing Tenant
================================ */
$existingStmt = $conn->prepare(
    "SELECT company_name, contact_person, email, phone, status, is_main_admin
     FROM tenants WHERE tenant_id = ?"
);

$existingStmt->bind_param("i", $tenantId);
$existingStmt->execute();
$existingTenant = $existingStmt->get_result()->fetch_assoc();
$existingStmt->close();

if (!$existingTenant) {
    jsonResponse(false, 'Tenant not found.');
}

/* ================================
   Detect Actual Changes
================================ */
$newData = [
    'company_name'   => $companyName,
    'contact_person' => $contactPerson,
    'email'          => $email,
    'phone'          => $phone,
    'status'         => $status,
    'is_main_admin'  => $isMainAdmin
];

$hasChanges = false;
foreach ($newData as $field => $value) {
    if ($existingTenant[$field] != $value) {
        $hasChanges = true;
        break;
    }
}

if (!$hasChanges) {
    jsonResponse(false, 'No changes detected.');
}

/* ================================
   Duplicate Email Check
================================ */
if ($email !== $existingTenant['email']) {
    $emailStmt = $conn->prepare(
        "SELECT tenant_id FROM tenants WHERE email = ? AND tenant_id != ?"
    );
    $emailStmt->bind_param("si", $email, $tenantId);
    $emailStmt->execute();
    if ($emailStmt->get_result()->num_rows > 0) {
        jsonResponse(false, 'Email already exists.', ['email' => 'Email is already in use.']);
    }
    $emailStmt->close();
}

/* ================================
   Transaction: UPDATE TENANT
================================ */
$conn->begin_transaction();

try {
    $updateStmt = $conn->prepare(
        "UPDATE tenants SET
            company_name = ?,
            contact_person = ?,
            email = ?,
            phone = ?,
            status = ?,
            is_main_admin = ?,
            updated_at = NOW()
         WHERE tenant_id = ?"
    );

    if (!$updateStmt) {
        throw new Exception($conn->error);
    }

    $updateStmt->bind_param(
        "sssssii",
        $companyName,
        $contactPerson,
        $email,
        $phone,
        $status,
        $isMainAdmin,
        $tenantId
    );

    if (!$updateStmt->execute()) {
        throw new Exception($updateStmt->error);
    }

    $affectedRows = $updateStmt->affected_rows;
    $updateStmt->close();

    if ($affectedRows === 0) {
        $conn->rollback();
        jsonResponse(false, 'No changes were applied.');
    }

    $conn->commit();

    // Safe logging
    logTenantAction(
        $conn,
        $userId,
        $tenantId,
        'update_tenant',
        "Updated tenant '{$companyName}' (ID: {$tenantId})"
    );

    jsonResponse(true, 'Tenant updated successfully.', null, [
        'tenant_id' => $tenantId,
        'company_name' => $companyName,
        'status' => ucfirst($status)
    ]);

} catch (Exception $e) {
    $conn->rollback();
    error_log("Tenant Update Error: " . $e->getMessage());
    jsonResponse(false, 'Failed to update tenant. Please try again.');
}

/* ================================
   Cleanup
================================ */
finally {
    if (isset($conn) && $conn->ping()) {
        $conn->close();
    }
}
