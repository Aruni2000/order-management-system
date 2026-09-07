<?php
// File: templates/lead_upload.php
// Start output buffering to prevent header issues
ob_start();

// Start session at the very beginning
session_start();

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    ob_end_clean();
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

// Include the database connection file early
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Initialize transaction flag
$transactionStarted = false;

// Function to log user actions
function logUserAction($conn, $user_id, $action_type, $inquiry_id, $details) {
    $logSql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, ?, ?, ?, NOW())";
    $logStmt = $conn->prepare($logSql);
    if ($logStmt) {
        $logStmt->bind_param("isis", $user_id, $action_type, $inquiry_id, $details);
        $logStmt->execute();
        $logStmt->close();
    }
}
// NEW: Handle CSV export of failed rows
if (isset($_GET['download_errors']) && isset($_SESSION['failed_rows_data'])) {
    $failedData = $_SESSION['failed_rows_data'];
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="failed_leads_' . date('Y-m-d_His') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Open output stream
    $output = fopen('php://output', 'w');
    
    // Add BOM for Excel UTF-8 compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Write header row with error column
    $headerRow = array_merge($failedData['headers'], ['Error Reason']);
    fputcsv($output, $headerRow);
    
    // Write failed rows with their error messages
    foreach ($failedData['rows'] as $rowData) {
        $rowWithError = array_merge($rowData['data'], [$rowData['error']]);
        fputcsv($output, $rowWithError);
    }
    
    fclose($output);
    
    // Clear the session data after download
    unset($_SESSION['failed_rows_data']);
    exit();
}
// Get logged-in user info
$loggedInUserId = $_SESSION['user_id'];
$isMainAdmin = $_SESSION['is_main_admin'];
$role_id = $_SESSION['role_id'];
$loggedInTenantId = $_SESSION['tenant_id'];

// Handle tenant selection for main admin
$selectedTenantId = null;
if ($isMainAdmin == 1 && $role_id === 1) {
    if (isset($_POST['selected_tenant']) && !isset($_POST['users'])) {
        $selectedTenantId = (int)$_POST['selected_tenant'];
        $_SESSION['upload_selected_tenant'] = $selectedTenantId;
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } elseif (isset($_SESSION['upload_selected_tenant'])) {
        $selectedTenantId = $_SESSION['upload_selected_tenant'];
    }
} else {
    $selectedTenantId = $loggedInTenantId;
}

// Process CSV upload if form is submitted
if ($_POST && isset($_FILES['csv_file']) && isset($_POST['users'])) {
    try {
        // Validate file upload
        if ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("File upload failed with error code: " . $_FILES['csv_file']['error']);
        }
        
        // Validate file type
        $fileInfo = pathinfo($_FILES['csv_file']['name']);
        if (strtolower($fileInfo['extension']) !== 'csv') {
            throw new Exception("Only CSV files are allowed.");
        }
        
        // Validate file size (10MB limit)
        if ($_FILES['csv_file']['size'] > 10 * 1024 * 1024) {
            throw new Exception("File size must be less than 10MB.");
        }
        
        // Get selected users
        $selectedUsers = $_POST['users'];
        if (empty($selectedUsers)) {
            throw new Exception("Please select at least one user.");
        }
        
        // Validate Product Selection
        if (empty($_POST['product_id'])) {
            throw new Exception("Please select a product related to this upload.");
        }
        $selectedProductId = (int)$_POST['product_id'];
        
        // Get tenant_id for upload
        $tenant_id = $selectedTenantId;
        
        if (!$tenant_id) {
            throw new Exception("Unable to determine tenant.");
        }
        
        error_log("DEBUG - Lead Upload - Using tenant_id: $tenant_id, user_id: $loggedInUserId");

        // Validate selected users belong to the selected tenant
        $userPlaceholders = str_repeat('?,', count($selectedUsers) - 1) . '?';
        $userValidationSql = "SELECT id, tenant_id FROM users WHERE id IN ($userPlaceholders) AND status = 'active' AND tenant_id = ?";
        $userValidationStmt = $conn->prepare($userValidationSql);
        if (!$userValidationStmt) {
            throw new Exception("Failed to prepare user validation query: " . $conn->error);
        }
        
        $bindTypes = str_repeat('i', count($selectedUsers)) . 'i';
        $bindParams = array_merge($selectedUsers, [$tenant_id]);
        $userValidationStmt->bind_param($bindTypes, ...$bindParams);
        $userValidationStmt->execute();
        $validUsersResult = $userValidationStmt->get_result();
        
        if ($validUsersResult->num_rows !== count($selectedUsers)) {
            throw new Exception("One or more selected users are invalid, inactive, or do not belong to the selected tenant.");
        }
        $userValidationStmt->close();

        // Fetch delivery fee from tenants table
        $deliveryFee = 0.00;
        $tenantFeeSql = "SELECT delivery_fee FROM tenants WHERE tenant_id = ? AND status = 'active' LIMIT 1";
        $tenantFeeStmt = $conn->prepare($tenantFeeSql);
        if (!$tenantFeeStmt) {
            throw new Exception("Failed to prepare tenant delivery fee query: " . $conn->error);
        }
        $tenantFeeStmt->bind_param("i", $tenant_id);
        $tenantFeeStmt->execute();
        $tenantFeeResult = $tenantFeeStmt->get_result();

        if ($tenantFeeResult->num_rows > 0) {
            $tenantFeeData = $tenantFeeResult->fetch_assoc();
            $deliveryFee = (float)$tenantFeeData['delivery_fee'];
            error_log("DEBUG - Delivery fee fetched from tenants for tenant_id $tenant_id: $deliveryFee");
        } else {
            error_log("WARNING - No active tenant found for tenant_id: $tenant_id, using default delivery_fee: 0.00");
        }
        $tenantFeeStmt->close();
        
        // Process CSV file
        $csvFile = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($csvFile, 'r');
        
        if (!$handle) {
            throw new Exception("Could not open CSV file.");
        }
        
        // Skip BOM if present
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
        
        // Read header row
        $headers = fgetcsv($handle);
        if (!$headers) {
            throw new Exception("CSV file is empty or invalid.");
        }
        
        // Normalize headers (trim whitespace and convert to lowercase)
        $normalizedHeaders = array_map(function($h) {
            return strtolower(trim($h));
        }, $headers);
        
        // Expected headers (lowercase)
        $expectedHeaders = [
            'full name', 
            'phone number', 
            'phone number 2', 
            'city', 
            'email', 
            'address line 1', 
            'address line 2', 
            'quantity',
            'other'
        ];
        
        // Create mapping of header name to column index
        $headerMap = array_flip($normalizedHeaders);
        
        // Check if all required headers exist
        $missingHeaders = [];
        foreach ($expectedHeaders as $expected) {
            if (!isset($headerMap[$expected])) {
                $missingHeaders[] = ucwords($expected);
            }
        }
        
        if (!empty($missingHeaders)) {
            throw new Exception(
                "Missing required CSV headers: " . implode(', ', $missingHeaders) . "\n\n" .
                "Found headers: " . implode(', ', $headers) . "\n\n" .
                "Please download a fresh template and ensure all headers are present."
            );
        }
        
        // Initialize counters
        $successCount = 0;
        $errorCount = 0;
        $errorMessages = [];
        $failedRowsData = [];
        $infoMessages = [];
        $rowNumber = 1;
        $successfulOrderIds = [];
        
        // Begin transaction
        $conn->begin_transaction();
        $transactionStarted = true;
        
        // Initialize round-robin counter
        $userIndex = 0;

        // Fetch product data once for all rows (filtered by tenant)
        $productSql = "SELECT id, product_code, description FROM products WHERE id = ? AND status = 'active' AND tenant_id = ?";
        $productStmt = $conn->prepare($productSql);
        if (!$productStmt) {
            throw new Exception("Failed to prepare product query: " . $conn->error);
        }
        $productStmt->bind_param("ii", $selectedProductId, $tenant_id);
        $productStmt->execute();
        $productResult = $productStmt->get_result();
        
        if ($productResult->num_rows === 0) {
            throw new Exception("Selected product not found or inactive");
        }
        
        $product = $productResult->fetch_assoc();
        $productId = $product['id'];
        $unitPrice = 0.00;
        $productCode = $product['product_code'];
        $productDescription = $product['description'] ?? '';
        $productStmt->close();

        // Read and validate selected selling price when inventory is enabled
        $selectedSellingPrice = 0.00;
        if (isset($_SESSION['allow_inventory']) && $_SESSION['allow_inventory'] == 1) {
            if (empty($_POST['selling_price'])) {
                throw new Exception("Please select a selling price for this upload.");
            }
            $selectedSellingPrice = (float)$_POST['selling_price'];
            if ($selectedSellingPrice <= 0) {
                throw new Exception("The selected selling price is invalid.");
            }

            // Verify a confirmed batch exists for this product at the selected price
            $priceCheckSql = "SELECT batch_id FROM batches
                              WHERE product_id = ? AND status = 'confirmed' AND remaining_qty > 0 AND selling_price = ?
                              LIMIT 1";
            $priceCheckStmt = $conn->prepare($priceCheckSql);
            if (!$priceCheckStmt) {
                throw new Exception("Failed to prepare price validation query: " . $conn->error);
            }
            $priceCheckStmt->bind_param("id", $productId, $selectedSellingPrice);
            $priceCheckStmt->execute();
            $priceCheckResult = $priceCheckStmt->get_result();
            if ($priceCheckResult->num_rows === 0) {
                $priceCheckStmt->close();
                throw new Exception("No confirmed batch exists for this product at the selected selling price.");
            }
            $priceCheckStmt->close();

            $unitPrice = $selectedSellingPrice;
        }
        
        // Process each row
        while (($row = fgetcsv($handle)) !== FALSE) {
            $rowNumber++;
            
            $conn->query("SAVEPOINT row_sp");
            try {
                // Skip empty rows
                if (empty(array_filter($row))) {
                    continue;
                }

                // Map CSV columns to variables
                $fullName = $row[$headerMap['full name']] ?? '';
                $phoneNumber = $row[$headerMap['phone number']] ?? '';
                $phoneNumber2 = $row[$headerMap['phone number 2']] ?? '';
                $city = $row[$headerMap['city']] ?? '';
                $email = $row[$headerMap['email']] ?? '';
                $addressLine1 = $row[$headerMap['address line 1']] ?? '';
                $addressLine2 = $row[$headerMap['address line 2']] ?? '';
                $quantityStr = $row[$headerMap['quantity']] ?? '';
                $other = $row[$headerMap['other']] ?? '';

                // Sanitize and validate inputs
                $fullName = trim($fullName);
                $phoneNumber = trim($phoneNumber);
                $phoneNumber2 = trim($phoneNumber2);
                $city = trim($city);
                $email = trim($email);
                $addressLine1 = trim($addressLine1);
                $addressLine2 = trim($addressLine2);
                $quantityStr = trim($quantityStr);
                $other = trim($other);

                // Default quantity to 1 if empty or invalid
                $quantityInt = (!empty($quantityStr) && is_numeric($quantityStr)) ? (int)$quantityStr : 1;
                if ($quantityInt <= 0) $quantityInt = 1;

                // Basic validation for required fields
                if (empty($fullName)) {
                    throw new Exception("Full Name is required.");
                }
                if (empty($phoneNumber)) {
                    throw new Exception("Phone Number is required.");
                }
                
                // Clean phone number (remove non-digits, check length)
                $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
                
                // Handle different phone number formats
                if (strlen($phoneNumber) > 10) {
                     // If it's more than 10 digits, it might have a country code (e.g. 94 or +94)
                     // Take the last 9 digits and add '0' prefix
                     $phoneNumber = substr($phoneNumber, -9);
                     $phoneNumber = '0' . $phoneNumber;
                } elseif (strlen($phoneNumber) === 9) {
                     // If exactly 9 digits, add '0' prefix (e.g., 771234567 -> 0771234567)
                     $phoneNumber = '0' . $phoneNumber;
                }
                
                if (strlen($phoneNumber) !== 10) {
                    throw new Exception("Phone Number must be 9 or 10 digits (0 will be added to 9-digit numbers).");
                }
                
                // Get city_id from city name 
                $cityError = null;  
                $cityId = null;
                $zone_id = null;
                $district_id = null;

                if (empty($city)) {
                    $cityError = "City is missing.";
                } else {
                    $citySql = "SELECT city_id, zone_id, district_id FROM city_table WHERE LOWER(city_name) = LOWER(?) AND is_active = 1 LIMIT 1";
                    $cityStmt = $conn->prepare($citySql);
                    if (!$cityStmt) {
                        throw new Exception("Failed to prepare city query: " . $conn->error);
                    }
                    $cityStmt->bind_param("s", $city);
                    $cityStmt->execute();
                    $cityResult = $cityStmt->get_result();

                    if ($cityResult->num_rows === 0) {
                        // City not found - store error but continue processing
                        $cityError = "City '$city' not found.";
                    } else {
                        $cityData = $cityResult->fetch_assoc();
                        $cityId = $cityData['city_id'];
                        $zone_id = $cityData['zone_id'];
                        $district_id = $cityData['district_id'];
                    }
                    $cityStmt->close();
                }

                // Validate address line 1 
                $addressError = null;
                if (empty($addressLine1)) {
                    $addressError = "Address Line 1 is missing.";
                }

                // Combine errors for upload_error
                $upload_error = null;
                if ($cityError || $addressError) {
                    $errors = array_filter([$cityError, $addressError]);
                    $upload_error = implode(" | ", $errors);
                }
                
                // Customer matching logic
                $customerId = 0;
                $is_new_customer = true;

                // Check if phone exists
                $checkPhoneSql = "SELECT customer_id FROM customers 
                                WHERE (phone = ? OR phone_2 = ?) 
                                AND tenant_id = ?
                                AND status = 'Active'
                                LIMIT 1";

                $phoneStmt = $conn->prepare($checkPhoneSql);
                if (!$phoneStmt) {
                    throw new Exception("Failed to prepare phone check query: " . $conn->error);
                }

                $phoneStmt->bind_param("ssi", $phoneNumber, $phoneNumber, $tenant_id);
                $phoneStmt->execute();
                $phoneResult = $phoneStmt->get_result();

                if ($phoneResult->num_rows > 0) {
                    $customerId = $phoneResult->fetch_assoc()['customer_id'];
                    $is_new_customer = false;
                }
                $phoneStmt->close();

                // Create new customer if needed
                if ($is_new_customer) {
                    $insertCustomerSql = "INSERT INTO customers 
                        (tenant_id, name, email, phone, phone_2, address_line1, address_line2, city_id, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active')";
                    
                    $customerStmt = $conn->prepare($insertCustomerSql);
                    if (!$customerStmt) {
                        throw new Exception("Failed to prepare customer insert query: " . $conn->error);
                    }
                    
                    $email_value = !empty($email) ? $email : null;
                    $phone2_value = !empty($phoneNumber2) ? $phoneNumber2 : null;
                    $address1_value = !empty($addressLine1) ? $addressLine1 : null;
                    $address2_value = !empty($addressLine2) ? $addressLine2 : null;
                    
                    $customerStmt->bind_param(
                        "issssssi",
                        $tenant_id,
                        $fullName,
                        $email_value,
                        $phoneNumber,
                        $phone2_value,
                        $address1_value,
                        $address2_value,
                        $cityId
                    );
                    
                    if (!$customerStmt->execute()) {
                        throw new Exception("Failed to create customer: " . $customerStmt->error);
                    }
                    
                    $customerId = $conn->insert_id;
                    $customerStmt->close();
                }

                if (empty($customerId) || $customerId <= 0) {
                    throw new Exception("Invalid customer ID");
                }
                
                // Round-robin assign to one of the selected users
                $assignedUserId = $selectedUsers[$userIndex % count($selectedUsers)];
                $userIndex++;
                
                // Calculate subtotal and total amount with delivery fee
                $subtotal = $unitPrice * $quantityInt;
                $orderTotalAmount = $subtotal + $deliveryFee;

                // Create order header
                $orderSql = "INSERT INTO order_header (
                    tenant_id, customer_id, user_id, issue_date, due_date, 
                    subtotal, discount, total_amount, delivery_fee,
                    notes, currency, status, pay_status, pay_date, created_by,
                    product_code, full_name, mobile, mobile_2,
                    address_line1, address_line2, city_id, zone_id, district_id,
                    interface, call_log, upload_error
                ) VALUES (?, ?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), 
                        ?, 0.00, ?, ?, ?, 'lkr', 'pending', 'unpaid', NULL, ?, 
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, 'leads', 0, ?)";
                
                $orderStmt = $conn->prepare($orderSql);
                if (!$orderStmt) {
                    throw new Exception("Failed to prepare order query: " . $conn->error);
                }
                
                $notes = !empty($other) ? $other : 'Imported from CSV';
                $phone2_value = !empty($phoneNumber2) ? $phoneNumber2 : null;
                $address2_value = !empty($addressLine2) ? $addressLine2 : null;
                $zone_id_value = !empty($zone_id) ? $zone_id : null;
                $district_id_value = !empty($district_id) ? $district_id : null;
                
                $orderStmt->bind_param(
                    "iiidddsiisssssiiis",
                    $tenant_id,
                    $customerId,
                    $assignedUserId,
                    $subtotal,
                    $orderTotalAmount,
                    $deliveryFee,
                    $notes,
                    $loggedInUserId,
                    $productId,
                    $fullName,
                    $phoneNumber,
                    $phone2_value,
                    $addressLine1,
                    $address2_value,
                    $cityId,
                    $zone_id_value,
                    $district_id_value,
                    $upload_error
                );
                
                if (!$orderStmt->execute()) {
                    throw new Exception("Failed to create order: " . $orderStmt->error);
                }
                
                $orderId = $conn->insert_id;
                $orderStmt->close();
                
                // Create order item
                $itemSql = "INSERT INTO order_items (
                    order_id, product_id, quantity, unit_price, discount, total_amount, 
                    pay_status, status, description
                ) VALUES (?, ?, ?, ?, 0.00, ?, 'unpaid', 'pending', ?)";
                
                $itemStmt = $conn->prepare($itemSql);
                if (!$itemStmt) {
                    throw new Exception("Failed to prepare order item query: " . $conn->error);
                }
                
                $itemStmt->bind_param("iiidds", 
                    $orderId, 
                    $productId, 
                    $quantityInt, 
                    $unitPrice,
                    $subtotal,
                    $productDescription
                );
                
                if (!$itemStmt->execute()) {
                    throw new Exception("Failed to create order item: " . $itemStmt->error);
                }
                $order_item_id = $conn->insert_id;
                
                $itemStmt->close();
                
                // Check and deduct stock (FIFO within the selected selling price group)
                if (isset($_SESSION['allow_inventory']) && $_SESSION['allow_inventory'] == 1) {
                    // Select confirmed batches at the selected selling price, oldest-first (filtered by tenant)
                    $batchSql = "SELECT batch_id, remaining_qty FROM batches
                                 WHERE product_id = ? AND tenant_id = ? AND status = 'confirmed' AND remaining_qty > 0 AND selling_price = ?
                                 ORDER BY received_date ASC, batch_id ASC
                                 FOR UPDATE";
                    $batchStmt = $conn->prepare($batchSql);
                    if (!$batchStmt) {
                        throw new Exception("Failed to prepare batch query: " . $conn->error);
                    }
                    $batchStmt->bind_param("iid", $productId, $tenant_id, $selectedSellingPrice);
                    $batchStmt->execute();
                    $batchResult = $batchStmt->get_result();
                    $batchList = [];
                    $available = 0;
                    while ($br = $batchResult->fetch_assoc()) {
                        $batchList[] = ['batch_id' => intval($br['batch_id']), 'remaining_qty' => intval($br['remaining_qty'])];
                        $available += intval($br['remaining_qty']);
                    }
                    $batchStmt->close();

                    $hasBatches = !empty($batchList);

                    if ($hasBatches && $available < $quantityInt) {
                        $getNameSql = "SELECT name FROM products WHERE id = ?";
                        $nameStmt = $conn->prepare($getNameSql);
                        $nameStmt->bind_param("i", $productId);
                        $nameStmt->execute();
                        $productResult = $nameStmt->get_result();
                        $productName = $productCode; // Fallback
                        if ($productResult && $productRow = $productResult->fetch_assoc()) {
                            $productName = $productRow['name'];
                        }
                        $nameStmt->close();
                        throw new Exception("Insufficient stock for product: " . $productName . " (Code: " . $productCode . ") at price Rs. " . number_format($selectedSellingPrice, 2));
                    }

                    if ($hasBatches) {
                        // Deduct FIFO across batches (with tenant isolation)
                        $need = $quantityInt;
                        if ($isMainAdmin) {
                            $updateBatch = $conn->prepare("UPDATE batches SET remaining_qty = remaining_qty - ? WHERE batch_id = ? AND remaining_qty >= ?");
                        } else {
                            $updateBatch = $conn->prepare("UPDATE batches SET remaining_qty = remaining_qty - ? WHERE batch_id = ? AND remaining_qty >= ? AND tenant_id = ?");
                        }
                        $insertOib = $conn->prepare("INSERT INTO order_item_batches (order_item_id, order_id, batch_id, quantity) VALUES (?, ?, ?, ?)");
                        foreach ($batchList as $b) {
                            if ($need <= 0) break;
                            $take = min($need, $b['remaining_qty']);
                            if ($isMainAdmin) {
                                $updateBatch->bind_param("iii", $take, $b['batch_id'], $take);
                            } else {
                                $updateBatch->bind_param("iiii", $take, $b['batch_id'], $take, $tenant_id);
                            }
                            if (!$updateBatch->execute()) {
                                throw new Exception("Failed to deduct batch stock.");
                            }
                            $insertOib->bind_param("iiii", $order_item_id, $orderId, $b['batch_id'], $take);
                            $insertOib->execute();
                            $need -= $take;
                        }
                        $updateBatch->close();
                        $insertOib->close();
                    }

                    // Deduct product total (with tenant isolation)
                    $updateStockSql = "UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ? AND stock_quantity >= ? AND tenant_id = ?";
                    $stockUpdateStmt = $conn->prepare($updateStockSql);
                    if (!$stockUpdateStmt) {
                        throw new Exception("Failed to prepare stock update query: " . $conn->error);
                    }
                    $stockUpdateStmt->bind_param("iiii", $quantityInt, $productId, $quantityInt, $tenant_id);
                    
                    if (!$stockUpdateStmt->execute()) {
                        throw new Exception("Failed to update stock for product code: " . $productCode);
                    }
                    
                    if ($stockUpdateStmt->affected_rows === 0) {
                        // Fetch product name
                        $getNameSql = "SELECT name FROM products WHERE id = ?";
                        $nameStmt = $conn->prepare($getNameSql);
                        $nameStmt->bind_param("i", $productId);
                        $nameStmt->execute();
                        $productResult = $nameStmt->get_result();
                        $productName = $productCode; // Fallback
                        if ($productResult && $productRow = $productResult->fetch_assoc()) {
                            $productName = $productRow['name'];
                        }
                        $nameStmt->close();
                        throw new Exception("Insufficient stock for product: " . $productName . " (Code: " . $productCode . ")");
                    }
                    $stockUpdateStmt->close();
                }

                $successfulOrderIds[] = $orderId;
                $successCount++;
                
            } catch (Exception $e) {
                $conn->query("ROLLBACK TO SAVEPOINT row_sp");
                $errorCount++;
                $errorMessage = $e->getMessage();
                $errorMessages[] = "Row $rowNumber: " . $errorMessage;
                
                // ADD THESE LINES - Store the complete failed row data for CSV export
                $failedRowsData[] = [
                    'data' => $row,
                    'error' => $errorMessage
                ];
                
                error_log("ERROR - Row $rowNumber: " . $errorMessage);
                continue;
            }
        }
        
        fclose($handle);
        
        // Commit transaction
        $conn->commit();
        $transactionStarted = false;
        
        // Log the import summary
        if ($successCount > 0 || $errorCount > 0) {
            $logDetails = "Lead uploaded - Success($successCount) | Failed($errorCount) | Tenant: $tenant_id | Delivery Fee: $deliveryFee";
            if (!empty($selectedUsers)) {
                $logDetails .= " | Assigned to User IDs: " . implode(',', $selectedUsers);
            }
            $logOrderId = !empty($successfulOrderIds) ? $successfulOrderIds[0] : 0;
            logUserAction($conn, $loggedInUserId, "lead_upload", $logOrderId, $logDetails);
        }
        
        // Store results in session
            $_SESSION['import_result'] = [
                'success' => $successCount,
                'errors' => $errorCount,
                'messages' => $errorMessages,
                'info' => $infoMessages 
            ];

            // ADD THESE LINES - Store failed rows data for CSV export
            if (!empty($failedRowsData)) {
                $_SESSION['failed_rows_data'] = [
                    'headers' => $headers, // Original headers from CSV
                    'rows' => $failedRowsData
                ];
            }
        
        // Redirect to avoid resubmission
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
        
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($transactionStarted) {
            $conn->rollback();
            $transactionStarted = false;
        }
        
        if (isset($handle) && is_resource($handle)) {
            fclose($handle);
        }
        
        error_log("CRITICAL ERROR - Lead Upload: " . $e->getMessage());
        $_SESSION['import_error'] = $e->getMessage();
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Fetch tenants for main admin
$tenants = [];
if ($isMainAdmin == 1 && $role_id === 1) {
    $tenantsSql = "SELECT tenant_id, company_name FROM tenants WHERE status = 'active' ORDER BY company_name ASC";
    $tenantsStmt = $conn->prepare($tenantsSql);
    if ($tenantsStmt) {
        $tenantsStmt->execute();
        $tenantsResult = $tenantsStmt->get_result();
        while ($tenant = $tenantsResult->fetch_assoc()) {
            $tenants[] = $tenant;
        }
        $tenantsStmt->close();
    }
}

// Fetch users based on selected tenant
$users = [];
if ($selectedTenantId) {
    $usersSql = "SELECT id, name, tenant_id FROM users WHERE status = 'active' AND tenant_id = ? ORDER BY name ASC";
    $usersStmt = $conn->prepare($usersSql);
    if ($usersStmt) {
        $usersStmt->bind_param("i", $selectedTenantId);
        $usersStmt->execute();
        $usersResult = $usersStmt->get_result();
        while ($user = $usersResult->fetch_assoc()) {
            $users[] = $user;
        }
        $usersStmt->close();
    }
}

// Fetch active products for dropdown based on selected tenant
$products = [];
if ($selectedTenantId) {
    $productsSql = "SELECT id, name, product_code, stock_quantity FROM products WHERE status = 'active' AND tenant_id = ? ORDER BY name ASC";
    $productsStmt = $conn->prepare($productsSql);
    if ($productsStmt) {
        $productsStmt->bind_param("i", $selectedTenantId);
        $productsStmt->execute();
        $productsResult = $productsStmt->get_result();
        while ($row = $productsResult->fetch_assoc()) {
            $products[] = $row;
        }
        $productsStmt->close();
    }
}


?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Lead Upload | <?= htmlspecialchars($_SESSION['company_name'] ?? '') ?></title>
    
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>
    
    <link rel="stylesheet" href="../assets/css/leads.css" />
</head>

<style>
.alert-info {
    background-color: #d1ecf1;
    border: 1px solid #bee5eb;
    color: #0c5460;
    padding: 1rem;
    margin-bottom: 1.5rem;
    border-radius: 5px;
}

/* Info Box - Clean minimal alert */
.info-box {
    background: #e8f4fd;
    border: 1px solid #bee5eb;
    border-radius: 8px;
    padding: 16px 20px;
    margin-bottom: 24px;
    color: #0c5460;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.info-box--warning {
    background: #fff3cd;
    border-color: #ffeaa7;
    color: #856404;
}

.alert-info h4 {
    margin-bottom: 0.5rem;
    color: #0c5460;
}

.alert-info ul {
    margin-bottom: 0;
    padding-left: 1.5rem;
}

.alert-info li {
    margin-bottom: 0.3rem;
}


.alert-warning {
    background-color: #fff3cd;
    border: 1px solid #ffeaa7;
    color: #856404;
    padding: 1rem;
    margin-bottom: 1.5rem;
    border-radius: 5px;
}

.error-section {
    background-color: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
    padding: 1rem;
    margin-top: 1rem;
    border-radius: 5px;
}

.error-section h5 {
    color: #721c24;
    margin-bottom: 0.5rem;
}

.tenant-selector-card {
    background: #f8f9fa;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 15px 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
     width: fit-content;
     display: flex;
     align-items: center;
}

.tenant-selector-content {
    display: flex;
    align-items: center;
    gap: 15px;
}

.tenant-selector-label {
    color: #495057;
    font-weight: 600;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
    white-space: nowrap;
}

.tenant-selector-label i {
    font-size: 16px;
    color: #667eea;
}

.tenant-selector-dropdown form {
    margin: 0;
    display: flex;
    align-items: center;
}

.tenant-selector-dropdown select {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ced4da;
    border-radius: 6px;
    background: #fff;
    color: #495057;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
}

.tenant-selector-dropdown select:hover {
    border-color: #ccd1e8;
}

.tenant-selector-dropdown select:focus {
    outline: none;
    border-color: #ccd1e8;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.user-selection-section {
    margin-top: 2rem;
    margin-bottom: 2rem;
}

.user-selection-section h6 {
    font-size: 1.1rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: #333;
}

.user-selection-section .text-muted {
    margin-bottom: 1.5rem;
    font-size: 0.95rem;
}

/* Custom layout for lead upload */
.upload-grid-row {
    display: flex;
    flex-wrap: wrap;
    gap: 2rem;
    margin-bottom: 2rem;
}

.upload-column {
    flex: 1;
    min-width: 300px;
}

.product-option:hover {
    background-color: #f5f5f5;
}

.product-option.active {
    background-color: #e9ecef;
}

.section-title {
    font-size: 1.25rem;
    font-weight: 600;
    margin-bottom: 1rem;
    color: #333;
}

.alert-warning {
    background-color: #fff3cd;
    border: 1px solid #ffeaa7;
    color: #856404;
    padding: 1rem;
    margin-bottom: 1.5rem;
    border-radius: 5px;
}

.user-checkboxes {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    margin-bottom: 2rem;
}

.user-checkboxes .form-check {
    margin: 0;
}

.user-checkboxes .form-check-label {
    font-size: 1rem;
    margin-left: 8px;
    cursor: pointer;
}

.user-checkboxes .form-check-input {
    cursor: pointer;
    width: 18px;
    height: 18px;
    margin-top: 2px;
}

.form-actions {
    margin-top: 2rem;
    text-align: left;
}

#uploadBtn {
    background-color: #1565C0;
    border-color: #1565C0;
    font-weight: 500;
    border-radius: 8px;
    font-size: 14px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 9px 24px;
}

#uploadBtn:hover {
    background-color: #0b5ed7;
    border-color: #0a58ca;
}

#uploadBtn:disabled {
    background-color: #6c757d;
    border-color: #6c757d;
    cursor: not-allowed;
    opacity: 0.65;
}

#uploadBtn i {
    margin-right: 8px;
}

.quick-guide-container {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 15px;
    margin-bottom: 2rem;
}

.guide-card {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 15px;
}

.guide-card h6 {
    font-size: 0.95rem;
    font-weight: 600;
    margin-bottom: 10px;
    color: #333;
}

.guide-card p {
    font-size: 0.85rem;
    margin-bottom: 0;
    color: #555;
    line-height: 1.5;
}

.guide-card ol,
.guide-card ul {
    font-size: 0.85rem;
    margin-bottom: 0;
    padding-left: 20px;
    color: #555;
}

.guide-card ol li,
.guide-card ul li {
    margin-bottom: 5px;
}

.guide-card code {
    background: #e9ecef;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 0.8rem;
    color: #d63384;
}

@media (max-width: 768px) {
    .quick-guide-container {
        grid-template-columns: 1fr;
    }
    
    .user-checkboxes {
        flex-direction: column;
        gap: 15px;
    }
}
/* Download button styling */
.btn-danger {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-danger i {
    font-size: 1rem;
}
</style>

<body>
    <?php 
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/loader.php');
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');
    ?>

    <div class="pc-container">
        <div class="pc-content">
            
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">
                            Lead Management
                            <i class="fas fa-info-circle text-primary" style="cursor: pointer; font-size: 16px; margin-left: 8px; color: #3b82f6;" onclick="openInfoModal()" title="How to use this page"></i>
                        </h5>
                    </div>
                </div>
            </div>

          <?php if (isset($_SESSION['import_result'])): ?>
    <?php 
        $impSuccess = $_SESSION['import_result']['success'];
        $impErrors = $_SESSION['import_result']['errors'];
        $impMessages = $_SESSION['import_result']['messages'] ?? [];
        $impInfo = $_SESSION['import_result']['info'] ?? [];
    ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($impErrors > 0): ?>
                toastManager.warning('Import completed: <?php echo $impSuccess; ?> imported, <?php echo $impErrors; ?> failed', 8000);
            <?php else: ?>
                toastManager.success('Successfully imported <?php echo $impSuccess; ?> records', 5000);
            <?php endif; ?>
        });
    </script>

    <?php if ($impErrors > 0 || !empty($impInfo)): ?>
    <div style="background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 20px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">
        <?php if ($impErrors > 0): ?>
            <div style="margin-bottom: 16px;">
                <?php if (isset($_SESSION['failed_rows_data'])): ?>
                    <div style="margin-bottom: 14px;">
                        <a href="?download_errors=1" class="btn btn-danger btn-sm">
                            <i class="feather icon-download"></i> Download Failed Rows CSV
                        </a>
                        <p style="margin-top: 6px; font-size: 0.8rem; color: #6b7280;">
                            <em>Fix the issues in the CSV and re-upload just those rows.</em>
                        </p>
                    </div>
                <?php endif; ?>
                <?php if (!empty($impMessages)): ?>
                    <details>
                        <summary style="cursor: pointer; font-weight: 600; color: #92400e; font-size: 0.85rem;">
                            <i class="fas fa-exclamation-triangle"></i> View Error Details (<?php echo count($impMessages); ?>)
                        </summary>
                        <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px 16px; margin-top: 8px;">
                            <ul style="margin-bottom: 0; padding-left: 20px; color: #991b1b; font-size: 0.82rem;">
                                <?php foreach ($impMessages as $message): ?>
                                    <li style="margin-bottom: 4px;"><?php echo htmlspecialchars($message); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </details>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($impInfo)): ?>
            <div>
                <details open>
                    <summary style="cursor: pointer; font-weight: 600; color: #0369a1; font-size: 0.85rem;">
                        <i class="fas fa-info-circle"></i> Additional Information (<?php echo count($impInfo); ?> notices)
                    </summary>
                    <ul style="margin-top: 8px; margin-bottom: 0; padding-left: 20px; color: #0c4a6e; font-size: 0.85rem;">
                        <?php foreach ($impInfo as $infoMsg): ?>
                            <li style="margin-bottom: 4px;"><?php echo htmlspecialchars($infoMsg); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php unset($_SESSION['import_result']); ?>
<?php endif; ?>
            
            <?php if (isset($_SESSION['import_error'])): ?>
                <div class="alert alert-danger">
                    <strong>Error:</strong> <?php echo htmlspecialchars($_SESSION['import_error']); ?>
                </div>
                <?php unset($_SESSION['import_error']); ?>
            <?php endif; ?>
            <div class="lead-upload-container">
                
                <?php if ($isMainAdmin == 1 && $role_id === 1): ?>
                    <!-- Tenant Selector Card -->
                    <div class="tenant-selector-card">
                        <div class="tenant-selector-content">
                            <label class="tenant-selector-label">
                               <i class="feather icon-briefcase"></i>
                                Tenant:
                            </label>
                            <div class="tenant-selector-dropdown">
                                <form method="POST" id="tenantSelectForm">
                                    <select name="selected_tenant" id="tenantSelect" onchange="this.form.submit()">
                                        <option value="">-- Select Tenant --</option>
                                        <?php foreach ($tenants as $tenant): ?>
                                            <option value="<?php echo $tenant['tenant_id']; ?>" 
                                                <?php echo ($selectedTenantId == $tenant['tenant_id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($tenant['company_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($selectedTenantId): ?>
                    <form method="POST" enctype="multipart/form-data" id="uploadForm">
                        <?php if ($isMainAdmin == 1): ?>
                            <input type="hidden" name="selected_tenant" value="<?php echo $selectedTenantId; ?>">
                        <?php endif; ?>

                        <div class="upload-grid-row">
                            <!-- Left Column: Product Selection -->
                            <div class="upload-column product-selection-section">
                                <h2 class="section-title">Select Product <span style="color: red;">*</span></h2>
                                
                                <div class="form-group" style="position: relative;">
                                    <input type="text" id="product_search" class="form-control" placeholder="Type to search product..." autocomplete="off" style="width: 100%; padding: 10px; border: 1px solid #ced4da; border-radius: 4px;">
                                    <input type="hidden" name="product_id" id="product_id" required>
                                    <div id="product_dropdown" style="display: none; position: absolute; background: white; border: 1px solid #ced4da; border-top: none; max-height: 200px; overflow-y: auto; width: 100%; z-index: 1000; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                                        <?php foreach ($products as $prod): ?>
                                            <div class="product-option" data-id="<?php echo $prod['id']; ?>" data-name="<?php echo htmlspecialchars($prod['name']); ?>" data-code="<?php echo htmlspecialchars($prod['product_code']); ?>" style="padding: 10px; cursor: pointer; border-bottom: 1px solid #f0f0f0;">
                                                <strong><?php echo htmlspecialchars($prod['name']); ?></strong> (<?php echo htmlspecialchars($prod['product_code']); ?>)
                                                <?php if (isset($_SESSION['allow_inventory']) && $_SESSION['allow_inventory'] == 1): ?>
                                                    <span style="color: #6c757d; font-size: 0.9em;"> - Stock: <?php echo $prod['stock_quantity']; ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                        <div id="no_products_found" style="display: none; padding: 10px; color: #999; text-align: center;">No products found</div>
                                    </div>
                                </div>

                                <?php if (isset($_SESSION['allow_inventory']) && $_SESSION['allow_inventory'] == 1): ?>
                                <div class="form-group" style="margin-top: 15px;">
                                    <label for="selling_price_dropdown" style="font-size: 0.9rem; font-weight: 500;">
                                        Select Selling Price <span style="color: red;">*</span>
                                    </label>
                                    <select id="selling_price_dropdown" name="selling_price_dropdown" class="form-control" disabled style="width: 100%; padding: 10px; border: 1px solid #ced4da; border-radius: 4px;">
                                        <option value="">-- Select a product first --</option>
                                    </select>
                                    <div id="selling_price_hint" style="font-size: 0.8rem; color: #6c757d; margin-top: 5px;"></div>
                                    <input type="hidden" name="selling_price" id="selling_price" value="">
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Right Column: CSV Upload -->
                            <div class="upload-column file-upload-section" style="margin-bottom: 0; padding-bottom: 0;">
                                <h2 class="section-title">CSV Upload</h2>
                                <div class="file-upload-box" style="margin-top: 0.5rem; display: flex; flex-direction: column; align-items: start; gap: 10px; padding: 15px;">
                                    <p id="file-name" style="margin-bottom: 0;">No file selected</p>
                                    <div style="display: flex; gap: 10px; width: 100%; justify-content: space-between; align-items: center;">
                                        <input type="file" id="csv_file" name="csv_file" accept=".csv" style="display: none;">
                                        <button type="button" class="choose-file-btn" onclick="document.getElementById('csv_file').click()">Choose File</button>
                                        <a href="/OMS/dist/templates/generate_template.php" class="choose-file-btn" style="text-decoration: none;">Generate Template</a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <hr>

                        <div class="user-selection-section">
                            <h2 class="section-title">Select Users</h2>
                            <p class="text-muted">Choose which users will receive the imported leads</p>
                            
                            <div class="user-checkboxes" id="usersList">
                                <?php if (!empty($users)): ?>
                                    <?php foreach ($users as $user): ?>
                                        <div class="form-check" style="min-width: 150px;">
                                            <input class="form-check-input" 
                                                type="checkbox" 
                                                name="users[]" 
                                                value="<?php echo $user['id']; ?>" 
                                                id="user_<?php echo $user['id']; ?>">
                                            <label class="form-check-label" for="user_<?php echo $user['id']; ?>">
                                                <?php echo htmlspecialchars($user['name']); ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-danger">No active users found for this tenant.</p>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (!empty($users)): ?>
                                <button type="button" class="btn btn-outline-secondary btn-sm mb-3" id="toggleSelectAll">Select All</button>
                            <?php endif; ?>

                            <div class="action-buttons mt-4" style="display: flex; gap: 15px;">
                                <button type="button" class="btn btn-secondary" id="resetBtn">Reset</button>
                                <button type="submit" class="btn btn-primary btn-lg" id="uploadBtn" <?php echo empty($users) ? 'disabled' : ''; ?>>
                                    <i class="feather icon-upload"></i> Import Leads
                                </button>
                            </div>
                        </div>

                        <hr>

                    </form>
                <?php else: ?>
                    <?php if ($isMainAdmin == 1 && $role_id === 1): ?>
                        <?php if (!empty($tenants)): ?>
                            <div class="info-box">
                                <i class="feather icon-info"></i>
                                Please select a tenant above to begin uploading leads.
                            </div>
                        <?php else: ?>
                            <div class="info-box info-box--warning">
                                <i class="feather icon-alert-triangle"></i>
                                No active tenants found in the system. Please create a tenant first before uploading leads.
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
                
            </div>
        </div>
    </div>
    <?php
    include_once($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/info_modal.php');
    renderInfoModal(
        'How Lead Upload Works',
        'fas fa-upload',
        '<div style="font-family: system-ui, -apple-system, sans-serif;">

        <div style="margin-bottom: 16px;">
            <h6 style="margin: 0 0 10px; font-size: 14px;">📌 3 Simple Steps</h6>
            <ol style="margin: 0; padding-left: 20px; color: #374151; font-size: 13.5px; line-height: 1.8;">
                <li><strong>Generate template</strong> → fill with customer data</li>
                <li><strong>Pick a product</strong> and select users who get the leads</li>
                <li><strong>Upload the CSV</strong> → system imports automatically</li>
            </ol>
        </div>

        <div style="margin-bottom: 16px;">
            <h6 style="margin: 0 0 10px; font-size: 14px;">✅ CSV Requirements</h6>
            <div style="display: grid; grid-template-columns: auto 1fr; gap: 4px 10px; font-size: 13px; color: #374151;">
                <span style="font-weight: 600; color: #dc2626;">Required:</span>
                <span>Full Name, Phone Number, City, Address Line 1</span>
                <span style="font-weight: 600; color: #6b7280;">Optional:</span>
                <span>Quantity, Phone 2, Email, Address 2, Other</span>
                <span style="font-weight: 600; color: #6b7280;">Phone:</span>
                <span>10 digits (e.g. 0771234567)</span>
                <span style="font-weight: 600; color: #6b7280;">Size:</span>
                <span>Max 10MB · .csv only</span>
            </div>
        </div>

        <div style="margin-bottom: 16px;">
            <h6 style="margin: 0 0 10px; font-size: 14px;">🔍 How Customers Are Matched</h6>
            <ul style="margin: 0; padding-left: 20px; color: #374151; font-size: 13px; line-height: 1.7;">
                <li>Checks by <strong>Phone Number</strong> against existing customers</li>
                <li><strong>Match found</strong> → new order for that customer</li>
                <li><strong>No match</strong> → new customer created automatically</li>
            </ul>
        </div>

        <div style="margin-bottom: 16px;">
            <h6 style="margin: 0 0 10px; font-size: 14px;">📊 After Upload</h6>
            <ul style="margin: 0; padding-left: 20px; color: #374151; font-size: 13px; line-height: 1.7;">
                <li>See how many imported <strong>✓</strong> vs failed <strong>✗</strong></li>
                <li><strong>Download failed rows</strong> → fix errors → re-upload</li>
            </ul>
        </div>

        <div style="background: #fef3c7; border-radius: 6px; padding: 10px 12px; font-size: 13px; color: #92400e;">
            💡 City names must <strong>exactly match</strong> what\'s in the system. Use the template to get the right format.
        </div>

        </div>',
        '650px'
    );
    ?>

    <?php
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php');
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php');
    ?>


    <script>
    // Product Search Autocomplete
    const productSearch = document.getElementById('product_search');
    const productId = document.getElementById('product_id');
    const productDropdown = document.getElementById('product_dropdown');
    const productOptions = document.querySelectorAll('.product-option');
    
    if (productSearch) {
        // Show dropdown when input is focused or typed in
        productSearch.addEventListener('focus', function() {
            this.select(); 
            filterProducts(this.value);
            productDropdown.style.display = 'block';
        });
        
        productSearch.addEventListener('input', function() {
            filterProducts(this.value);
            productDropdown.style.display = 'block';
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!productSearch.contains(e.target) && !productDropdown.contains(e.target)) {
                productDropdown.style.display = 'none';
            }
        });
    }
    
    // Filter products based on search term
    function filterProducts(searchTerm) {
        const term = searchTerm.toLowerCase().trim();
        const noProductsFound = document.getElementById('no_products_found');
        let hasVisibleOptions = false;
        
        productOptions.forEach(option => {
            const name = option.dataset.name.toLowerCase();
            const code = option.dataset.code.toLowerCase();
            const combined = (name + ' (' + code + ')').toLowerCase();
            
            if (term === '' || name.includes(term) || code.includes(term) || combined.includes(term)) {
                option.style.display = 'block';
                hasVisibleOptions = true;
            } else {
                option.style.display = 'none';
            }
        });
        
        if (noProductsFound) {
            noProductsFound.style.display = hasVisibleOptions ? 'none' : 'block';
        }
    }
    
    // Handle product selection
    productOptions.forEach(option => {
        option.addEventListener('click', function() {
            const id = this.dataset.id;
            const name = this.dataset.name;
            const code = this.dataset.code;
            
            productId.value = id;
            productSearch.value = name + ' (' + code + ')';
            productDropdown.style.display = 'none';
            loadSellingPrices(id);
        });
    });

    // Load distinct batch selling prices for the selected product
    const sellingPriceDropdown = document.getElementById('selling_price_dropdown');
    const sellingPriceHidden = document.getElementById('selling_price');
    const sellingPriceHint = document.getElementById('selling_price_hint');
    const tenantIdForPrices = <?php echo $selectedTenantId ? (int)$selectedTenantId : 0; ?>;

    function loadSellingPrices(productId) {
        if (!sellingPriceDropdown) return;

        sellingPriceDropdown.innerHTML = '<option value="">-- Loading prices... --</option>';
        sellingPriceDropdown.disabled = true;
        if (sellingPriceHidden) sellingPriceHidden.value = '';
        if (sellingPriceHint) sellingPriceHint.textContent = '';

        fetch('/OMS/dist/orders/get_product_batches.php?product_id=' + encodeURIComponent(productId) +
              (tenantIdForPrices ? '&tenant_id=' + encodeURIComponent(tenantIdForPrices) : ''))
            .then(response => response.json())
            .then(data => {
                if (data.success && data.prices && data.prices.length > 0) {
                    let opts = '<option value="">-- Select Selling Price --</option>';
                    data.prices.forEach(p => {
                        opts += '<option value="' + p.selling_price + '" data-stock="' + p.stock + '">Rs. ' +
                                Number(p.selling_price).toFixed(2) + ' (Stock: ' + p.stock + ')</option>';
                    });
                    sellingPriceDropdown.innerHTML = opts;
                    sellingPriceDropdown.disabled = false;
                    if (sellingPriceHint) sellingPriceHint.textContent = 'Select one selling price for all imported rows.';
                } else {
                    sellingPriceDropdown.innerHTML = '<option value="">-- No batch prices available --</option>';
                    sellingPriceDropdown.disabled = true;
                    if (sellingPriceHidden) sellingPriceHidden.value = '';
                    if (sellingPriceHint) sellingPriceHint.textContent = 'This product has no confirmed batches with a selling price.';
                }
            })
            .catch(() => {
                sellingPriceDropdown.innerHTML = '<option value="">-- Error loading prices --</option>';
                sellingPriceDropdown.disabled = true;
                if (sellingPriceHint) sellingPriceHint.textContent = 'Failed to load batch prices.';
            });
    }

    if (sellingPriceDropdown) {
        sellingPriceDropdown.addEventListener('change', function() {
            if (sellingPriceHidden) sellingPriceHidden.value = this.value;
        });
    }

    // Display selected file name
    document.getElementById('csv_file')?.addEventListener('change', function(e) {
        const file = e.target.files[0];
        const fileNameEl = document.getElementById('file-name');
        
        if (file) {
            const validExtensions = ['.csv'];
            const fileName = file.name.toLowerCase();
            const isValidExtension = validExtensions.some(ext => fileName.endsWith(ext));
            
            if (!isValidExtension) {
                alert('Please select a valid CSV file.');
                this.value = '';
                fileNameEl.textContent = 'No file selected';
                return;
            }
            
            const maxSize = 10 * 1024 * 1024;
            if (file.size > maxSize) {
                alert('File size must be less than 10MB.');
                this.value = '';
                fileNameEl.textContent = 'No file selected';
                return;
            }
            
            fileNameEl.textContent = file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
        } else {
            fileNameEl.textContent = 'No file selected';
        }
    });

    // Toggle Select All
    const toggleBtn = document.getElementById('toggleSelectAll');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            const checkboxes = document.querySelectorAll('input[name="users[]"]');
            const allChecked = Array.from(checkboxes).every(checkbox => checkbox.checked);
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = !allChecked;
            });
            
            this.textContent = allChecked ? 'Select All' : 'Deselect All';
        });
    }

    // Reset Form
    document.getElementById('resetBtn')?.addEventListener('click', function() {
        if (confirm('Are you sure you want to reset the form?')) {
            document.querySelectorAll('input[name="users[]"]').forEach(checkbox => {
                checkbox.checked = false;
            });
            
            const fileInput = document.getElementById('csv_file');
            if (fileInput) fileInput.value = '';
            
            const fileNameEl = document.getElementById('file-name');
            if (fileNameEl) fileNameEl.textContent = 'No file selected';

            const prodSearch = document.getElementById('product_search');
            if (prodSearch) prodSearch.value = '';

            const prodId = document.getElementById('product_id');
            if (prodId) prodId.value = '';

            const priceDropdown = document.getElementById('selling_price_dropdown');
            if (priceDropdown) {
                priceDropdown.innerHTML = '<option value="">-- Select a product first --</option>';
                priceDropdown.disabled = true;
            }
            const priceHidden = document.getElementById('selling_price');
            if (priceHidden) priceHidden.value = '';
            const priceHint = document.getElementById('selling_price_hint');
            if (priceHint) priceHint.textContent = '';
            
            if (toggleBtn) {
                toggleBtn.textContent = 'Select All';
            }
        }
    });

    // Form validation before submit
    document.getElementById('uploadForm')?.addEventListener('submit', function(e) {
        const fileInput = document.getElementById('csv_file');
        const userCheckboxes = document.querySelectorAll('input[name="users[]"]:checked');
        const prodId = document.getElementById('product_id');
        
        if (!prodId.value) {
            e.preventDefault();
            alert('Please select a product first.');
            return false;
        }

        <?php if (isset($_SESSION['allow_inventory']) && $_SESSION['allow_inventory'] == 1): ?>
        const sellingPriceHidden = document.getElementById('selling_price');
        if (!sellingPriceHidden.value) {
            e.preventDefault();
            alert('Please select a selling price for this upload.');
            return false;
        }
        <?php endif; ?>

        if (!fileInput.files.length) {
            e.preventDefault();
            alert('Please select a CSV file to upload.');
            return false;
        }
        
        if (userCheckboxes.length === 0) {
            e.preventDefault();
            alert('Please select at least one user to distribute leads.');
            return false;
        }
        
        // Disable submit button to prevent double submission
        const submitBtn = document.getElementById('uploadBtn');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Importing...';
        
        return true;
    });

    // Auto-hide success alerts after 10 seconds
    setTimeout(function() {
        const successAlerts = document.querySelectorAll('.alert-success');
        successAlerts.forEach(alert => {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        });
    }, 10000);
    </script>

</body>
</html>