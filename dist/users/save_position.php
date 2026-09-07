<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');
ob_start();

session_start();
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

function jsonResponse($success, $message, $errors = null, $data = null) {
    if (ob_get_level()) ob_end_clean();
    $response = ['success' => $success, 'message' => $message];
    if ($errors !== null) $response['errors'] = $errors;
    if ($data !== null) $response['data'] = $data;
    echo json_encode($response);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method');
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    jsonResponse(false, 'Authentication required');
}

$currentUserId = $_SESSION['user_id'] ?? null;
if (!$currentUserId) {
    jsonResponse(false, 'User session not found');
}

$contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';

// Handle JSON payload (for get and toggle_status actions)
if (strpos($contentType, 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    if ($action === 'get') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) jsonResponse(false, 'Invalid position ID');

        $stmt = $conn->prepare("SELECT * FROM positions WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $position = $result->fetch_assoc();
        $stmt->close();

        if (!$position) jsonResponse(false, 'Position not found');
        jsonResponse(true, 'OK', null, $position);
    }

    if ($action === 'toggle_status') {
        $id = (int)($input['id'] ?? 0);
        $newStatus = $input['status'] ?? '';

        if ($id <= 0) jsonResponse(false, 'Invalid position ID');
        if (!in_array($newStatus, ['active', 'inactive'])) jsonResponse(false, 'Invalid status value');

        $stmt = $conn->prepare("SELECT name FROM positions WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $position = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$position) jsonResponse(false, 'Position not found');

        $stmt = $conn->prepare("UPDATE positions SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("si", $newStatus, $id);
        if ($stmt->execute()) {
            $stmt->close();

            $actionType = $newStatus === 'active' ? 'position_activated' : 'position_deactivated';
            $logDetails = ($newStatus === 'active' ? 'Activated' : 'Deactivated') . " Position '{$position['name']}'";
            $logSql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, ?, ?, ?, NOW())";
            $logStmt = $conn->prepare($logSql);
            $logStmt->bind_param("isis", $currentUserId, $actionType, $id, $logDetails);
            $logStmt->execute();
            $logStmt->close();

            jsonResponse(true, "Position '{$position['name']}' has been " . ($newStatus === 'active' ? 'activated' : 'deactivated') . ".");
        } else {
            $stmt->close();
            jsonResponse(false, 'Failed to update position status.');
        }
    }

    jsonResponse(false, 'Unknown action');
}

// Handle form-data (for add and edit actions)
$action = $_POST['action'] ?? '';

if ($action === 'add') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    $errors = [];
    if (empty($name)) $errors['name'] = 'Position name is required.';
    if (strlen($name) > 100) $errors['name'] = 'Position name must be under 100 characters.';

    if (!empty($errors)) {
        jsonResponse(false, 'Please correct the errors.', $errors);
    }

    $stmt = $conn->prepare("SELECT id FROM positions WHERE name = ?");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        jsonResponse(false, 'A position with this name already exists.', ['name' => 'Position name already exists.']);
    }
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO positions (name, description, status, created_at) VALUES (?, ?, 'active', NOW())");
    $stmt->bind_param("ss", $name, $description);

    if ($stmt->execute()) {
        $newId = $conn->insert_id;
        $stmt->close();

        $logDetails = "Created position - Name: $name, ID: $newId";
        $logSql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, 'position_create', ?, ?, NOW())";
        $logStmt = $conn->prepare($logSql);
        $logStmt->bind_param("iis", $currentUserId, $newId, $logDetails);
        $logStmt->execute();
        $logStmt->close();

        jsonResponse(true, "Position '$name' has been created.", null, ['id' => $newId, 'name' => $name]);
    } else {
        $stmt->close();
        jsonResponse(false, 'Failed to create position. Please try again.');
    }
}

if ($action === 'edit') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($id <= 0) jsonResponse(false, 'Invalid position ID');

    $errors = [];
    if (empty($name)) $errors['name'] = 'Position name is required.';
    if (strlen($name) > 100) $errors['name'] = 'Position name must be under 100 characters.';

    if (!empty($errors)) {
        jsonResponse(false, 'Please correct the errors.', $errors);
    }

    // Fetch original data for change detection
    $origStmt = $conn->prepare("SELECT name, description FROM positions WHERE id = ?");
    $origStmt->bind_param("i", $id);
    $origStmt->execute();
    $origResult = $origStmt->get_result();
    $originalData = $origResult->fetch_assoc();
    $origStmt->close();
    
    if (!$originalData) {
        jsonResponse(false, 'Position not found.');
    }
    
    // Check if any actual changes were made
    if ($originalData['name'] === $name && $originalData['description'] === $description) {
        jsonResponse(true, 'No changes were made to the position.');
    }

    $stmt = $conn->prepare("SELECT id FROM positions WHERE name = ? AND id != ?");
    $stmt->bind_param("si", $name, $id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        jsonResponse(false, 'Another position with this name already exists.', ['name' => 'Position name already exists.']);
    }
    $stmt->close();

    $stmt = $conn->prepare("UPDATE positions SET name = ?, description = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("ssi", $name, $description, $id);

    if ($stmt->execute()) {
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected > 0) {
            $logDetails = "Position updated - Name: $name, ID: $id";
            $logSql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, 'position_update', ?, ?, NOW())";
            $logStmt = $conn->prepare($logSql);
            $logStmt->bind_param("iis", $currentUserId, $id, $logDetails);
            $logStmt->execute();
            $logStmt->close();
        }

        jsonResponse(true, "Position '$name' has been updated.");
    } else {
        $stmt->close();
        jsonResponse(false, 'Failed to update position. Please try again.');
    }
}

jsonResponse(false, 'Unknown action');
