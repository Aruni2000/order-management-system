<?php
// File: templates/lead_upload.php
// Start output buffering to prevent header issues
ob_start();

// Start session at the very beginning
session_start();

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    ob_end_clean();
    header("Location: /order_management/dist/pages/login.php");
    exit();
}

// Include the database connection file early
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/connection/db_connection.php');

// Initialize transaction flag
$transactionStarted = false;

// Function to log user actions
function logUserAction($conn, $user_id, $action_type, $inquiry_id, $details) {
    $logSql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details) VALUES (?, ?, ?, ?)";
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
    if (isset($_POST['selected_tenant'])) {
        $selectedTenantId = (int)$_POST['selected_tenant'];
        $_SESSION['upload_selected_tenant'] = $selectedTenantId;
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

        // Fetch delivery fee from branding table
        $deliveryFee = 0.00;
        $brandingSql = "SELECT delivery_fee FROM branding WHERE tenant_id = ? AND active = 1 LIMIT 1";
        $brandingStmt = $conn->prepare($brandingSql);
        if (!$brandingStmt) {
            throw new Exception("Failed to prepare branding query: " . $conn->error);
        }
        $brandingStmt->bind_param("i", $tenant_id);
        $brandingStmt->execute();
        $brandingResult = $brandingStmt->get_result();

        if ($brandingResult->num_rows > 0) {
            $brandingData = $brandingResult->fetch_assoc();
            $deliveryFee = (float)$brandingData['delivery_fee'];
            error_log("DEBUG - Delivery fee fetched from branding for tenant_id $tenant_id: $deliveryFee");
        } else {
            error_log("WARNING - No active branding found for tenant_id: $tenant_id, using default delivery_fee: 0.00");
        }
        $brandingStmt->close();
        
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
            'product code',
            'quantity',
            'total amount', 
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
        
        // Process each row
        while (($row = fgetcsv($handle)) !== FALSE) {
            $rowNumber++;
            
            try {
                // Skip empty rows
                if (empty(array_filter($row))) {
                    continue;
                }
                
                // Map CSV columns using header positions
                $fullName = trim($row[$headerMap['full name']] ?? '');
                $phoneNumber = trim($row[$headerMap['phone number']] ?? '');
                $phoneNumber2 = trim($row[$headerMap['phone number 2']] ?? '');
                $city = trim($row[$headerMap['city']] ?? '');
                $email = trim($row[$headerMap['email']] ?? '');
                $addressLine1 = trim($row[$headerMap['address line 1']] ?? '');
                $addressLine2 = trim($row[$headerMap['address line 2']] ?? '');
                $productCode = trim($row[$headerMap['product code']] ?? '');
                $quantity = trim($row[$headerMap['quantity']] ?? '1');
                $totalAmount = trim($row[$headerMap['total amount']] ?? '');
                $other = trim($row[$headerMap['other']] ?? '');

                // Phone number normalization
                if (strlen($phoneNumber) === 12 && substr($phoneNumber, 0, 3) === '+94') {
                    $phoneNumber = '0' . substr($phoneNumber, 3);
                } elseif (strlen($phoneNumber) === 11 && substr($phoneNumber, 0, 2) === '94') {
                    $phoneNumber = '0' . substr($phoneNumber, 2);
                }

                if (!empty($phoneNumber2)) {
                    if (strlen($phoneNumber2) === 12 && substr($phoneNumber2, 0, 3) === '+94') {
                        $phoneNumber2 = '0' . substr($phoneNumber2, 3);
                    } elseif (strlen($phoneNumber2) === 11 && substr($phoneNumber2, 0, 2) === '94') {
                        $phoneNumber2 = '0' . substr($phoneNumber2, 2);
                    }
                }

                // Excel removed leading 0 → add it back
                if (strlen($phoneNumber) === 9 && ctype_digit($phoneNumber)) {
                    $phoneNumber = '0' . $phoneNumber;
                }

                if (!empty($phoneNumber2) && strlen($phoneNumber2) === 9 && ctype_digit($phoneNumber2)) {
                    $phoneNumber2 = '0' . $phoneNumber2;
                }
                
                // Handle email - normalize empty values
                if (empty($email) || in_array(strtolower($email), ['', 'null', 'n/a', '-'])) {
                    $email = '';
                } else {
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        throw new Exception("Invalid email format: '$email'");
                    }
                }
                
                // Handle phone number 2 - normalize empty values
                if (empty($phoneNumber2) || in_array(strtolower($phoneNumber2), ['null', 'n/a', '-'])) {
                    $phoneNumber2 = '';
                }
                
                // Validation
                if (empty($fullName)) {
                    throw new Exception("Full Name is required");
                }
                if (empty($phoneNumber)) {
                    throw new Exception("Phone Number is required");
                }
                if (empty($city)) {
                    throw new Exception("City is required");
                }
                if (empty($productCode)) {
                    throw new Exception("Product Code is required");
                }
                if (empty($quantity)) {
                    throw new Exception("Quantity is required");
                }
                if (empty($totalAmount)) {
                    throw new Exception("Total Amount is required");
                }
                if (empty($addressLine1)) {
                    throw new Exception("Address Line 1 is required");
                }
                
                // Phone must be exactly 10 digits and start with 0
                if (!preg_match('/^0\d{9}$/', $phoneNumber)) {
                    throw new Exception("Phone Number must be exactly 10 digits and start with 0 (got: '$phoneNumber')");
                }
                
                if (!empty($phoneNumber2) && !preg_match('/^0\d{9}$/', $phoneNumber2)) {
                    throw new Exception("Phone Number 2 must be exactly 10 digits and start with 0 (got: '$phoneNumber2')");
                }

                if (!empty($phoneNumber2) && $phoneNumber === $phoneNumber2) {
                    throw new Exception("Phone Number 2 cannot be the same as Phone Number");
                }

                if (!is_numeric($quantity) || $quantity <= 0 || $quantity != floor($quantity)) {
                    throw new Exception("Quantity must be a positive integer (got: '$quantity')");
                }
                $quantityInt = (int)$quantity;
                
                if (!is_numeric($totalAmount) || $totalAmount <= 0) {
                    throw new Exception("Total Amount must be a positive number");
                }
                
                $totalAmountDecimal = (float)$totalAmount;
                
                // Fetch product data
                $productSql = "SELECT id, lkr_price, description FROM products WHERE product_code = ? AND status = 'active' AND tenant_id = ?";
                $productStmt = $conn->prepare($productSql);
                if (!$productStmt) {
                    throw new Exception("Failed to prepare product query: " . $conn->error);
                }
                $productStmt->bind_param("si", $productCode, $tenant_id);
                $productStmt->execute();
                $productResult = $productStmt->get_result();
                
                if ($productResult->num_rows === 0) {
                    throw new Exception("Product code '$productCode' not found or inactive");
                }
                
                $product = $productResult->fetch_assoc();
                $productId = $product['id'];
                $unitPrice = (float)$product['lkr_price'];
                $productDescription = $product['description'] ?? '';
                $productStmt->close();
                
                // Look up city_id and get zone_id, district_id
                $cityId = null;
                $zone_id = null;
                $district_id = null;
                
                $citySql = "SELECT city_id, zone_id, district_id FROM city_table WHERE city_name = ? AND is_active = 1 LIMIT 1";
                $cityStmt = $conn->prepare($citySql);
                if (!$cityStmt) {
                    throw new Exception("Failed to prepare city query: " . $conn->error);
                }
                $cityStmt->bind_param("s", $city);
                $cityStmt->execute();
                $cityResult = $cityStmt->get_result();
                
                if ($cityResult->num_rows > 0) {
                    $cityData = $cityResult->fetch_assoc();
                    $cityId = $cityData['city_id'];
                    $zone_id = $cityData['zone_id'];
                    $district_id = $cityData['district_id'];
                } else {
                    throw new Exception("City '$city' not found or inactive");
                }
                $cityStmt->close();
                
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
                    $infoMessages[] = "Row $rowNumber: Phone number already exists - Order created for existing customer (Customer ID: $customerId)";
                }
                $phoneStmt->close();

                // Check email if phone not found
                if ($is_new_customer && !empty($email)) {
                    $checkEmailSql = "SELECT customer_id FROM customers 
                                    WHERE email = ? 
                                    AND tenant_id = ?
                                    AND status = 'Active'
                                    LIMIT 1";
                    
                    $emailStmt = $conn->prepare($checkEmailSql);
                    if (!$emailStmt) {
                        throw new Exception("Failed to prepare email check query: " . $conn->error);
                    }
                    
                    $emailStmt->bind_param("si", $email, $tenant_id);
                    $emailStmt->execute();
                    $emailResult = $emailStmt->get_result();
                    
                    if ($emailResult->num_rows > 0) {
                        $customerId = $emailResult->fetch_assoc()['customer_id'];
                        $is_new_customer = false;
                        $infoMessages[] = "Row $rowNumber: Email '$email' already registered - Order created for existing customer (Customer ID: $customerId)";
                    }
                    $emailStmt->close();
                }

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
                
                // Randomly assign to one of the selected users
                $assignedUserId = $selectedUsers[array_rand($selectedUsers)];
                
                // Calculate total amount with delivery fee
                $orderTotalAmount = $totalAmountDecimal + $deliveryFee;

                // Create order header
                $orderSql = "INSERT INTO order_header (
                    tenant_id, customer_id, user_id, issue_date, due_date, 
                    subtotal, discount, total_amount, delivery_fee,
                    notes, currency, status, pay_status, pay_date, created_by,
                    product_code, full_name, mobile, mobile_2,
                    address_line1, address_line2, city_id, zone_id, district_id,
                    interface, call_log
                ) VALUES (?, ?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), 
                        ?, 0.00, ?, ?, ?, 'lkr', 'pending', 'unpaid', NULL, ?, 
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, 'leads', 0)";
                
                $orderStmt = $conn->prepare($orderSql);
                if (!$orderStmt) {
                    throw new Exception("Failed to prepare order query: " . $conn->error);
                }
                
                $notes = !empty($other) ? $other : null;
                $phone2_value = !empty($phoneNumber2) ? $phoneNumber2 : null;
                $address2_value = !empty($addressLine2) ? $addressLine2 : null;
                $zone_id_value = !empty($zone_id) ? $zone_id : null;
                $district_id_value = !empty($district_id) ? $district_id : null;
                
                $orderStmt->bind_param(
                    "iiidddsiisssssiii",
                    $tenant_id,
                    $customerId,
                    $assignedUserId,
                    $totalAmountDecimal,
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
                    $district_id_value
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
                    $totalAmountDecimal,
                    $productDescription
                );
                
                if (!$itemStmt->execute()) {
                    throw new Exception("Failed to create order item: " . $itemStmt->error);
                }
                
                $itemStmt->close();
                
                $successfulOrderIds[] = $orderId;
                $successCount++;
                
            } catch (Exception $e) {
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

include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/sidebar.php');
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Order Management Admin Portal - Lead Upload</title>
    
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/head.php'); ?>
    
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/leads.css" id="main-style-link" />
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
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/loader.php'); ?>

    <div class="pc-container">
        <div class="pc-content">
            
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">Lead Management</h5>
                    </div>
                </div>
            </div>

          <?php if (isset($_SESSION['import_result'])): ?>
    <script>
        setTimeout(function() {
            window.location.reload();
        }, 5000);
    </script>
    <div class="alert alert-<?php echo $_SESSION['import_result']['errors'] > 0 ? 'warning' : 'success'; ?>">
        <h4>Import Results</h4>
        <p><strong>Successfully imported:</strong> <?php echo $_SESSION['import_result']['success']; ?> records</p>
        
        <?php if (!empty($_SESSION['import_result']['info'])): ?>
            <div class="alert alert-info mt-3" style="background-color: #e7f3ff; border-color: #b3d9ff;">
                <details open>
                    <summary style="cursor: pointer; font-weight: bold; color: #004085;">
                         Additional Information (<?php echo count($_SESSION['import_result']['info']); ?> notices)
                    </summary>
                    <ul class="mt-2" style="margin-bottom: 0;">
                        <?php foreach ($_SESSION['import_result']['info'] as $infoMsg): ?>
                            <li style="color: #004085;"><?php echo htmlspecialchars($infoMsg); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            </div>
        <?php endif; ?>
        
        <?php if ($_SESSION['import_result']['errors'] > 0): ?>
            <p><strong>Failed imports:</strong> <?php echo $_SESSION['import_result']['errors']; ?> records</p>
            
            <!-- ============ ADD THIS ENTIRE BLOCK HERE ============ -->
            <?php if (isset($_SESSION['failed_rows_data'])): ?>
                <div style="margin-top: 1rem; margin-bottom: 1rem;">
                    <a href="?download_errors=1" class="btn btn-danger">
                        <i class="feather icon-download"></i> Download Failed Rows CSV
                    </a>
                    <p style="margin-top: 0.5rem; font-size: 0.9rem; color: #721c24;">
                        <em>💡 Download the CSV file containing only the failed rows with error reasons. Fix the issues and re-upload just those rows.</em>
                    </p>
                </div>
            <?php endif; ?>
            <!-- ============ END OF NEW BLOCK ============ -->
            
            <?php if (!empty($_SESSION['import_result']['messages'])): ?>
                <details>
                    <summary style="cursor: pointer; font-weight: bold; color: #856404;">⚠️ View Error Details</summary>
                    <div class="error-section">
                        <ul class="mt-2">
                            <?php foreach ($_SESSION['import_result']['messages'] as $message): ?>
                                <li><?php echo htmlspecialchars($message); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </details>
            <?php endif; ?>
        <?php endif; ?>
    </div>
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
                        
                        <div class="file-upload-section">
                            <a href="/order_management/dist/templates/generate_template.php" class="choose-file-btn">
                                Download CSV Template
                            </a>

                            <div class="file-upload-box">
                                <p><strong>Select CSV File</strong></p>
                                <p id="file-name">No file selected</p>
                                <input type="file" id="csv_file" name="csv_file" accept=".csv" style="display: none;" required>
                                <button type="button" class="choose-file-btn" onclick="document.getElementById('csv_file').click()">
                                     Choose File
                                </button>
                            </div>
                        </div>
                        
                        <div class="quick-guide-container">
                            <div class="guide-card">
                                <h6> Steps</h6>
                                <ol>
                                    <li>Download CSV template</li>
                                    <li>Fill in lead data</li>
                                    <li>Select users below</li>
                                    <li>Upload CSV</li>
                                </ol>
                            </div>
                            
                            <div class="guide-card">
                                <h6> Required Fields</h6>
                                <p>Full Name, Phone, City, Address, Product Code, Quantity, Total Amount</p>
                            </div>
                            
                            <div class="guide-card">
                                <h6> Phone Format</h6>
                                <p>Any format works:<br><code>0771234567</code>, <code>94771234567</code>, <code>+94771234567</code></p>
                            </div>
                            
                            <div class="guide-card">
                                <h6>Smart Features</h6>
                                <ul>
                                    <li>Finds existing customers</li>
                                    <li>No duplicates created</li>
                                    <li>Auto-calculates prices</li>
                                </ul>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <div class="user-selection-section">
                            <h6>Select Users to Distribute Leads</h6>
                            <p class="text-muted">Select one or more users. Leads will be randomly distributed among selected users.</p>
                            
                            <div class="user-checkboxes">
                                <?php if (!empty($users)): ?>
                                    <?php foreach ($users as $user): ?>
                                        <div class="form-check">
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
                            
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary btn-lg" id="uploadBtn" <?php echo empty($users) ? 'disabled' : ''; ?>>
                                    <i class="feather icon-upload"></i> Upload CSV & Import Leads
                                </button>
                            </div>
                        </div>
                    </form>
                <?php else: ?>
                    <?php if ($isMainAdmin == 1 && $role_id === 1): ?>
                        <div class="alert alert-info">
                            <i class="feather icon-info"></i> Please select a tenant above to begin uploading leads.
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                
            </div>
        </div>
    </div>

    <?php
    include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/footer.php');
    include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/scripts.php');
    ?>

    <script>
    // Display selected file name
    document.getElementById('csv_file')?.addEventListener('change', function(e) {
        const fileName = e.target.files[0]?.name || 'No file selected';
        document.getElementById('file-name').textContent = fileName;
    });

    // Form validation before submit
    document.getElementById('uploadForm')?.addEventListener('submit', function(e) {
        const fileInput = document.getElementById('csv_file');
        const userCheckboxes = document.querySelectorAll('input[name="users[]"]:checked');
        
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
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Uploading...';
        
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