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

if (empty($name)) {
    echo json_encode(['success' => false, 'message' => 'Category name is required.']);
    exit();
}

try {
    // Check for duplicates
    $check = $conn->prepare("SELECT id FROM categories WHERE name = ? LIMIT 1");
    $check->bind_param("s", $name);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Category name already exists.']);
        exit();
    }
    $check->close();

    // Insert (flat category, no parent)
    $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
    $stmt->bind_param("s", $name);
    
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