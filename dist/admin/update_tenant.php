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

include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/connection/db_connection.php');

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
   Helper: User Log (SAFE)
================================ */
function logTenantAction($conn, $userId, $tenantId, $description = '')
{
    try {
        $actionType = 'tenant_update';
        $sql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at)
                VALUES (?, ?, ?, ?, NOW())";

        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("isis", $userId, $actionType, $tenantId, $description);
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
   Input Sanitization (first, so we have $tenantId for checks)
================================ */
$tenantId       = (int)($_POST['tenant_id'] ?? 0);
$companyName    = trim($_POST['company_name'] ?? '');
$contactPerson  = trim($_POST['contact_person'] ?? '');
$email          = strtolower(trim($_POST['email'] ?? ''));
$phone          = trim($_POST['phone'] ?? '');
$address        = trim($_POST['address'] ?? '');
$deliveryFee    = trim($_POST['delivery_fee'] ?? '0.00');
$removeLogo     = ($_POST['remove_logo'] ?? '0') === '1' ? 1 : 0;
$removeFavicon  = ($_POST['remove_favicon'] ?? '0') === '1' ? 1 : 0;
$isMainAdmin    = (int)($_POST['is_main_admin'] ?? 0);

/* ================================
   Role & Ownership Check
================================ */
$currentIsMainAdmin = isset($_SESSION['is_main_admin']) && $_SESSION['is_main_admin'] == 1;

// Company admin can ONLY update their own tenant
if (!$currentIsMainAdmin) {
    $sessionTenantId = (int)($_SESSION['tenant_id'] ?? 0);
    if ($tenantId !== $sessionTenantId || $sessionTenantId <= 0) {
        jsonResponse(false, 'Access denied. You can only edit your own company settings.');
    }
}

/* ================================
   Fetch Existing Tenant (before validation to override protected fields)
================================ */
$existingStmt = $conn->prepare(
    "SELECT company_name, contact_person, email, phone, address, delivery_fee, logo_url, fav_icon_url, status, is_main_admin
     FROM tenants
     WHERE tenant_id = ?"
);

$existingStmt->bind_param("i", $tenantId);
$existingStmt->execute();
$existingTenant = $existingStmt->get_result()->fetch_assoc();
$existingStmt->close();

if (!$existingTenant) {
    jsonResponse(false, 'Tenant not found.');
}

// Company admin cannot change is_main_admin — force existing value BEFORE validation
if (!$currentIsMainAdmin) {
    $isMainAdmin = (int)$existingTenant['is_main_admin'];
}

$sessionTenantId = (int)($_SESSION['tenant_id'] ?? 0);
if ($currentIsMainAdmin && $tenantId === $sessionTenantId && $isMainAdmin !== (int)$existingTenant['is_main_admin']) {
    jsonResponse(false, 'You cannot change the Main Tenant status of your own company. Another Main Tenant admin must do this.');
}

/* ================================
   Validation
================================ */
$errors = [];

if ($tenantId <= 0) $errors['tenant_id'] = 'Invalid tenant ID.';
    if (strlen($companyName) < 2) $errors['company_name'] = 'Tenant Name is required.';
if (strlen($contactPerson) < 2) $errors['contact_person'] = 'Contact person is required.';

if (empty($address)) $errors['address'] = 'Company address is required.';

if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Invalid email address.';
}

$cleanPhone = preg_replace('/\s+/', '', $phone);
if (!preg_match('/^(0|94|\+94)?[1-9][0-9]{8}$/', $cleanPhone)) {
    $errors['phone'] = 'Invalid Sri Lankan phone number.';
}

if (!in_array($isMainAdmin, [0, 1])) {
    $errors['is_main_admin'] = 'Invalid value.';
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

if (!empty($errors)) {
    jsonResponse(false, 'Please correct the errors.', $errors);
}

/* ================================
   Detect Actual Changes & Build Log Details
================================ */
$tenantChanged = false;
$changes = [];
$fieldLabels = [
    'company_name'   => 'Tenant Name',
    'contact_person' => 'Contact Person',
    'email'          => 'Email',
    'phone'          => 'Phone',
    'address'        => 'Address',
    'delivery_fee'   => 'Delivery Fee',
    'is_main_admin'  => 'Main Admin'
];

$tenantFields = [
    'company_name'   => $companyName,
    'contact_person' => $contactPerson,
    'email'          => $email,
    'phone'          => $phone,
    'address'        => $address,
    'delivery_fee'   => $deliveryFee,
    'is_main_admin'  => $isMainAdmin
];

foreach ($tenantFields as $field => $newValue) {
    $oldValue = $existingTenant[$field] ?? '';
    if ($oldValue != $newValue) {
        $tenantChanged = true;
        $label = $fieldLabels[$field] ?? ucfirst(str_replace('_', ' ', $field));

        // Format special field values for readability
        switch ($field) {
            case 'is_main_admin':
                $oldDisplay = $oldValue ? 'Yes' : 'No';
                $newDisplay = $newValue ? 'Yes' : 'No';
                break;
            case 'delivery_fee':
                $oldDisplay = number_format((float)$oldValue, 2);
                $newDisplay = number_format((float)$newValue, 2);
                break;
            default:
                $oldDisplay = !empty($oldValue) || $oldValue === '0' ? $oldValue : '(empty)';
                $newDisplay = !empty($newValue) || $newValue === '0' ? $newValue : '(empty)';
                break;
        }

        $changes[] = "{$label}: Changed from '{$oldDisplay}' to '{$newDisplay}'";
    }
}

/* ================================
   Process Logo/Favicon Uploads
================================ */
$logoUrl = $existingTenant['logo_url'] ?? '';
$faviconUrl = $existingTenant['fav_icon_url'] ?? '';

// Helper: sanitize Tenant Name for use in filenames
$nameSlug = strtolower(trim($companyName));
$nameSlug = preg_replace('/[^a-z0-9]+/', '_', $nameSlug);
$nameSlug = trim($nameSlug, '_');
$nameSlug = substr($nameSlug, 0, 40);

// Helper: delete old uploaded file from disk given its URL path
$deleteOldFile = function ($urlPath) {
    if (empty($urlPath)) return;
    $filePath = $_SERVER['DOCUMENT_ROOT'] . $urlPath;
    if (file_exists($filePath)) {
        @unlink($filePath);
    }
};

// Handle logo upload
if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
    $allowed = ['jpg', 'jpeg', 'png', 'gif'];
    $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
    if (in_array($ext, $allowed)) {
        $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $newName = $nameSlug . '_logo_' . time() . '.' . $ext;
        if (move_uploaded_file($_FILES['logo']['tmp_name'], $uploadDir . $newName)) {
            // New file saved — now safe to delete the old one
            $deleteOldFile($existingTenant['logo_url'] ?? '');
            $logoUrl = '/orderhub_nextwave/dist/uploads/' . $newName;
        }
    }
} elseif ($removeLogo) {
    $deleteOldFile($existingTenant['logo_url'] ?? '');
    $logoUrl = '';
}

// Handle favicon upload
if (isset($_FILES['fav_icon']) && $_FILES['fav_icon']['error'] == 0) {
    $allowed = ['jpg', 'jpeg', 'png', 'ico'];
    $ext = strtolower(pathinfo($_FILES['fav_icon']['name'], PATHINFO_EXTENSION));
    if (in_array($ext, $allowed)) {
        $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $newName = $nameSlug . '_favicon_' . time() . '.' . $ext;
        if (move_uploaded_file($_FILES['fav_icon']['tmp_name'], $uploadDir . $newName)) {
            // New file saved — now safe to delete the old one
            $deleteOldFile($existingTenant['fav_icon_url'] ?? '');
            $faviconUrl = '/orderhub_nextwave/dist/uploads/' . $newName;
        }
    }
} elseif ($removeFavicon) {
    $deleteOldFile($existingTenant['fav_icon_url'] ?? '');
    $faviconUrl = '';
}

// Check if logo/favicon changed
if ($logoUrl !== ($existingTenant['logo_url'] ?? '')) {
    $tenantChanged = true;
    if (empty($logoUrl)) {
        $changes[] = 'Company Logo: Removed';
    } else {
        $changes[] = 'Company Logo: Uploaded new logo';
    }
}
if ($faviconUrl !== ($existingTenant['fav_icon_url'] ?? '')) {
    $tenantChanged = true;
    if (empty($faviconUrl)) {
        $changes[] = 'Favicon: Removed';
    } else {
        $changes[] = 'Favicon: Uploaded new favicon';
    }
}

if (!$tenantChanged) {
    jsonResponse(true, 'No changes were made to the tenant.');
}

/* ================================
   Duplicate Email Check
================================ */
if (!empty($email) && $email !== $existingTenant['email']) {
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
    if ($tenantChanged) {
        $updateStmt = $conn->prepare(
            "UPDATE tenants SET
                company_name = ?,
                contact_person = ?,
                email = ?,
                phone = ?,
                address = ?,
                delivery_fee = ?,
                logo_url = ?,
                fav_icon_url = ?,
                is_main_admin = ?,
                updated_at = NOW()
             WHERE tenant_id = ?"
        );

        if (!$updateStmt) {
            throw new Exception($conn->error);
        }

        $updateStmt->bind_param(
            "sssssdssii",
            $companyName,
            $contactPerson,
            $email,
            $phone,
            $address,
            $deliveryFee,
            $logoUrl,
            $faviconUrl,
            $isMainAdmin,
            $tenantId
        );

        if (!$updateStmt->execute()) {
            throw new Exception($updateStmt->error);
        }

        $updateStmt->close();
    }

    $conn->commit();

    // Safe logging to user_logs with detailed changes
    $logDetails = !empty($changes) ? implode(' | ', $changes) : "Updated tenant '{$companyName}' (ID: {$tenantId})";
    logTenantAction(
        $conn,
        $userId,
        $tenantId,
        $logDetails
    );

    jsonResponse(true, 'Tenant updated successfully.', null, [
        'tenant_id' => $tenantId,
        'company_name' => $companyName
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
