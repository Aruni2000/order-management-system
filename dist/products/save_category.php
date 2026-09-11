<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'message' => 'Security token mismatch.']);
    exit();
}

if ((int)($_SESSION['role_id'] ?? 0) == 2) {
    echo json_encode(['success' => false, 'message' => 'You do not have permission to add categories.']);
    exit();
}

$name = trim(htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8'));

if (empty($name)) {
    echo json_encode(['success' => false, 'message' => 'Category name is required.']);
    exit();
}

// Determine target tenant: main admin picks a tenant, everyone else uses their own
$is_main_admin = (int)($_SESSION['is_main_admin'] ?? 0);
$role_id = (int)($_SESSION['role_id'] ?? 0);
if ($is_main_admin === 1 && $role_id === 1) {
    $tenant_id = intval($_POST['tenant_id'] ?? 0);
    if ($tenant_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Target Company is required.']);
        exit();
    }
} else {
    $tenant_id = (int)($_SESSION['tenant_id'] ?? 0);
}

try {
    // Check for duplicates within the same tenant
    $check = $conn->prepare("SELECT id FROM categories WHERE name = ? AND tenant_id = ? LIMIT 1");
    $check->bind_param("si", $name, $tenant_id);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Category name already exists.']);
        exit();
    }
    $check->close();

    // Insert (flat category, no parent)
    $stmt = $conn->prepare("INSERT INTO categories (name, tenant_id) VALUES (?, ?)");
    $stmt->bind_param("si", $name, $tenant_id);
    
    if ($stmt->execute()) {
        $category_id = $conn->insert_id;
        
        // Log action
        if (isset($_SESSION['user_id'])) {
            $user_id = $_SESSION['user_id'];
            $details = "Created Category '$name'";
            $log = $conn->prepare("INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, 'category_create', ?, ?, NOW())");
            $log->bind_param("iis", $user_id, $category_id, $details);
            $log->execute();
            $log->close();
        }
        
        echo json_encode(['success' => true, 'message' => "Category '$name' added successfully!", 'id' => $category_id]);
    } else {
        throw new Exception($stmt->error);
    }
    $stmt->close();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
} finally {
    $conn->close();
}
?>