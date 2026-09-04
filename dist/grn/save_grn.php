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

$response = ['success' => false, 'message' => '', 'errors' => []];

function sanitizeInput($input) { return trim(htmlspecialchars($input, ENT_QUOTES, 'UTF-8')); }

try {
    $supplier_id = intval($_POST['supplier_id'] ?? 0);
    $received_date = sanitizeInput($_POST['received_date'] ?? '');
    $notes = sanitizeInput($_POST['notes'] ?? '');
    $auto_confirm = intval($_POST['auto_confirm'] ?? 0);
    $created_by = $_SESSION['user_id'] ?? null;
    $tenant_id = $_SESSION['tenant_id'] ?? null;

    // Validate header
    if ($supplier_id <= 0) {
        $response['errors']['supplier_id'] = 'Please select a supplier';
        $response['message'] = 'Please correct the errors below.';
        echo json_encode($response);
        exit();
    }
    if (empty($received_date)) {
        $response['errors']['received_date'] = 'Received date is required';
        $response['message'] = 'Please correct the errors below.';
        echo json_encode($response);
        exit();
    }

    // Validate supplier exists
    $supCheck = $conn->prepare("SELECT id FROM suppliers WHERE id = ? AND status = 'active'");
    $supCheck->bind_param("i", $supplier_id);
    $supCheck->execute();
    if ($supCheck->get_result()->num_rows === 0) {
        $response['errors']['supplier_id'] = 'Invalid or inactive supplier';
        $response['message'] = 'Please correct the errors below.';
        echo json_encode($response);
        exit();
    }
    $supCheck->close();

    // Collect and validate items
    $items = [];
    $total_amount = 0;

    // Helper function to generate unique batch number starting with product code
    function generateUniqueBatchNumber($conn, $product_code, $counter_offset = 0) {
        $product_code = !empty($product_code) ? strtoupper($product_code) : 'BATCH';
        $datePart = date('Ymd');
        $timePart = date('His');
        $randomPart = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 4));
        $batch_number = "{$product_code}-{$datePart}-{$timePart}-{$randomPart}";
        
        // Check for uniqueness and increment if needed
        $counter = $counter_offset;
        while (true) {
            $checkStmt = $conn->prepare("SELECT id FROM grn_items WHERE batch_number = ? LIMIT 1");
            $checkStmt->bind_param("s", $batch_number);
            $checkStmt->execute();
            $exists = $checkStmt->get_result()->num_rows > 0;
            $checkStmt->close();
            if (!$exists) {
                break;
            }
            $counter++;
            $randomPart = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 4));
            $batch_number = "{$product_code}-{$datePart}-{$timePart}-{$randomPart}-{$counter}";
        }
        return $batch_number;
    }

    // Fetch product codes for batch number generation
    $productCodes = [];
    if (isset($_POST['items']) && is_array($_POST['items'])) {
        $productIds = array_unique(array_map(fn($item) => intval($item['product_id'] ?? 0), $_POST['items']));
        $productIds = array_filter($productIds, fn($id) => $id > 0);
        if (!empty($productIds)) {
            $placeholders = str_repeat('?,', count($productIds) - 1) . '?';
            $codeStmt = $conn->prepare("SELECT id, product_code FROM products WHERE id IN ($placeholders)");
            $codeTypes = str_repeat('i', count($productIds));
            $codeStmt->bind_param($codeTypes, ...$productIds);
            $codeStmt->execute();
            $codeResult = $codeStmt->get_result();
            while ($row = $codeResult->fetch_assoc()) {
                $productCodes[$row['id']] = $row['product_code'];
            }
            $codeStmt->close();
        }
    }

    if (isset($_POST['items']) && is_array($_POST['items'])) {
        $batchCounter = 0;
        foreach ($_POST['items'] as $idx => $item) {
            $product_id = intval($item['product_id'] ?? 0);
            $quantity = intval($item['quantity'] ?? 0);
            $buying_price = floatval($item['buying_price'] ?? 0);
            $selling_price = floatval($item['selling_price'] ?? 0);
            $batch_number = sanitizeInput($item['batch_number'] ?? '');
            $product_code = $productCodes[$product_id] ?? 'BATCH';

            if ($product_id <= 0 || $quantity <= 0 || $buying_price <= 0 || $selling_price <= 0) {
                continue; // Skip incomplete items
            }

            // Generate unique batch number with product code if not provided
            if (empty($batch_number)) {
                $batch_number = generateUniqueBatchNumber($conn, $product_code, $batchCounter);
                $batchCounter++;
            } else {
                // Validate uniqueness of provided batch number
                $checkStmt = $conn->prepare("SELECT id FROM grn_items WHERE batch_number = ? LIMIT 1");
                $checkStmt->bind_param("s", $batch_number);
                $checkStmt->execute();
                if ($checkStmt->get_result()->num_rows > 0) {
                    $batch_number = generateUniqueBatchNumber($conn, $product_code, $batchCounter);
                    $batchCounter++;
                }
                $checkStmt->close();
            }

            $items[] = [
                'product_id' => $product_id,
                'batch_number' => $batch_number,
                'quantity' => $quantity,
                'buying_price' => $buying_price,
                'selling_price' => $selling_price
            ];
            $total_amount += ($quantity * $buying_price);
        }
    }

    if (empty($items)) {
        $response['message'] = 'Please add at least one valid stock item.';
        echo json_encode($response);
        exit();
    }

    // Start transaction first so GRN number generation is atomic
    $conn->begin_transaction();

    // Generate GRN number inside the transaction with a lock to prevent race conditions
    $today = date('Ymd');
    $grnPrefix = "GRN-{$today}-";
    $seqQuery = "SELECT grn_number FROM grn WHERE grn_number LIKE ? ORDER BY grn_number DESC LIMIT 1 FOR UPDATE";
    $seqStmt = $conn->prepare($seqQuery);
    $likePattern = $grnPrefix . '%';
    $seqStmt->bind_param("s", $likePattern);
    $seqStmt->execute();
    $seqResult = $seqStmt->get_result();
    $nextSeq = 1;
    if ($seqResult->num_rows > 0) {
        $lastGrn = $seqResult->fetch_assoc()['grn_number'];
        $lastNum = intval(substr($lastGrn, -3));
        $nextSeq = $lastNum + 1;
    }
    $seqStmt->close();
    $grn_number = $grnPrefix . str_pad($nextSeq, 3, '0', STR_PAD_LEFT);

    // Insert GRN header
    $status = $auto_confirm ? 'confirmed' : 'draft';
    $insertGrn = $conn->prepare("INSERT INTO grn (grn_number, supplier_id, received_date, total_amount, notes, status, created_by, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $insertGrn->bind_param("sisdssii", $grn_number, $supplier_id, $received_date, $total_amount, $notes, $status, $created_by, $tenant_id);

    if (!$insertGrn->execute()) {
        throw new Exception("Failed to create GRN: " . $insertGrn->error);
    }
    $grn_id = $conn->insert_id;
    $insertGrn->close();

    // Insert GRN items and their batch records
    $insertItem = $conn->prepare("INSERT INTO grn_items (grn_id, product_id, batch_number, quantity, buying_price, selling_price) VALUES (?, ?, ?, ?, ?, ?)");
    $insertBatch = $conn->prepare("INSERT INTO batches (tenant_id, grn_id, grn_item_id, supplier_id, product_id, batch_number, buying_price, selling_price, received_qty, remaining_qty, received_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $batchStatus = $auto_confirm ? 'confirmed' : 'draft';
    foreach ($items as $item) {
        $insertItem->bind_param("iisidd", $grn_id, $item['product_id'], $item['batch_number'], $item['quantity'], $item['buying_price'], $item['selling_price']);
        if (!$insertItem->execute()) {
            throw new Exception("Failed to add item: " . $insertItem->error);
        }
        $grn_item_id = $conn->insert_id;

        $insertBatch->bind_param(
            "iiiiisdiiiss",
            $tenant_id, $grn_id, $grn_item_id, $supplier_id, $item['product_id'],
            $item['batch_number'], $item['buying_price'], $item['selling_price'],
            $item['quantity'], $item['quantity'], $received_date, $batchStatus
        /* types: i i i i i s d d i i s s */
        );
        if (!$insertBatch->execute()) {
            throw new Exception("Failed to add batch record: " . $insertBatch->error);
        }
    }
    $insertItem->close();
    $insertBatch->close();

    // Auto-confirm: update product stock quantities (only if allow_inventory is enabled)
    if ($auto_confirm && isset($_SESSION['allow_inventory']) && $_SESSION['allow_inventory'] == 1) {
        $updateStock = $conn->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?");
        foreach ($items as $item) {
            $updateStock->bind_param("ii", $item['quantity'], $item['product_id']);
            if (!$updateStock->execute()) {
                throw new Exception("Failed to update stock for product ID " . $item['product_id']);
            }
        }
        $updateStock->close();
    }

    // Log action
    if ($created_by) {
        $logQuery = $conn->prepare("INSERT INTO user_logs (user_id, action_type, inquiry_id, details) VALUES (?, ?, ?, ?)");
        $action_type = 'grn_create';
        $itemCount = count($items);
        $details = "GRN created - Number: {$grn_number}, Supplier ID: {$supplier_id}, Items: {$itemCount}, Total: LKR " . number_format($total_amount, 2) . ($auto_confirm ? " [Auto-Confirmed]" : "");
        $logQuery->bind_param("isis", $created_by, $action_type, $grn_id, $details);
        $logQuery->execute();
        $logQuery->close();
    }

    $conn->commit();

    $response['success'] = true;
    $response['message'] = "GRN '{$grn_number}' created successfully!" . ($auto_confirm ? " Stock has been updated." : "");
    $response['grn_id'] = $grn_id;
    $response['grn_number'] = $grn_number;

} catch (Exception $e) {
    $conn->rollback();
    error_log("GRN creation error: " . $e->getMessage());
    $response['message'] = 'An error occurred while creating the GRN. Please try again.';
} finally {
    if (isset($conn)) $conn->close();
}

echo json_encode($response);
exit();
