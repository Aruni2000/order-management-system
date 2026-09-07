<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'User ID not found in session']);
    exit();
}

// Check if user is main admin (Admin only access)
$is_main_admin = isset($_SESSION['is_main_admin']) ? (int)$_SESSION['is_main_admin'] : 0;
if ($is_main_admin != 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Only administrators can update selling prices.']);
    exit();
}

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['product_id']) || !isset($input['new_selling_price'])) {
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
        exit();
    }

    $product_id = (int)$input['product_id'];
    $new_selling_price = (float)$input['new_selling_price'];
    $batch_id = isset($input['batch_id']) ? (int)$input['batch_id'] : 0;
    $user_id = $_SESSION['user_id'];

    if ($product_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
        exit();
    }
    if ($new_selling_price <= 0) {
        echo json_encode(['success' => false, 'message' => 'Selling price must be greater than zero']);
        exit();
    }

    $session_tenant_id = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
    $is_main_admin = isset($_SESSION['is_main_admin']) ? (int)$_SESSION['is_main_admin'] : 0;

    if ($is_main_admin) {
        $checkSql = "SELECT id, name FROM products WHERE id = ?";
        $checkStmt = $conn->prepare($checkSql);
        if (!$checkStmt) {
            echo json_encode(['success' => false, 'message' => 'Database prepare error: ' . $conn->error]);
            exit();
        }
        $checkStmt->bind_param("i", $product_id);
    } else {
        $checkSql = "SELECT id, name FROM products WHERE id = ? AND tenant_id = ?";
        $checkStmt = $conn->prepare($checkSql);
        if (!$checkStmt) {
            echo json_encode(['success' => false, 'message' => 'Database prepare error: ' . $conn->error]);
            exit();
        }
        $checkStmt->bind_param("ii", $product_id, $session_tenant_id);
    }
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit();
    }
    $product = $result->fetch_assoc();
    $checkStmt->close();

    if ($is_main_admin) {
        if ($batch_id > 0) {
            $batchSql = "SELECT batch_id, batch_number, selling_price FROM batches WHERE batch_id = ? AND product_id = ? AND status = 'confirmed'";
            $batchStmt = $conn->prepare($batchSql);
            if (!$batchStmt) {
                echo json_encode(['success' => false, 'message' => 'Database prepare error: ' . $conn->error]);
                exit();
            }
            $batchStmt->bind_param("ii", $batch_id, $product_id);
        } else {
            $batchSql = "SELECT batch_id, batch_number, selling_price FROM batches WHERE product_id = ? AND status = 'confirmed'";
            $batchStmt = $conn->prepare($batchSql);
            if (!$batchStmt) {
                echo json_encode(['success' => false, 'message' => 'Database prepare error: ' . $conn->error]);
                exit();
            }
            $batchStmt->bind_param("i", $product_id);
        }
    } else {
        if ($batch_id > 0) {
            $batchSql = "SELECT batch_id, batch_number, selling_price FROM batches WHERE batch_id = ? AND product_id = ? AND tenant_id = ? AND status = 'confirmed'";
            $batchStmt = $conn->prepare($batchSql);
            if (!$batchStmt) {
                echo json_encode(['success' => false, 'message' => 'Database prepare error: ' . $conn->error]);
                exit();
            }
            $batchStmt->bind_param("iii", $batch_id, $product_id, $session_tenant_id);
        } else {
            $batchSql = "SELECT batch_id, batch_number, selling_price FROM batches WHERE product_id = ? AND tenant_id = ? AND status = 'confirmed'";
            $batchStmt = $conn->prepare($batchSql);
            if (!$batchStmt) {
                echo json_encode(['success' => false, 'message' => 'Database prepare error: ' . $conn->error]);
                exit();
            }
            $batchStmt->bind_param("ii", $product_id, $session_tenant_id);
        }
    }
    $batchStmt->execute();
    $batchResult = $batchStmt->get_result();

    if ($batchResult->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'No confirmed batches found for this product']);
        exit();
    }

    $oldPrices = [];
    while ($brow = $batchResult->fetch_assoc()) {
        $oldPrices[] = $brow['batch_number'] . ': Rs. ' . number_format($brow['selling_price'], 2);
    }
    $batchStmt->close();

    $new_price_str = number_format($new_selling_price, 2);

    $conn->autocommit(FALSE);

    try {
        if ($is_main_admin) {
            if ($batch_id > 0) {
                $updateSql = "UPDATE batches SET selling_price = ? WHERE batch_id = ? AND product_id = ? AND status = 'confirmed'";
                $updateStmt = $conn->prepare($updateSql);
                if (!$updateStmt) {
                    throw new Exception('Batch update prepare error: ' . $conn->error);
                }
                $updateStmt->bind_param("dii", $new_selling_price, $batch_id, $product_id);
            } else {
                $updateSql = "UPDATE batches SET selling_price = ? WHERE product_id = ? AND status = 'confirmed'";
                $updateStmt = $conn->prepare($updateSql);
                if (!$updateStmt) {
                    throw new Exception('Batch update prepare error: ' . $conn->error);
                }
                $updateStmt->bind_param("di", $new_selling_price, $product_id);
            }
        } else {
            if ($batch_id > 0) {
                $updateSql = "UPDATE batches SET selling_price = ? WHERE batch_id = ? AND product_id = ? AND tenant_id = ? AND status = 'confirmed'";
                $updateStmt = $conn->prepare($updateSql);
                if (!$updateStmt) {
                    throw new Exception('Batch update prepare error: ' . $conn->error);
                }
                $updateStmt->bind_param("diii", $new_selling_price, $batch_id, $product_id, $session_tenant_id);
            } else {
                $updateSql = "UPDATE batches SET selling_price = ? WHERE product_id = ? AND tenant_id = ? AND status = 'confirmed'";
                $updateStmt = $conn->prepare($updateSql);
                if (!$updateStmt) {
                    throw new Exception('Batch update prepare error: ' . $conn->error);
                }
                $updateStmt->bind_param("dii", $new_selling_price, $product_id, $session_tenant_id);
            }
        }
        if (!$updateStmt->execute()) {
            throw new Exception('Failed to update selling price: ' . $updateStmt->error);
        }
        $affectedRows = $updateStmt->affected_rows;
        $updateStmt->close();

        $action_type = 'product_selling_price_updated';
        $old_prices_str = implode(', ', $oldPrices);
        $batch_info = $batch_id > 0 ? "Batch #{$batch_id}" : "All batches";
        $details = "Updated Selling Price for Product '{$product['name']}' " .
            "({$batch_info} from [{$old_prices_str}] to Rs. {$new_price_str}), " .
            "Affected batches: {$affectedRows}";

        $logSql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, ?, ?, ?, NOW())";
        $logStmt = $conn->prepare($logSql);
        if (!$logStmt) {
            throw new Exception('Log prepare error: ' . $conn->error);
        }
        $logStmt->bind_param("isis", $user_id, $action_type, $product_id, $details);
        if (!$logStmt->execute()) {
            throw new Exception('Failed to insert user log: ' . $logStmt->error);
        }
        $logStmt->close();

        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Selling price updated successfully for ' . $affectedRows . ' batch(es)',
            'product_id' => $product_id,
            'new_selling_price' => $new_selling_price,
            'updated_batches' => $affectedRows
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Selling price update error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An unexpected error occurred'
    ]);
} finally {
    if (isset($conn)) {
        $conn->autocommit(TRUE);
        $conn->close();
    }
}
?>
