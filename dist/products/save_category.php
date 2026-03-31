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

$name = trim(htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8'));
$parent_id = isset($_POST['parent_id']) ? intval($_POST['parent_id']) : 0;
$parent_id_val = ($parent_id > 0) ? $parent_id : null;

if (empty($name)) {
    echo json_encode(['success' => false, 'message' => 'Category name is required.']);
    exit();
}

try {
    // Check for duplicates
    $check = $conn->prepare("SELECT id FROM categories WHERE name = ? AND (parent_id = ? OR (parent_id IS NULL AND ? IS NULL)) LIMIT 1");
    $check->bind_param("sii", $name, $parent_id_val, $parent_id_val);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Category name already exists in this parent.']);
        exit();
    }
    $check->close();

    // Insert
    $stmt = $conn->prepare("INSERT INTO categories (name, parent_id) VALUES (?, ?)");
    $stmt->bind_param("si", $name, $parent_id_val);
    
    if ($stmt->execute()) {
        $category_id = $conn->insert_id;
        
        // Log action
        if (isset($_SESSION['user_id'])) {
            $user_id = $_SESSION['user_id'];
            $parent_text = ($parent_id > 0) ? "under parent ID $parent_id" : "as top-level";
            $details = "Created new category: $name $parent_text";
            $log = $conn->prepare("INSERT INTO user_logs (user_id, action_type, details) VALUES (?, 'category_create', ?)");
            $log->bind_param("is", $user_id, $details);
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