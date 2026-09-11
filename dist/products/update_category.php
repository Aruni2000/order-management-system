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

include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/connection/db_connection.php');

if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'message' => 'Security token mismatch.']);
    exit();
}

if ((int)($_SESSION['role_id'] ?? 0) == 2) {
    echo json_encode(['success' => false, 'message' => 'You do not have permission to edit categories.']);
    exit();
}

$category_id = intval($_POST['category_id'] ?? 0);
$name = trim(htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8'));

if ($category_id <= 0 || empty($name)) {
    echo json_encode(['success' => false, 'message' => 'Invalid data.']);
    exit();
}

try {
    // Tenant isolation: main admin can edit any tenant's category, others only their own
    $is_main_admin = (int)($_SESSION['is_main_admin'] ?? 0);
    $role_id = (int)($_SESSION['role_id'] ?? 0);
    $session_tenant_id = (int)($_SESSION['tenant_id'] ?? 0);

    // Check if category exists
    if ($is_main_admin === 1 && $role_id === 1) {
        $checkQuery = "SELECT name, tenant_id FROM categories WHERE id = ? LIMIT 1";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bind_param("i", $category_id);
    } else {
        $checkQuery = "SELECT name, tenant_id FROM categories WHERE id = ? AND tenant_id = ? LIMIT 1";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bind_param("ii", $category_id, $session_tenant_id);
    }
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Category not found.']);
        exit();
    }
    $originalData = $checkResult->fetch_assoc();
    $checkStmt->close();

    // Check for duplicates (same name but different ID, within the same tenant)
    $dup = $conn->prepare("SELECT id FROM categories WHERE name = ? AND tenant_id = ? AND id != ? LIMIT 1");
    $dup->bind_param("sii", $name, $originalData['tenant_id'], $category_id);
    $dup->execute();
    if ($dup->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Category name already exists.']);
        exit();
    }
    $dup->close();

    // Check for actual changes before updating
    if ($originalData['name'] === $name) {
        echo json_encode(['success' => true, 'message' => 'No changes were made to the category.']);
        exit();
    }

    // Update (keep tenant constraint for non-main admin as an extra safeguard)
    if ($is_main_admin === 1 && $role_id === 1) {
        $stmt = $conn->prepare("UPDATE categories SET name = ? WHERE id = ?");
        $stmt->bind_param("si", $name, $category_id);
    } else {
        $stmt = $conn->prepare("UPDATE categories SET name = ? WHERE id = ? AND tenant_id = ?");
        $stmt->bind_param("sii", $name, $category_id, $session_tenant_id);
    }
    
    if ($stmt->execute()) {
        // Log action
        if (isset($_SESSION['user_id'])) {
            $user_id = $_SESSION['user_id'];
            $details = "Updated Category '{$name}': Name: '{$originalData['name']}' to '$name'";
            $log = $conn->prepare("INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, 'category_update', ?, ?, NOW())");
            $log->bind_param("iis", $user_id, $category_id, $details);
            $log->execute();
            $log->close();
        }
        
        echo json_encode(['success' => true, 'message' => "Category updated successfully!"]);
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