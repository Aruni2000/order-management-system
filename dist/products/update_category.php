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

$category_id = intval($_POST['category_id'] ?? 0);
$name = trim(htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8'));
$parent_id = isset($_POST['parent_id']) ? intval($_POST['parent_id']) : 0;
$parent_id_val = ($parent_id > 0) ? $parent_id : null;

if ($category_id <= 0 || empty($name)) {
    echo json_encode(['success' => false, 'message' => 'Invalid data.']);
    exit();
}

try {
    // Check if category exists
    $checkQuery = "SELECT name, parent_id FROM categories WHERE id = ? LIMIT 1";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bind_param("i", $category_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Category not found.']);
        exit();
    }
    $originalData = $checkResult->fetch_assoc();
    $checkStmt->close();

    // Check for duplicates (same name and parent but different ID)
    $dup = $conn->prepare("SELECT id FROM categories WHERE name = ? AND (parent_id = ? OR (parent_id IS NULL AND ? IS NULL)) AND id != ? LIMIT 1");
    $dup->bind_param("siii", $name, $parent_id_val, $parent_id_val, $category_id);
    $dup->execute();
    if ($dup->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Category name already exists in this parent.']);
        exit();
    }
    $dup->close();

    // Update
    $stmt = $conn->prepare("UPDATE categories SET name = ?, parent_id = ? WHERE id = ?");
    $stmt->bind_param("sii", $name, $parent_id_val, $category_id);
    
    if ($stmt->execute()) {
        // Log action
        if (isset($_SESSION['user_id'])) {
            $user_id = $_SESSION['user_id'];
            $changes = [];
            if ($originalData['name'] !== $name) $changes[] = "Name: '{$originalData['name']}' to '$name'";
            if ($originalData['parent_id'] != $parent_id_val) $changes[] = "Parent ID: " . ($originalData['parent_id'] ?? 'NULL') . " to " . ($parent_id_val ?? 'NULL');
            
            $details = "Updated category ID $category_id: " . implode(', ', $changes);
            $log = $conn->prepare("INSERT INTO user_logs (user_id, action_type, details) VALUES (?, 'category_update', ?)");
            $log->bind_param("is", $user_id, $details);
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