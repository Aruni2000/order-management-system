<?php
// CRITICAL: Start output buffering FIRST
ob_start();

// Start session AFTER output buffering
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Disable error reporting for production
error_reporting(0);
ini_set('display_errors', 0);

// Include the database connection file
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Get user permissions and tenant info
$is_main_admin = isset($_SESSION['is_main_admin']) ? (int)$_SESSION['is_main_admin'] : 0;
$role_id = isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : 0;
$session_tenant_id = isset($_SESSION['tenant_id']) ? intval($_SESSION['tenant_id']) : 0;

// Function to log user actions
function logUserAction($conn, $user_id, $action_type, $inquiry_id, $details = null) {
    if (!$conn) return false;
    $stmt = $conn->prepare("INSERT INTO user_logs (user_id, action_type, inquiry_id, details) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isis", $user_id, $action_type, $inquiry_id, $details);
    return $stmt->execute();
}

// Function to parse numeric inputs safely
function parse_numeric($value, $default = 0.00) {
    if (is_array($value)) return $default;
    $clean_value = str_replace(',', '', (string)$value);
    return is_numeric($clean_value) ? floatval($clean_value) : $default;
}

// Function to set session message and redirect
function setMessageAndRedirect($type, $message, $redirect_url = null) {
    $_SESSION["order_{$type}"] = $message;
    if (!$redirect_url) {
        $redirect_url = "/OMS/dist/orders/pending_order_list.php";
    }
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: " . $redirect_url);
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        $order_id = $_POST['order_id'] ?? '';
        if (empty($order_id)) {
            throw new Exception("Order ID is missing.");
        }

        if (empty($_POST['customer_name'])) {
            throw new Exception("Customer name is required.");
        }

        if (empty($_POST['order_product'])) {
            throw new Exception("At least one product must be added to the order.");
        }

        // Begin transaction
        $conn->begin_transaction();

        // Fetch current pay_status, interface and tenant_id of the order before updating
        $currentPayStatusSql = "SELECT pay_status, interface, tenant_id FROM order_header WHERE order_id = ?";
        $currentPayStatusStmt = $conn->prepare($currentPayStatusSql);
        $currentPayStatusStmt->bind_param("s", $order_id);
        $currentPayStatusStmt->execute();
        $currentPayResult = $currentPayStatusStmt->get_result();
        $old_pay_status = 'unpaid';
        $order_interface = 'individual'; // default to individual
        $order_tenant_id = 0;
        if ($old_row = $currentPayResult->fetch_assoc()) {
            $old_pay_status = $old_row['pay_status'];
            $order_interface = $old_row['interface'] ?? 'individual';
            $order_tenant_id = intval($old_row['tenant_id'] ?? 0);
        }
        $currentPayStatusStmt->close();

        // Validate tenant access - non-main admins can only edit orders from their own tenant
        if (!($is_main_admin === 1 && $role_id === 1)) {
            if ($order_tenant_id !== $session_tenant_id) {
                throw new Exception("You do not have permission to edit this order.");
            }
        }

        $user_id = $_SESSION['user_id'] ?? 1;
        $customer_id = !empty($_POST['customer_id']) ? intval($_POST['customer_id']) : null;
        $customer_name = trim($_POST['customer_name']);
        $customer_email = trim($_POST['customer_email'] ?? '');
        $customer_phone = trim($_POST['customer_phone'] ?? '');
        $customer_phone_2 = trim($_POST['customer_phone_2'] ?? '');
        $address_line1 = trim($_POST['address_line1'] ?? '');
        $address_line2 = trim($_POST['address_line2'] ?? '');
        $city_id = !empty($_POST['city_id']) ? intval($_POST['city_id']) : null;

        // Get zone and district from city_table
        $zone_id = null;
        $district_id = null;
        if ($city_id) {
            $cityStmt = $conn->prepare("SELECT zone_id, district_id FROM city_table WHERE city_id = ?");
            $cityStmt->bind_param("i", $city_id);
            $cityStmt->execute();
            $cityResult = $cityStmt->get_result();
            if ($row = $cityResult->fetch_assoc()) {
                $zone_id = $row['zone_id'];
                $district_id = $row['district_id'];
            }
            $cityStmt->close();
        }

        $order_date = $_POST['order_date'] ?? date('Y-m-d');
        $due_date = $_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days'));
        $notes = $_POST['notes'] ?? "";
        $order_status = $_POST['order_status'] ?? 'Unpaid';
        $pay_status = $order_status === 'Paid' ? 'paid' : 'unpaid';
        $pay_date = $order_status === 'Paid' ? date('Y-m-d') : null;

        // Product processing
        $products = $_POST['order_product'];
        $product_prices = $_POST['order_product_price'];
        $quantities = $_POST['order_product_quantity'] ?? [];
        $discounts = $_POST['order_product_discount'] ?? [];
        $product_descriptions = $_POST['order_product_description'] ?? [];
        $item_ids = $_POST['order_item_id'] ?? []; // Get existing item IDs

        $subtotal_before_discounts = 0;
        $total_discount = 0;
        $product_codes = [];
        $order_items = [];
        $processed_item_ids = []; // Track which item IDs we've processed

        foreach ($products as $key => $pid) {
            if (empty($pid)) continue;
            
            $price = parse_numeric($product_prices[$key] ?? 0);
            $qty = intval($quantities[$key] ?? 1);
            $disc = parse_numeric($discounts[$key] ?? 0);
            $desc = $product_descriptions[$key] ?? '';
            $item_id = !empty($item_ids[$key]) ? intval($item_ids[$key]) : null;
            
            $line_total = $price * $qty;
            $subtotal_before_discounts += $line_total;
            $total_discount += $disc;
            $product_codes[] = $pid;
            
            $order_items[] = [
                'item_id' => $item_id,
                'product_id' => $pid,
                'price' => $price,
                'qty' => $qty,
                'discount' => $disc,
                'desc' => $desc,
                'total' => $line_total - $disc
            ];
            
            if ($item_id) {
                $processed_item_ids[] = $item_id;
            }
        }

        if (empty($order_items)) {
            throw new Exception("No valid products to process in the order.");
        }

        // Sort order items by product_id ASC
        usort($order_items, function($a, $b) {
            return strcmp((string)$a['product_id'], (string)$b['product_id']);
        });

        // Re-generate product_codes array from sorted items
        $product_codes = array_map(function($item) {
            return $item['product_id'];
        }, $order_items);

        $product_code_str = implode(',', $product_codes);
        $subtotal_after_discount = $subtotal_before_discounts - $total_discount;

        // Delivery Fee logic (same as create) - filtered by order's tenant
        $deliveryFeeSql = "SELECT delivery_fee FROM branding WHERE tenant_id = ? AND active = 1 LIMIT 1";
        $deliveryFeeStmt = $conn->prepare($deliveryFeeSql);
        $deliveryFeeStmt->bind_param("i", $order_tenant_id);
        $deliveryFeeStmt->execute();
        $deliveryFeeResult = $deliveryFeeStmt->get_result();
        $brandingFee = ($deliveryFeeResult && $row = $deliveryFeeResult->fetch_assoc()) ? floatval($row['delivery_fee']) : 0;
        $deliveryFeeStmt->close();
        
        $delivery_fee = $brandingFee;
        $total_amount = $subtotal_after_discount + $delivery_fee;

        // Update order_header (clear upload_error when editing)
        $updateSql = "UPDATE order_header SET 
                        customer_id = ?, issue_date = ?, due_date = ?, 
                        subtotal = ?, discount = ?, total_amount = ?, delivery_fee = ?, 
                        notes = ?, pay_status = ?, pay_date = ?, 
                        product_code = ?, full_name = ?, email = ?, mobile = ?, mobile_2 = ?, 
                        address_line1 = ?, address_line2 = ?, city_id = ?, zone_id = ?, district_id = ?,
                        upload_error = NULL
                      WHERE order_id = ? AND status = 'pending'";
        
        $stmt = $conn->prepare($updateSql);
        $stmt->bind_param("issddddssssssssssiiis", 
            $customer_id, $order_date, $due_date, 
            $subtotal_before_discounts, $total_discount, $total_amount, $delivery_fee,
            $notes, $pay_status, $pay_date,
            $product_code_str, $customer_name, $customer_email, $customer_phone, $customer_phone_2,
            $address_line1, $address_line2, $city_id, $zone_id, $district_id,
            $order_id
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to update order header: " . $stmt->error);
        }
        $stmt->close();

        // Fetch current order items to track what exists
        $currentItemsSql = "SELECT item_id, product_id, quantity FROM order_items WHERE order_id = ?";
        $currentItemsStmt = $conn->prepare($currentItemsSql);
        $currentItemsStmt->bind_param("s", $order_id);
        $currentItemsStmt->execute();
        $currentItemsResult = $currentItemsStmt->get_result();
        
        $current_items_map = [];
        while ($cItem = $currentItemsResult->fetch_assoc()) {
            $current_items_map[$cItem['item_id']] = [
                'product_id' => $cItem['product_id'],
                'quantity' => $cItem['quantity']
            ];
        }
        $currentItemsStmt->close();

        // Prepare statements for item operations
        $updateItemSql = "UPDATE order_items SET 
            product_id = ?, unit_price = ?, quantity = ?, discount = ?, 
            total_amount = ?, pay_status = ?, description = ?
            WHERE item_id = ? AND order_id = ?";
        $updateStmt = $conn->prepare($updateItemSql);

        $insertItemSql = "INSERT INTO order_items (
            order_id, product_id, unit_price, quantity, discount, 
            total_amount, pay_status, status, description
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?)";
        $insertStmt = $conn->prepare($insertItemSql);
        

        // Process each item
        foreach ($order_items as $item) {
            if ($item['item_id']) {
                // EXISTING ITEM - UPDATE IT
                $old_item = $current_items_map[$item['item_id']] ?? null;
                
                if ($old_item) {
                    // Update the item
                    $updateStmt->bind_param("ididdssss", 
                        $item['product_id'], $item['price'], $item['qty'], 
                        $item['discount'], $item['total'], $pay_status, $item['desc'],
                        $item['item_id'], $order_id
                    );
                    $updateStmt->execute();
                }
            } else {
                // NEW ITEM - INSERT IT
                $insertStmt->bind_param("iididsss",
                    $order_id, $item['product_id'], $item['price'], $item['qty'], 
                    $item['discount'], $item['total'], $pay_status, $item['desc']
                );
                $insertStmt->execute();
            }
        }
        
        // Delete items that were removed from the order
        foreach ($current_items_map as $item_id => $old_item) {
            if (!in_array($item_id, $processed_item_ids)) {
                $deleteStmt = $conn->prepare("DELETE FROM order_items WHERE item_id = ? AND order_id = ?");
                $deleteStmt->bind_param("is", $item_id, $order_id);
                $deleteStmt->execute();
                $deleteStmt->close();
            }
        }
        
        $updateStmt->close();
        $insertStmt->close();

        // Create detailed log message
        $logDetails = "Order details updated via edit interface";
        if ($old_pay_status !== $pay_status) {
            $logDetails .= " | Payment Status: " . ucfirst($old_pay_status) . " to " . ucfirst($pay_status);
        }

        // Log action with details
        logUserAction($conn, $user_id, "Updated order", $order_id, $logDetails);

        // Debug logging
        error_log("Payment Debug - Old status: " . $old_pay_status . ", New status: " . $pay_status);
        
        // Debug logging
        error_log("Payment Debug - Old status: " . $old_pay_status . ", New status: " . $pay_status);
        
        // If status changed from unpaid to paid, insert payment record
        if ($old_pay_status !== 'paid' && $pay_status === 'paid') {
            error_log("Payment Debug - Inserting payment for order: " . $order_id . ", amount: " . $total_amount);
            $insertPaymentSql = "INSERT INTO payments (order_id, amount_paid, payment_method, payment_date, pay_by) 
                                VALUES (?, ?, 'order_edit', CURRENT_TIMESTAMP, ?)";
            $insertPaymentStmt = $conn->prepare($insertPaymentSql);
            if (!$insertPaymentStmt) {
                error_log("Payment Debug - Prepare failed: " . $conn->error);
                throw new Exception("Failed to prepare payment statement: " . $conn->error);
            }
            $insertPaymentStmt->bind_param("sdi", $order_id, $total_amount, $user_id);
            if (!$insertPaymentStmt->execute()) {
                error_log("Payment Debug - Execute failed: " . $insertPaymentStmt->error);
                throw new Exception("Failed to insert payment record: " . $insertPaymentStmt->error);
            }
            error_log("Payment Debug - Payment inserted successfully");
            $insertPaymentStmt->close();
        } elseif ($old_pay_status === 'paid' && $pay_status !== 'paid') {
            // If status changed from paid to unpaid, delete payment record
            error_log("Payment Debug - Deleting payment for order: " . $order_id);
            $deletePaymentSql = "DELETE FROM payments WHERE order_id = ?";
            $deletePaymentStmt = $conn->prepare($deletePaymentSql);
            $deletePaymentStmt->bind_param("s", $order_id);
            if (!$deletePaymentStmt->execute()) {
                throw new Exception("Failed to delete payment record: " . $deletePaymentStmt->error);
            }
            $deletePaymentStmt->close();
        } else {
            error_log("Payment Debug - No payment action needed. Old: " . $old_pay_status . ", New: " . $pay_status);
        }

        $conn->commit();
        setMessageAndRedirect("success", "Order #$order_id updated successfully.");

    } catch (Exception $e) {
        if ($conn) $conn->rollback();
        setMessageAndRedirect("error", "Error: " . $e->getMessage(), "edit_order.php?id=" . ($_POST['order_id'] ?? ''));
    }
}
?>
