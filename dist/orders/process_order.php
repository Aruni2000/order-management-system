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

// NEW: Session recovery logic
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

if ($user_id == 0) {
    // Try to recover user_id from session identifier if it's missing but we have a username/email
    $session_identifier = isset($_SESSION['username']) ? $_SESSION['username'] : 
                         (isset($_SESSION['email']) ? $_SESSION['email'] : '');
    
    if ($session_identifier) {
        $userQuery = "SELECT id FROM users WHERE email = ? OR name = ? LIMIT 1";
        $stmt = $conn->prepare($userQuery);
        $stmt->bind_param("ss", $session_identifier, $session_identifier);
        $stmt->execute();
        $userResult = $stmt->get_result();
        
        if ($userResult && $userResult->num_rows > 0) {
            $userData = $userResult->fetch_assoc();
            $user_id = (int)$userData['id'];
            $_SESSION['user_id'] = $user_id;
        }
        $stmt->close();
    }
}

// Function to log user actions
function logUserAction($conn, $user_id, $action_type, $inquiry_id, $details = null) {
    $stmt = $conn->prepare("INSERT INTO user_logs (user_id, action_type, inquiry_id, details) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isis", $user_id, $action_type, $inquiry_id, $details);
    return $stmt->execute();
}

/**
 * Get user-friendly FDE API status message
 * Handles both New Parcel API and Existing Parcel API status codes
 */
function getFdeStatusMessage($status_code, $api_type = 'new') {
    if ($api_type === 'existing') {
        // FDE Existing Parcel API status messages
        $existing_status_messages = [
            200 => 'Successfully insert the parcel',
            201 => 'Incorrect waybill type. Only allow CRE or CCP',
            202 => 'The waybill is used',
            203 => 'The waybill is not yet assigned',
            204 => 'Inactive Client',
            205 => 'Invalid order id',
            206 => 'Invalid weight',
            207 => 'Empty or invalid parcel description',
            208 => 'Empty or invalid name',
            209 => 'Invalid contact number 1',
            210 => 'Invalid contact number 2',
            211 => 'Empty or invalid address',
            212 => 'Empty or invalid amount (If you have CRE numbers, you can ignore or set as a 0 value to this)',
            213 => 'Invalid city',
            214 => 'Parcel insert unsuccessfully',
            215 => 'Invalid or inactive client',
            216 => 'Invalid API key',
            217 => 'Invalid exchange value',
            218 => 'System maintain mode is activated'
        ];
        
        return isset($existing_status_messages[$status_code]) ? $existing_status_messages[$status_code] : 'Unknown error occurred';
    } else {
        // FDE New Parcel API status messages (default)
        $new_status_messages = [
            200 => 'Successful insert',
            201 => 'Inactive Client',
            202 => 'Invalid order id',
            203 => 'Invalid weight',
            204 => 'Empty or invalid parcel description',
            205 => 'Empty or invalid name',
            206 => 'Contact number 1 is not valid',
            207 => 'Contact number 2 is not valid',
            208 => 'Empty or invalid address',
            209 => 'Invalid City',
            210 => 'Unsuccessful insert, try again',
            211 => 'Invalid API key',
            212 => 'Invalid or inactive client',
            213 => 'Invalid exchange value',
            214 => 'System maintain mode is activated'
        ];
        
        return isset($new_status_messages[$status_code]) ? $new_status_messages[$status_code] : 'Unknown error occurred';
    }
}

// Function to set session message and redirect
function setMessageAndRedirect($type, $message, $redirect_url = null) {
    $_SESSION["order_{$type}"] = $message;
    
    // Default redirect to create order page
    if (!$redirect_url) {
        $redirect_url = "/OMS/dist/orders/create_order.php";
    }
    
    // Clean output buffer
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Check if headers can be sent
    if (!headers_sent()) {
        header("Location: " . $redirect_url, true, 303);
        exit();
    } else {
        // Fallback: JavaScript redirect
        echo '<script type="text/javascript">';
        echo 'window.location.href="' . htmlspecialchars($redirect_url, ENT_QUOTES, 'UTF-8') . '";';
        echo '</script>';
        echo '<noscript>';
        echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirect_url, ENT_QUOTES, 'UTF-8') . '" />';
        echo '</noscript>';
        exit();
    }
}

// Check if the form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        // Validate required fields
        if (empty($_POST['customer_name'])) {
            throw new Exception("Customer name is required.");
        }
        
        // Get customer details early
        $customer_name = trim($_POST['customer_name']);
        $customer_email = trim($_POST['customer_email'] ?? '');
        $customer_phone = trim($_POST['customer_phone'] ?? '');
        $customer_phone_2 = trim($_POST['customer_phone_2'] ?? '');
        
        // Additional customer validation (optional but recommended)
        if (!empty($customer_email) && !filter_var($customer_email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email format.");
        }
        
        if (!empty($customer_phone) && !preg_match('/^[0-9+\-\s()]+$/', $customer_phone)) {
            throw new Exception("Invalid phone number format.");
        }

        // Check if products are added
        if (empty($_POST['order_product'])) {
            throw new Exception("At least one product must be added to the order.");
        }

        // Validate that at least one product is selected (not empty)
        $valid_products = array_filter($_POST['order_product'], function($product_id) {
            return !empty($product_id);
        });
        
        if (empty($valid_products)) {
            throw new Exception("Please select at least one valid product for the order.");
        }

        // Begin transaction
        $conn->begin_transaction();
        
        // ==========================================
        // CRITICAL FIX: Get tenant_id from POST (not session)
        // This ensures we use the selected tenant from the form
        // ==========================================

        // Get tenant_id from POST data (this is what user selected in the form)
        $tenant_id = isset($_POST['tenant_id']) ? intval($_POST['tenant_id']) : ($_SESSION['tenant_id'] ?? 1);

        error_log("DEBUG - POST tenant_id: " . ($_POST['tenant_id'] ?? 'NOT_SET'));
        error_log("DEBUG - Session tenant_id: " . ($_SESSION['tenant_id'] ?? 'NOT_SET'));
        error_log("DEBUG - Final tenant_id being used: $tenant_id");
        error_log("DEBUG - User ID: $user_id");
        
        // Handle address fields according to actual database schema
        $address_line1 = trim($_POST['address_line1'] ?? '');
        $address_line2 = trim($_POST['address_line2'] ?? '');
        $city_id = !empty($_POST['city_id']) ? intval($_POST['city_id']) : null;
        
        // Debug log for city_id from POST
        error_log("DEBUG - POST city_id: " . ($_POST['city_id'] ?? 'NOT_SET'));
        error_log("DEBUG - Processed city_id: " . ($city_id ?? 'NULL'));
        
        // ==========================================
        // CUSTOMER MATCHING LOGIC - FIXED VERSION
        // Business Rules:
        // 1. Match by email OR phone (unique identifiers)
        // 2. If match found: UPDATE existing customer
        // 3. If NO match: CREATE new customer (even if name is same)
        // 4. Multiple customers CAN have the same name
        // ==========================================
        
                
            $customer_id = 0;
            $existing_customer = null;
            $is_new_customer = true;
            $matched_by_phone = false; // Track if we found customer by phone

            // STEP 1: Check if phone exists in BOTH phone and phone_2 columns
            if (!empty($customer_phone)) {
                
                // Search for phone in BOTH phone and phone_2 columns
            $checkPhoneSql = "SELECT customer_id, name, email, phone, phone_2 
                            FROM customers 
                            WHERE (phone = ? OR phone_2 = ?) 
                            AND tenant_id = ?
                            AND status = 'Active'
                            LIMIT 1";

            $stmt = $conn->prepare($checkPhoneSql);

            if (!$stmt) {
                throw new Exception("Failed to prepare phone check query: " . $conn->error);
            }

            $stmt->bind_param("ssi", $customer_phone, $customer_phone, $tenant_id); // UPDATED: Added tenant_id
                
                if (!$stmt->execute()) {
                    throw new Exception("Failed to execute phone check: " . $stmt->error);
                }
                
                $result = $stmt->get_result();
                
                // If phone found in database
                if ($result->num_rows > 0) {
                    $existing_customer = $result->fetch_assoc();
                    $customer_id = $existing_customer['customer_id'];
                    $is_new_customer = false;
                    $matched_by_phone = true;
                    
                    // Log which column matched
                    $matched_column = ($existing_customer['phone'] === $customer_phone) ? 'phone' : 'phone_2';
                    error_log("DEBUG - Phone found in customer_id: $customer_id (matched in column: $matched_column)");
                    error_log("DEBUG - Using existing customer_id WITHOUT updating customer table");
                    error_log("DEBUG - Order_header will use FORM data, not customer table data");
                } else {
                    error_log("DEBUG - Phone not found in database");
                }
                
                $stmt->close();
            }

            // STEP 2: If phone not found, check email (optional - frontend should prevent duplicate email)
    //         if ($is_new_customer && !empty($customer_email)) {
                
    //             $checkEmailSql = "SELECT customer_id, name, email, phone, phone_2 
    //                 FROM customers 
    //                 WHERE email = ? 
    //                 AND tenant_id = ?
    //                 AND status = 'Active'
    //                 LIMIT 1";

    // $stmt = $conn->prepare($checkEmailSql);

    // if (!$stmt) {
    //     throw new Exception("Failed to prepare email check query: " . $conn->error);
    // }

    // $stmt->bind_param("si", $customer_email, $tenant_id); // UPDATED: Added tenant_id
                
    //             if (!$stmt->execute()) {
    //                 throw new Exception("Failed to execute email check: " . $stmt->error);
    //             }
                
    //             $result = $stmt->get_result();
                
    //             if ($result->num_rows > 0) {
    //                 $existing_customer = $result->fetch_assoc();
    //                 $customer_id = $existing_customer['customer_id'];
    //                 $is_new_customer = false;
    //                 error_log("DEBUG - Email found in customer_id: $customer_id (using existing customer)");
    //             }
                
    //             $stmt->close();
    //         }

            // ==========================================
            // STEP 3: CREATE NEW CUSTOMER (Only if both phone and email are new)
            // ==========================================

            if ($is_new_customer) {
                error_log("DEBUG - Creating new customer: $customer_name");
                
                // Validate required fields for new customer
                if (empty($customer_name)) {
                    throw new Exception("Customer name is required for new customer");
                }
                
                if (empty($customer_phone)) {
                    throw new Exception("Phone number is required for new customer");
                }
                
              // Insert new customer WITH tenant_id
                $insertCustomerSql = "INSERT INTO customers 
                    (tenant_id, name, email, phone, phone_2, address_line1, address_line2, city_id, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active')";

                $stmt = $conn->prepare($insertCustomerSql);

                if (!$stmt) {
                    throw new Exception("Failed to prepare customer insert query: " . $conn->error);
                }

                // Handle nullable fields properly
                $email_value = !empty($customer_email) ? $customer_email : null;
                $phone2_value = !empty($customer_phone_2) ? $customer_phone_2 : null;
                $address1_value = !empty($address_line1) ? $address_line1 : null;
                $address2_value = !empty($address_line2) ? $address_line2 : null;
                $city_id_value = !empty($city_id) ? $city_id : null;

                $stmt->bind_param(
                    "issssssi",          // UPDATED: Added 'i' for tenant_id at the beginning
                    $tenant_id,          // NEW: First parameter is tenant_id
                    $customer_name, 
                    $email_value, 
                    $customer_phone,
                    $phone2_value,
                    $address1_value, 
                    $address2_value, 
                    $city_id_value
                );
                
                if (!$stmt->execute()) {
                    throw new Exception("Failed to insert new customer: " . $stmt->error);
                }
                
                $customer_id = $conn->insert_id;
                
                if (empty($customer_id) || $customer_id <= 0) {
                    throw new Exception("Failed to get customer ID after insert");
                }
                
                error_log("DEBUG - New customer created (ID: $customer_id)");
                $stmt->close();
            }

            // ==========================================
            // STEP 4: VALIDATE CUSTOMER ID
            // ==========================================

            if (empty($customer_id) || $customer_id <= 0) {
                throw new Exception("Invalid customer ID");
            }

            error_log("DEBUG - Final customer_id: $customer_id");
            error_log("DEBUG - Is new customer: " . ($is_new_customer ? 'YES' : 'NO'));
            error_log("DEBUG - Matched by phone: " . ($matched_by_phone ? 'YES' : 'NO'));

            // ==========================================
            // STEP 5: GET ZONE_ID AND DISTRICT_ID FROM CITY_TABLE
            // We need these for order_header
            // Important: We get these from city_table, not customer table
            // ==========================================

            $zone_id = null;
            $district_id = null;

            // Ensure we have a city_id to work with
            if (empty($city_id)) {
                // Fallback: Try to get city_id from customer record
                $getCustomerCitySql = "SELECT city_id FROM customers WHERE customer_id = ?";
                $customerCityStmt = $conn->prepare($getCustomerCitySql);
                
                if ($customerCityStmt) {
                    $customerCityStmt->bind_param("i", $customer_id);
                    $customerCityStmt->execute();
                    $customerCityResult = $customerCityStmt->get_result();
                    
                    if ($customerCityResult && $customerCityResult->num_rows > 0) {
                        $customerCityData = $customerCityResult->fetch_assoc();
                        $city_id = $customerCityData['city_id'];
                        error_log("DEBUG - Retrieved city_id from customer record: " . ($city_id ?? 'NULL'));
                    }
                    $customerCityStmt->close();
                }
            }

            // Get zone_id and district_id from city_table
            if (!empty($city_id)) {
                $getCityDataSql = "SELECT zone_id, district_id, city_name FROM city_table WHERE city_id = ?";
                $cityStmt = $conn->prepare($getCityDataSql);
                
                if ($cityStmt) {
                    $cityStmt->bind_param("i", $city_id);
                    $cityStmt->execute();
                    $cityResult = $cityStmt->get_result();
                    
                    if ($cityResult && $cityResult->num_rows > 0) {
                        $cityData = $cityResult->fetch_assoc();
                        $zone_id = $cityData['zone_id'];
                        $district_id = $cityData['district_id'];
                        error_log("DEBUG - Retrieved from city_table - City: " . $cityData['city_name'] . ", Zone ID: $zone_id, District ID: $district_id");
                    }
                    $cityStmt->close();
                }
            }

            // ==========================================
            // STEP 6: PREPARE ORDER_HEADER DATA
            // CRITICAL: ALWAYS use form data directly, NEVER customer table data
            // This ensures order_header has the exact data user entered in the form
            // ==========================================

            // Use form data directly - DO NOT fallback to customer table
            $final_full_name = trim($customer_name);
            $final_email = !empty($customer_email) ? trim($customer_email) : null;
            $final_mobile = trim($customer_phone);
            $final_mobile_2 = !empty($customer_phone_2) ? trim($customer_phone_2) : null;
            $final_address_line1 = !empty($address_line1) ? trim($address_line1) : null;
            $final_address_line2 = !empty($address_line2) ? trim($address_line2) : null;
            $final_city_id = !empty($city_id) ? $city_id : null;
            $final_zone_id = $zone_id;
            $final_district_id = $district_id;

            // Validation: Ensure critical fields are not empty
            if (empty($final_full_name)) {
                throw new Exception("Customer name is required for order creation");
            }

            if (empty($final_mobile)) {
                throw new Exception("Customer mobile number is required for order creation");
            }

            if (empty($final_city_id)) {
                throw new Exception("Customer city is required for order creation");
            }

            // ==========================================
            // DETAILED LOGGING FOR DEBUGGING
            // ==========================================
            error_log("========================================");
            error_log("DEBUG - Customer Processing Summary:");
            error_log("========================================");
            error_log("Customer Matching:");
            error_log("  - Customer ID: $customer_id");
            error_log("  - New Customer: " . ($is_new_customer ? 'YES' : 'NO'));
            error_log("  - Matched by Phone: " . ($matched_by_phone ? 'YES' : 'NO'));

            if ($matched_by_phone) {
                error_log("  - Action Taken: Used existing customer_id, NO customer table update");
                error_log("  - Customer table preserved as-is");
            } else if ($is_new_customer) {
                error_log("  - Action Taken: Created new customer record");
            }

            error_log("");
            error_log("Order Header Data (ALL FROM FORM):");
            error_log("  - Tenant ID: $tenant_id");
            error_log("  - Full Name: $final_full_name");
            error_log("  - Mobile: $final_mobile");
            error_log("  - Mobile 2: " . ($final_mobile_2 ?? 'NULL'));
            error_log("  - Address Line 1: " . ($final_address_line1 ?? 'NULL'));
            error_log("  - Address Line 2: " . ($final_address_line2 ?? 'NULL'));
            error_log("  - City ID: $final_city_id");
            error_log("  - Zone ID: " . ($final_zone_id ?? 'NULL'));
            error_log("  - District ID: " . ($final_district_id ?? 'NULL'));
            error_log("========================================");

            // ==========================================
            // CONTINUE WITH ORDER CREATION
            // (Your existing order creation code continues here...)
            // ==========================================

            // Prepare order details
            $order_date = $_POST['order_date'] ?? date('Y-m-d');
            $due_date = $_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days'));
            $notes = $_POST['notes'] ?? "";
            $currency = isset($_POST['order_currency']) ? strtolower($_POST['order_currency']) : 'lkr';
            $order_status = $_POST['order_status'] ?? 'Unpaid';
            $pay_status = $order_status === 'Paid' ? 'paid' : 'unpaid';
            $pay_date = $order_status === 'Paid' ? date('Y-m-d') : null;
            $status = 'pending';

         // Product processing with quantity
// Product processing with quantity - FIXED VERSION
$products = $_POST['order_product'];
$product_prices = $_POST['order_product_price'];
$product_quantities = $_POST['order_product_quantity'] ?? [];
$discounts = $_POST['order_product_discount'] ?? [];
$product_descriptions = $_POST['order_product_description'] ?? [];

$subtotal_before_discounts = 0;
$total_discount = 0;
$delivery_fee = isset($_POST['delivery_fee']) ? floatval($_POST['delivery_fee']) : 0.00;
$product_codes = [];
$order_items = [];

foreach ($products as $key => $product_id) {
    if (empty($product_id)) continue;
    
    $original_price = floatval($product_prices[$key] ?? 0);
    $quantity = intval($product_quantities[$key] ?? 1);
    $discount = floatval($discounts[$key] ?? 0); // This is TOTAL discount for all items in this row
    $description = $product_descriptions[$key] ?? '';
    
    // Ensure quantity is at least 1
    if ($quantity < 1) {
        $quantity = 1;
    }
    
    // Calculate total price for this row
    $row_total_price = $original_price * $quantity;
    
    // ✅ FIXED: Discount should not exceed total price for this row
    if ($discount > $row_total_price) {
        $discount = $row_total_price;
    }
    
    // ✅ FIXED: Calculate subtotal and discount correctly
    $subtotal_before_discounts += $row_total_price;
    $total_discount += $discount; // Don't multiply by quantity - discount is already total!
    $product_codes[] = $product_id;
    
    $order_items[] = [
        'product_id' => $product_id,
        'quantity' => $quantity,
        'original_price' => $original_price,
        'discount' => $discount, // This is the TOTAL discount for this row
        'description' => $description
    ];
}
            if (empty($order_items)) {
                throw new Exception("No valid products to process in the order.");
            }
$final_product_code = implode(',', $product_codes);
$subtotal_after_discount = $subtotal_before_discounts - $total_discount;

// UPDATED: Always use the delivery fee from POST data (no free delivery logic)
// Delivery fee is already set from the form submission
$total_amount = $subtotal_after_discount + $delivery_fee;


// The "subtotal" column in order_header should be the subtotal AFTER discount
$order_subtotal = $subtotal_after_discount; // This is what we'll insert into the database

// Optional: Log for debugging
error_log("DEBUG - Subtotal before discount: Rs. $subtotal_before_discounts");
error_log("DEBUG - Total discount: Rs. $total_discount");
error_log("DEBUG - Order subtotal (after discount): Rs. $order_subtotal");
error_log("DEBUG - Delivery fee applied: Rs. $delivery_fee");
error_log("DEBUG - Total amount: Rs. $total_amount");

            // ==========================================
            // FIXED: INSERT ORDER_HEADER WITH TENANT_ID
            // ==========================================
            $insertOrderSql = "INSERT INTO order_header (
                tenant_id, customer_id, user_id, co_id, issue_date, due_date, 
                subtotal, discount, total_amount, delivery_fee,
                notes, currency, status, pay_status, pay_date, created_by,
                product_code, full_name, email, mobile, mobile_2,
                address_line1, address_line2, city_id, zone_id, district_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $conn->prepare($insertOrderSql);

            if (!$stmt) {
                throw new Exception("Failed to prepare order header insert query: " . $conn->error);
            }
$stmt->bind_param(
    "iiiissddddsssssisssssssiii", 
    $tenant_id,                     // 1.  tenant_id
    $customer_id,                   // 2.  customer_id
    $user_id,                       // 3.  user_id
    $co_id,                         // 4.  co_id
    $order_date,                    // 5.  issue_date
    $due_date,                      // 6.  due_date
    $order_subtotal,                // 7.  subtotal 
    $total_discount,                // 8.  discount
    $total_amount,                  // 9.  total_amount
    $delivery_fee,                  // 10. delivery_fee
    $notes,                         // 11. notes
    $currency,                      // 12. currency
    $status,                        // 13. status
    $pay_status,                    // 14. pay_status
    $pay_date,                      // 15. pay_date
    $user_id,                       // 16. created_by
    $final_product_code,            // 17. product_code
    $final_full_name,               // 18. full_name
    $final_email,                   // 19. email
    $final_mobile,                  // 20. mobile
    $final_mobile_2,                // 21. mobile_2
    $final_address_line1,           // 22. address_line1
    $final_address_line2,           // 23. address_line2
    $final_city_id,                 // 24. city_id
    $final_zone_id,                 // 25. zone_id
    $final_district_id              // 26. district_id
);

    if (!$stmt->execute()) {
        throw new Exception("Failed to insert order header: " . $stmt->error);
    }

    $order_id = $conn->insert_id;
    $stmt->close();

    if (empty($order_id) || $order_id <= 0) {
        throw new Exception("Failed to create order ID.");
    }

    // Success log
    error_log("========================================");
    error_log(" ORDER CREATED SUCCESSFULLY!");
    error_log("========================================");
    error_log("  - Order ID: $order_id");
    error_log("  - Tenant ID: $tenant_id");
    error_log("  - Customer ID: $customer_id");
    error_log("  - Customer Name: $final_full_name");
    error_log("  - Customer Email: " . ($final_email ?? 'NULL'));
    error_log("  - Product Code: $final_product_code");
    error_log("  - Total Amount: Rs. $total_amount (Delivery: Rs. $delivery_fee)");
    error_log("========================================");

            
       // Order items insertion with quantity
// Order items insertion with quantity - FIXED VERSION
$insertItemSql = "INSERT INTO order_items (
    order_id, product_id, quantity, unit_price, discount, 
    total_amount, pay_status, status, description
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

$stmt = $conn->prepare($insertItemSql);

if (!$stmt) {
    throw new Exception("Failed to prepare order items insert query: " . $conn->error);
}

foreach ($order_items as $item) {
    // ✅ FIXED: Calculate total_amount: (unit_price × quantity) - total_discount
    $row_total_price = $item['original_price'] * $item['quantity'];
    $item_total = $row_total_price - $item['discount']; // Discount is already total for this row
    
    $stmt->bind_param(
        "iiidissss",
        $order_id,                      // order_id
        $item['product_id'],            // product_id
        $item['quantity'],              // quantity
        $item['original_price'],        // unit_price
        $item['discount'],              // discount (total discount for this row)
        $item_total,                    // total_amount
        $pay_status,                    // pay_status
        $status,                        // status
        $item['description']            // description
    );
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to insert order item: " . $stmt->error);
    }
}


        // COURIER AND TRACKING ASSIGNMENT WITH ENHANCED FDE API
        // Get default courier (can be is_default = 1, 2, or 3)
            // Get default courier for THIS TENANT with co_id
            $getDefaultCourierSql = "SELECT co_id, courier_id, courier_name, api_key, client_id, 
                                    origin_city_name, origin_state_name, is_default 
                                    FROM couriers 
                                    WHERE tenant_id = ? 
                                    AND is_default IN (1, 2, 3) 
                                    AND status = 'active' 
                                    ORDER BY is_default ASC 
                                    LIMIT 1";

            $courierStmt = $conn->prepare($getDefaultCourierSql);
            $courierStmt->bind_param("i", $tenant_id);
            $courierStmt->execute();
            $courierResult = $courierStmt->get_result();

            $tracking_assigned = false;
            $courier_warning = '';
            $co_id = null; // Initialize co_id

            if ($courierResult && $courierResult->num_rows > 0) {
                $defaultCourier = $courierResult->fetch_assoc();
                
                // ✅ CRITICAL: Store BOTH IDs
                $co_id = $defaultCourier['co_id'];                    // Primary key from couriers table
                $default_courier_id = $defaultCourier['courier_id'];  // Courier service ID (11, 12, 13, 14)
                
                $courier_type = $defaultCourier['is_default'];
                $api_key = $defaultCourier['api_key'];
                $client_id = $defaultCourier['client_id'];
                $courier_name = $defaultCourier['courier_name'];
                $origin_city_name = $defaultCourier['origin_city_name'];
                $origin_state_name = $defaultCourier['origin_state_name'];
                        
            // Fardar API start here
            if ($default_courier_id == 11) {
                if ($courier_type == 1) {
                    // INTERNAL TRACKING SYSTEM
                    // Get an unused tracking number for this courier
                    $getTrackingSql = "SELECT tracking_id FROM tracking WHERE courier_id = ? AND status = 'unused' LIMIT 1";
                    $trackingStmt = $conn->prepare($getTrackingSql);
                    $trackingStmt->bind_param("i", $default_courier_id);
                    $trackingStmt->execute();
                    $trackingResult = $trackingStmt->get_result();
                    
                    if ($trackingResult && $trackingResult->num_rows > 0) {
                        $trackingData = $trackingResult->fetch_assoc();
                        $tracking_number = $trackingData['tracking_id'];
                        
                        // Update the tracking record to 'used'
                        $updateTrackingSql = "UPDATE tracking SET status = 'used' WHERE tracking_id = ? AND courier_id = ?";
                        $updateTrackingStmt = $conn->prepare($updateTrackingSql);
                        $updateTrackingStmt->bind_param("si", $tracking_number, $default_courier_id);
                        $updateTrackingStmt->execute();
                        
                        // Update order_header with courier info and set status to 'dispatch'
                       $updateOrderHeaderSql = "UPDATE order_header SET 
                        co_id = ?,
                        courier_id = ?, 
                        tracking_number = ?, 
                        status = 'dispatch' 
                        WHERE order_id = ?";
                    $updateOrderStmt = $conn->prepare($updateOrderHeaderSql);
                    $updateOrderStmt->bind_param("iisi", $co_id, $default_courier_id, $tracking_number, $order_id);


                         $updateOrderStmt->execute();
                        $updateOrderStmt->close();
                        
                        // Update all order_items status to 'dispatch'
                        $updateOrderItemsSql = "UPDATE order_items SET status = 'dispatch' WHERE order_id = ?";
                        $updateItemsStmt = $conn->prepare($updateOrderItemsSql);
                        $updateItemsStmt->bind_param("i", $order_id);
                        $updateItemsStmt->execute();
                        
                        // Update the main status variable for later use
                        $status = 'dispatch';
                        $tracking_assigned = true;
                        
                    } else {
                        $courier_warning = "No unused tracking numbers available for {$courier_name}";
                    }
                    
                } elseif ($courier_type == 2) {
                    // FDE NEW PARCEL API INTEGRATION
                    
                    // Include the FDE API function
                    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/api/fde_new_parcel_api.php');
                    
                    // CITY HANDLING
                    $city_name = '';
                    $proceed_with_api = false;
                    
                    // Debug log before city processing
                    error_log("DEBUG - About to process city for FDE New API - city_id: " . ($city_id ?? 'NULL'));
                    
                    if (!empty($city_id)) {
                        $getCityNameSql = "SELECT city_name FROM city_table WHERE city_id = ? AND is_active = 1";
                        $cityStmt = $conn->prepare($getCityNameSql);
                        $cityStmt->bind_param("i", $city_id);
                        $cityStmt->execute();
                        $cityResult = $cityStmt->get_result();
                        
                        if ($cityResult && $cityResult->num_rows > 0) {
                            $cityData = $cityResult->fetch_assoc();
                            $city_name = $cityData['city_name'];
                            $proceed_with_api = true; // Valid city found, proceed with API
                            
                            error_log("DEBUG - City found: " . $city_name);
                        } else {
                            $city_name = 'Unknown City'; // Fallback if city not found
                            $proceed_with_api = false; // Don't proceed with API
                            
                            error_log("DEBUG - City ID exists but city not found in database");
                        }
                    } else {
                        $city_name = 'City Not Specified'; // Fallback if city_id is empty
                        $proceed_with_api = false; // Don't proceed with API
                        
                        error_log("DEBUG - No city_id provided");
                    }
                    
                    // Only proceed with API call if we have a valid city
                    if ($proceed_with_api) {
                        // Prepare data for FDE New Parcel API
                        $parcel_weight = '1'; // Default weight
                        $parcel_description = 'Order #' . $order_id . ' - ' . count($order_items) . ' items';
                        
                        // Calculate API amount based on payment status
                        // If order is marked as 'Paid', send 0 to API, otherwise send total_amount
                        $api_amount = ($order_status === 'Paid') ? 0 : $total_amount;
                        
                        // Use customer data for API call
                        $fde_api_data = array(
                            'api_key' => $api_key,
                            'client_id' => $client_id,
                            'order_id' => $order_id,
                            'parcel_weight' => $parcel_weight,
                            'parcel_description' => $parcel_description,
                            'recipient_name' => $customer_name,
                            'recipient_contact_1' => $customer_phone,
                            'recipient_contact_2' => !empty($customer_phone_2) ? $customer_phone_2 : '',
                            'recipient_address' => trim($address_line1 . ' ' . $address_line2),
                            'recipient_city' => $city_name,
                            'amount' => $api_amount,
                            'exchange' => '0'
                        );
                        
                        // Call FDE New Parcel API
                        $fde_response = callFdeApi($fde_api_data);
                        
                        // Parse FDE response - handle both JSON and error responses
                        $fde_result = null;
                        
                        // Check if response starts with "Curl error:"
                        if (strpos($fde_response, 'Curl error:') === 0) {
                            // cURL error occurred
                            $fde_result = [
                                'success' => false,
                                'error' => $fde_response
                            ];
                        } else {
                            // Try to decode JSON response
                            $fde_result = json_decode($fde_response, true);
                            
                            // If JSON decode failed, treat as error
                            if (json_last_error() !== JSON_ERROR_NONE) {
                                $fde_result = [
                                    'success' => false,
                                    'error' => 'Invalid JSON response',
                                    'raw_response' => $fde_response
                                ];
                            }
                        }
                        
                        // Check for successful API response
                        if ($fde_result && (
                            (isset($fde_result['status']) && $fde_result['status'] == 200) ||
                            (isset($fde_result['success']) && $fde_result['success'] == true) ||
                            (isset($fde_result['waybill_no']) && !empty($fde_result['waybill_no']))
                        )) {
                            // API call successful
                            $tracking_number = $fde_result['waybill_no'] ?? ($fde_result['tracking_number'] ?? 'FDE' . $order_id);
                            
                            // Update order_header with courier info and set status to 'dispatch'
                           $updateOrderHeaderSql = "UPDATE order_header SET 
                            co_id = ?,
                            courier_id = ?, 
                            tracking_number = ?, 
                            status = 'dispatch'
                            WHERE order_id = ?";
                        $updateOrderStmt = $conn->prepare($updateOrderHeaderSql);
                        $updateOrderStmt->bind_param("iisi", $co_id, $default_courier_id, $tracking_number, $order_id);
                            $updateOrderStmt->execute();
                            
                            // Update all order_items status to 'dispatch'
                            $updateOrderItemsSql = "UPDATE order_items SET status = 'dispatch' WHERE order_id = ?";
                            $updateItemsStmt = $conn->prepare($updateOrderItemsSql);
                            $updateItemsStmt->bind_param("i", $order_id);
                            $updateItemsStmt->execute();
                            
                            // Update the main status variable for later use
                            $status = 'dispatch';
                            $tracking_assigned = true;
                            
                        } else {
                            // FDE API call failed - Enhanced error handling
                            $error_status_code = null;
                            $error_message = 'Unknown error occurred';
                            
                            // Extract status code and get user-friendly message
                            if (isset($fde_result['status'])) {
                                $error_status_code = $fde_result['status'];
                                $error_message = getFdeStatusMessage($error_status_code, 'new'); // Updated with api_type
                            } elseif (isset($fde_result['error'])) {
                                $error_message = $fde_result['error'];
                            } elseif (strpos($fde_response, 'Curl error:') === 0) {
                                $error_message = 'Network connection error - Please check internet connectivity';
                            }
                            
                            // UPDATED: Set user-friendly error message for session
                            $courier_warning = $error_message; // This will show "Invalid City", "Invalid Phone", etc.
                        }
                        
                    } else {
                        // City not found or not specified - don't call API
                        $city_error_reason = empty($city_id) ? 'City not specified in delivery address' : 'Invalid city selected';
                        $courier_warning = $city_error_reason; // UPDATED: Remove extra text, just show the reason
                    }
                    
                } elseif ($courier_type == 3) {
                    // FDE EXISTING PARCEL API INTEGRATION
                    
                    // Include the FDE Existing Parcel API function
                    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/api/fde_existing_parcel_api.php');
                    
                    // CITY HANDLING
                    $city_name = '';
                    $proceed_with_api = false;
                    
                    // Debug log before city processing
                    error_log("DEBUG - About to process city for FDE Existing API - city_id: " . ($city_id ?? 'NULL'));
                    
                    if (!empty($city_id)) {
                        $getCityNameSql = "SELECT city_name FROM city_table WHERE city_id = ? AND is_active = 1";
                        $cityStmt = $conn->prepare($getCityNameSql);
                        $cityStmt->bind_param("i", $city_id);
                        $cityStmt->execute();
                        $cityResult = $cityStmt->get_result();
                        
                        if ($cityResult && $cityResult->num_rows > 0) {
                            $cityData = $cityResult->fetch_assoc();
                            $city_name = $cityData['city_name'];
                            $proceed_with_api = true; // Valid city found, proceed with API
                            
                            error_log("DEBUG - City found: " . $city_name);
                        } else {
                            $city_name = 'Unknown City'; // Fallback if city not found
                            $proceed_with_api = false; // Don't proceed with API
                            
                            error_log("DEBUG - City ID exists but city not found in database");
                        }
                    } else {
                        $city_name = 'City Not Specified'; // Fallback if city_id is empty
                        $proceed_with_api = false; // Don't proceed with API
                        
                        error_log("DEBUG - No city_id provided");
                    }
                    
                    // Only proceed with API call if we have a valid city
                    if ($proceed_with_api) {
                        // Get unused tracking ID for existing parcel API
                        $getTrackingSql = "SELECT tracking_id FROM tracking WHERE courier_id = ? AND status = 'unused' LIMIT 1";
                        $trackingStmt = $conn->prepare($getTrackingSql);
                        $trackingStmt->bind_param("i", $default_courier_id);
                        $trackingStmt->execute();
                        $trackingResult = $trackingStmt->get_result();
                        
                        if ($trackingResult && $trackingResult->num_rows > 0) {
                            $trackingData = $trackingResult->fetch_assoc();
                            $tracking_id = $trackingData['tracking_id'];
                            
                            // Prepare data for FDE Existing Parcel API
                            $parcel_weight = '1'; // Default weight
                            $parcel_description = 'Order #' . $order_id . ' - ' . count($order_items) . ' items';
                            
                            // Calculate API amount based on payment status
                            // If order is marked as 'Paid', send 0 to API, otherwise send total_amount
                            $api_amount = ($order_status === 'Paid') ? 0 : $total_amount;
                            
                            // Use customer data for API call
                            $fde_existing_api_data = array(
                                'api_key' => $api_key,
                                'client_id' => $client_id,
                                'waybill_id' => $tracking_id, // Using tracking_id as waybill_id for the API
                                'order_id' => $order_id,
                                'parcel_weight' => $parcel_weight,
                                'parcel_description' => $parcel_description,
                                'recipient_name' => $customer_name,
                                'recipient_contact_1' => $customer_phone,
                                'recipient_contact_2' => !empty($customer_phone_2) ? $customer_phone_2 : '',
                                'recipient_address' => trim($address_line1 . ' ' . $address_line2),
                                'recipient_city' => $city_name,
                                'amount' => $api_amount,
                                'exchange' => '0'
                            );
                            
                            // Call FDE Existing Parcel API
                            $fde_existing_response = callFdeExistingParcelApi($fde_existing_api_data);
                            
                            // Parse FDE response - handle both JSON and error responses
                            $fde_existing_result = null;
                            
                            // Check if response starts with "Curl error:"
                            if (strpos($fde_existing_response, 'Curl error:') === 0) {
                                // cURL error occurred
                                $fde_existing_result = [
                                    'success' => false,
                                    'error' => $fde_existing_response
                                ];
                            } else {
                                // Try to decode JSON response
                                $fde_existing_result = json_decode($fde_existing_response, true);
                                
                                // If JSON decode failed, treat as error
                                if (json_last_error() !== JSON_ERROR_NONE) {
                                    $fde_existing_result = [
                                        'success' => false,
                                        'error' => 'Invalid JSON response',
                                        'raw_response' => $fde_existing_response
                                    ];
                                }
                            }
                            
                            // Check for successful API response
                            if ($fde_existing_result && (
                                (isset($fde_existing_result['status']) && $fde_existing_result['status'] == 200) ||
                                (isset($fde_existing_result['success']) && $fde_existing_result['success'] == true) ||
                                (isset($fde_existing_result['waybill_no']) && !empty($fde_existing_result['waybill_no']))
                            )) {
                                // API call successful
                                $tracking_number = $fde_existing_result['waybill_no'] ?? ($fde_existing_result['tracking_number'] ?? $tracking_id);
                                
                                // Update the tracking record to 'used'
                                $updateTrackingSql = "UPDATE tracking SET status = 'used', updated_at = CURRENT_TIMESTAMP WHERE tracking_id = ? AND courier_id = ?";
                                $updateTrackingStmt = $conn->prepare($updateTrackingSql);
                                $updateTrackingStmt->bind_param("si", $tracking_id, $default_courier_id);
                                $updateTrackingStmt->execute();
                                
                                // Update order_header with courier info and set status to 'dispatch'
                               $updateOrderHeaderSql = "UPDATE order_header SET 
                            co_id = ?,
                            courier_id = ?, 
                            tracking_number = ?, 
                            status = 'dispatch'
                            WHERE order_id = ?";
                        $updateOrderStmt = $conn->prepare($updateOrderHeaderSql);
                        $updateOrderStmt->bind_param("iisi", $co_id, $default_courier_id, $tracking_number, $order_id);

                                $updateOrderStmt->execute();
                                
                                // Update all order_items status to 'dispatch'
                                $updateOrderItemsSql = "UPDATE order_items SET status = 'dispatch' WHERE order_id = ?";
                                $updateItemsStmt = $conn->prepare($updateOrderItemsSql);
                                $updateItemsStmt->bind_param("i", $order_id);
                                $updateItemsStmt->execute();
                                
                                // Update the main status variable for later use
                                $status = 'dispatch';
                                $tracking_assigned = true;
                                
                            } else {
                                // FDE Existing API call failed - Enhanced error handling
                                $error_status_code = null;
                                $error_message = 'Invalid API key';
                                
                                // Extract status code and get user-friendly message
                                if (isset($fde_existing_result['status'])) {
                                    $error_status_code = $fde_existing_result['status'];
                                    $error_message = getFdeStatusMessage($error_status_code, 'existing'); // Updated with api_type
                                } elseif (isset($fde_existing_result['error'])) {
                                    $error_message = $fde_existing_result['error'];
                                } elseif (strpos($fde_existing_response, 'Curl error:') === 0) {
                                    $error_message = 'Network connection error - Please check internet connectivity';
                                }
                                
                                // UPDATED: Set user-friendly error message for session
                                $courier_warning = $error_message; // This will show "Invalid City", "Invalid Phone", etc.
                            }
                            
                        } else {
                            // No unused tracking IDs available
                            $courier_warning = "No unused tracking IDs available for {$courier_name}";
                        }
                        
                    } else {
                        // City not found or not specified - don't call API
                        $city_error_reason = empty($city_id) ? 'City not specified in delivery address' : 'Invalid city selected';
                        $courier_warning = $city_error_reason; // UPDATED: Remove extra text, just show the reason
                    }
                }
                // Fardar API end here
                
            } elseif ($default_courier_id == 12) {
                // Koombiyo API start here
                if ($courier_type == 1) {
                    // INTERNAL TRACKING SYSTEM for Koombiyo
                    // Get an unused tracking number for this courier
                    $getTrackingSql = "SELECT tracking_id FROM tracking WHERE courier_id = ? AND status = 'unused' LIMIT 1";
                    $trackingStmt = $conn->prepare($getTrackingSql);
                    $trackingStmt->bind_param("i", $default_courier_id);
                    $trackingStmt->execute();
                    $trackingResult = $trackingStmt->get_result();
                    
                    if ($trackingResult && $trackingResult->num_rows > 0) {
                        $trackingData = $trackingResult->fetch_assoc();
                        $tracking_number = $trackingData['tracking_id'];
                        
                        // Update the tracking record to 'used'
                        $updateTrackingSql = "UPDATE tracking SET status = 'used' WHERE tracking_id = ? AND courier_id = ?";
                        $updateTrackingStmt = $conn->prepare($updateTrackingSql);
                        $updateTrackingStmt->bind_param("si", $tracking_number, $default_courier_id);
                        $updateTrackingStmt->execute();
                        
                        // Update order_header with courier info and set status to 'dispatch'
                       $updateOrderHeaderSql = "UPDATE order_header SET 
    co_id = ?,
    courier_id = ?, 
    tracking_number = ?, 
    status = 'dispatch'
    WHERE order_id = ?";
$updateOrderStmt = $conn->prepare($updateOrderHeaderSql);
$updateOrderStmt->bind_param("iisi", $co_id, $default_courier_id, $tracking_number, $order_id);

                        $updateOrderStmt->execute();
                        
                        // Update all order_items status to 'dispatch'
                        $updateOrderItemsSql = "UPDATE order_items SET status = 'dispatch' WHERE order_id = ?";
                        $updateItemsStmt = $conn->prepare($updateOrderItemsSql);
                        $updateItemsStmt->bind_param("i", $order_id);
                        $updateItemsStmt->execute();
                        
                        // Update the main status variable for later use
                        $status = 'dispatch';
                        $tracking_assigned = true;
                        
                    } else {
                        $courier_warning = "No unused tracking numbers available for {$courier_name}";
                    }
                    
                } elseif ($courier_type == 2) {
                    // KOOMBIYO NEW PARCEL API INTEGRATION (placeholder for future implementation)
                    $courier_warning = "Koombiyo New Parcel API not yet implemented";
                    
                } elseif ($courier_type == 3) {
                            // KOOMBIYO API INTEGRATION
                            
                            // Initialize error tracking
                            $api_errors = [];
                            $courier_warning = '';
                            
                            // Validate API file and function
                            $api_file_path = $_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/api/koombiyo_delivery_api.php';
                            
                            if (!file_exists($api_file_path)) {
                                $courier_warning = "Koombiyo API configuration error. Please contact support.";
                                error_log("Koombiyo API Error: API file not found at $api_file_path");
                                goto end_koombiyo_processing;
                            }
                            
                            include($api_file_path);
                            
                            if (!function_exists('addKoombiyoOrder')) {
                                $courier_warning = "Koombiyo API service unavailable. Please contact support.";
                                error_log("Koombiyo API Error: addKoombiyoOrder function not found");
                                goto end_koombiyo_processing;
                            }
                            
                            // Validate API key
                            if (empty($api_key)) {
                                $courier_warning = "Courier service configuration error. Please contact support.";
                                error_log("Koombiyo API Error: API key not configured");
                                goto end_koombiyo_processing;
                            }
                            
                            // CITY AND DISTRICT HANDLING
                            $city_name = '';
                            $district_name = '';
                            $proceed_with_api = false;
                            
                            if (!empty($city_id)) {
                                // Get both city and district information
                                $getCityDistrictSql = "SELECT ct.city_name, dt.district_name 
                                                    FROM city_table ct 
                                                    LEFT JOIN district_table dt ON ct.district_id = dt.district_id 
                                                    WHERE ct.city_id = ? AND ct.is_active = 1";
                                
                                $cityStmt = $conn->prepare($getCityDistrictSql);
                                if (!$cityStmt) {
                                    $courier_warning = "Database error occurred. Please try again.";
                                    error_log("Koombiyo API Error: Failed to prepare city query - " . $conn->error);
                                    goto end_koombiyo_processing;
                                }
                                
                                $cityStmt->bind_param("i", $city_id);
                                if (!$cityStmt->execute()) {
                                    $courier_warning = "Database error occurred. Please try again.";
                                    error_log("Koombiyo API Error: Failed to execute city query - " . $cityStmt->error);
                                    goto end_koombiyo_processing;
                                }
                                
                                $cityResult = $cityStmt->get_result();
                                
                                if ($cityResult && $cityResult->num_rows > 0) {
                                    $locationData = $cityResult->fetch_assoc();
                                    $city_name = $locationData['city_name'];
                                    $district_name = $locationData['district_name'] ?? 'Unknown District';
                                    $proceed_with_api = true;
                                } else {
                                    $courier_warning = "Invalid delivery location selected. Please check your address.";
                                    error_log("Koombiyo API Error: City not found for city_id: $city_id");
                                    goto end_koombiyo_processing;
                                }
                                $cityStmt->close();
                            } else {
                                $courier_warning = "Delivery city not specified. Please complete your address.";
                                error_log("Koombiyo API Error: No city_id provided for order $order_id");
                                goto end_koombiyo_processing;
                            }
                            
                            // Only proceed with API call if we have valid city/district
                            if ($proceed_with_api) {
                                // Get unused tracking ID
                                $getTrackingSql = "SELECT tracking_id FROM tracking WHERE courier_id = ? AND status = 'unused' LIMIT 1";
                                
                                $trackingStmt = $conn->prepare($getTrackingSql);
                                if (!$trackingStmt) {
                                    $courier_warning = "System error occurred. Please try again.";
                                    error_log("Koombiyo API Error: Failed to prepare tracking query - " . $conn->error);
                                    goto end_koombiyo_processing;
                                }
                                
                                $trackingStmt->bind_param("i", $default_courier_id);
                                if (!$trackingStmt->execute()) {
                                    $courier_warning = "System error occurred. Please try again.";
                                    error_log("Koombiyo API Error: Failed to execute tracking query - " . $trackingStmt->error);
                                    goto end_koombiyo_processing;
                                }
                                
                                $trackingResult = $trackingStmt->get_result();
                                
                                if ($trackingResult && $trackingResult->num_rows > 0) {
                                    $trackingData = $trackingResult->fetch_assoc();
                                    $waybill_id = $trackingData['tracking_id'];
                                    $trackingStmt->close();
                                    
                                    // Calculate API amount based on payment status
                                    $api_amount = ($order_status === 'Paid') ? '0' : strval($total_amount);
                                    
                                    // Prepare parcel description
                                    $parcel_description = 'Order #' . $order_id . ' - ' . count($order_items) . ' items';
                                    
                                    // Get delivery instructions from notes if available
                                    $delivery_instructions = $notes ?? '';
                                    
                                    // Prepare data for Koombiyo API
                                    $koombiyo_api_data = array(
                                        'apikey' => $api_key,
                                        'orderWaybillid' => $waybill_id,
                                        'orderNo' => strval($order_id),
                                        'receiverName' => $customer_name,
                                        'receiverStreet' => trim($address_line1 . ' ' . $address_line2),
                                        'receiverDistrict' => $district_name,
                                        'receiverCity' => $city_name,
                                        'receiverPhone' => $customer_phone,
                                        'description' => $parcel_description,
                                        'spclNote' => $delivery_instructions,
                                        'getCod' => $api_amount
                                    );
                                    
                                    // Call Koombiyo API
                                    try {
                                        $koombiyo_response = addKoombiyoOrder($koombiyo_api_data, $api_key);
                                        
                                        // Handle response
                                        if (!$koombiyo_response || !is_array($koombiyo_response)) {
                                            $courier_warning = "Courier service error. Please try again or contact support.";
                                            error_log("Koombiyo API Error: Invalid response format for order $order_id");
                                            
                                        } elseif (!isset($koombiyo_response['success']) || !$koombiyo_response['success']) {
                                            // API failed
                                            $api_error = $koombiyo_response['error'] ?? 'Unknown API error';
                                            $courier_warning = "Courier API failed: " . $api_error;
                                            
                                            // Log detailed error information
                                            $error_details = isset($koombiyo_response['details']) ? json_encode($koombiyo_response['details']) : 'No additional details';
                                            error_log("Koombiyo API Failed for order $order_id: $api_error | Details: $error_details");
                                            
                                        } else {
                                            // API success - update database
                                            $conn->autocommit(FALSE); // Start transaction
                                            
                                            try {
                                                // Update tracking to used
                                                $updateTrackingSql = "UPDATE tracking SET status = 'used', updated_at = CURRENT_TIMESTAMP WHERE tracking_id = ? AND courier_id = ?";
                                                $updateTrackingStmt = $conn->prepare($updateTrackingSql);
                                                if (!$updateTrackingStmt) {
                                                    throw new Exception("Failed to prepare tracking update: " . $conn->error);
                                                }
                                                $updateTrackingStmt->bind_param("si", $waybill_id, $default_courier_id);
                                                if (!$updateTrackingStmt->execute()) {
                                                    throw new Exception("Failed to update tracking status: " . $updateTrackingStmt->error);
                                                }

                                                // Update order header
                                             $updateOrderHeaderSql = "UPDATE order_header SET 
                                                    co_id = ?,
                                                    courier_id = ?, 
                                                    tracking_number = ?, 
                                                    status = 'dispatch'
                                                    WHERE order_id = ?";
                                                $updateOrderStmt = $conn->prepare($updateOrderHeaderSql);
                                                if (!$updateOrderStmt) {
                                                    throw new Exception("Failed to prepare order header update: " . $conn->error);
                                                }
                                                $updateOrderStmt->bind_param("iisi", $co_id, $default_courier_id, $tracking_number, $order_id);
                                                if (!$updateOrderStmt->execute()) {
                                                    throw new Exception("Failed to update order header: " . $updateOrderStmt->error);
                                                }
                                                
                                                // Update order items
                                                $updateOrderItemsSql = "UPDATE order_items SET status = 'dispatch' WHERE order_id = ?";
                                                $updateItemsStmt = $conn->prepare($updateOrderItemsSql);
                                                if (!$updateItemsStmt) {
                                                    throw new Exception("Failed to prepare order items update: " . $conn->error);
                                                }
                                                $updateItemsStmt->bind_param("i", $order_id);
                                                if (!$updateItemsStmt->execute()) {
                                                    throw new Exception("Failed to update order items: " . $updateItemsStmt->error);
                                                }
                                                
                                                // Commit transaction
                                                $conn->commit();
                                                $conn->autocommit(TRUE);
                                                
                                                $status = 'dispatch';
                                                $tracking_assigned = true;
                                                
                                                // Log success
                                                error_log("Koombiyo API Success: Order $order_id dispatched with tracking $waybill_id");
                                                
                                                // Close prepared statements
                                                $updateTrackingStmt->close();
                                                $updateOrderStmt->close();
                                                $updateItemsStmt->close();
                                                
                                            } catch (Exception $e) {
                                                // Rollback transaction on database error
                                                $conn->rollback();
                                                $conn->autocommit(TRUE);
                                                
                                                $courier_warning = "Order processed but system update failed. Please contact support with order #$order_id.";
                                                error_log("Koombiyo API Database Error for order $order_id after successful API call: " . $e->getMessage());
                                                
                                                // Close any open statements
                                                if (isset($updateTrackingStmt)) $updateTrackingStmt->close();
                                                if (isset($updateOrderStmt)) $updateOrderStmt->close();
                                                if (isset($updateItemsStmt)) $updateItemsStmt->close();
                                            }
                                        }
                                        
                                    } catch (Exception $e) {
                                        // API call exception
                                        $courier_warning = "Courier service unavailable.";
                                        error_log("Koombiyo API Exception for order $order_id: " . $e->getMessage());
                                    }
                                    
                                } else {
                                    // No tracking IDs available
                                    $courier_warning = "No delivery tracking available for {$courier_name}.";
                                    error_log("Koombiyo API Error: No unused tracking IDs available for courier $default_courier_id");
                                    if (isset($trackingStmt)) $trackingStmt->close();
                                }
                            }
                            
                            end_koombiyo_processing:
                            
                            // If there were errors and tracking wasn't assigned, ensure proper status
                            if (!$tracking_assigned && !empty($courier_warning)) {
                                $status = 'confirmed'; // Keep as confirmed if courier assignment failed
                            }
                        }
                        // Koombiyo API end here

    
            } elseif ($default_courier_id == 13) {
                // Trans Express API start here
                if ($courier_type == 1) {
                    // INTERNAL TRACKING SYSTEM for Royal Express
                    // Get an unused tracking number for this courier
                    $getTrackingSql = "SELECT tracking_id FROM tracking WHERE courier_id = ? AND status = 'unused' LIMIT 1";
                    $trackingStmt = $conn->prepare($getTrackingSql);
                    $trackingStmt->bind_param("i", $default_courier_id);
                    $trackingStmt->execute();
                    $trackingResult = $trackingStmt->get_result();
                    
                    if ($trackingResult && $trackingResult->num_rows > 0) {
                        $trackingData = $trackingResult->fetch_assoc();
                        $tracking_number = $trackingData['tracking_id'];
                        
                        // Update the tracking record to 'used'
                        $updateTrackingSql = "UPDATE tracking SET status = 'used' WHERE tracking_id = ? AND courier_id = ?";
                        $updateTrackingStmt = $conn->prepare($updateTrackingSql);
                        $updateTrackingStmt->bind_param("si", $tracking_number, $default_courier_id);
                        $updateTrackingStmt->execute();
                        
                        // Update order_header with courier info and set status to 'dispatch'
                       $updateOrderHeaderSql = "UPDATE order_header SET 
    co_id = ?,
    courier_id = ?, 
    tracking_number = ?, 
    status = 'dispatch'
    WHERE order_id = ?";
$updateOrderStmt = $conn->prepare($updateOrderHeaderSql);
$updateOrderStmt->bind_param("iisi", $co_id, $default_courier_id, $tracking_number, $order_id);

                        $updateOrderStmt->execute();
                        
                        // Update all order_items status to 'dispatch'
                        $updateOrderItemsSql = "UPDATE order_items SET status = 'dispatch' WHERE order_id = ?";
                        $updateItemsStmt = $conn->prepare($updateOrderItemsSql);
                        $updateItemsStmt->bind_param("i", $order_id);
                        $updateItemsStmt->execute();
                        
                        // Update the main status variable for later use
                        $status = 'dispatch';
                        $tracking_assigned = true;
                        
                    } else {
                        $courier_warning = "No unused tracking numbers available for {$courier_name}";
                    }
                    
// FIXED TransExpress New Parcel API Integration (Type 2)
} elseif ($courier_type == 2) {
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/api/transexpress_new_parcel_api.php');
    
    $proceed_with_api = false;
    $district_id = null;
    
    // FIXED: Fetch BOTH city_name AND district_id
    if (!empty($city_id)) {
        $getCitySql = "SELECT city_name, district_id FROM city_table WHERE city_id = ? AND is_active = 1";
        $cityStmt = $conn->prepare($getCitySql);
        $cityStmt->bind_param("i", $city_id);
        $cityStmt->execute();
        $cityResult = $cityStmt->get_result();
        
        if ($cityResult && $cityResult->num_rows > 0) {
            $cityData = $cityResult->fetch_assoc();
            $city_name = $cityData['city_name'];
            $district_id = $cityData['district_id'];
            $proceed_with_api = !empty($district_id); // Require district_id
            
            error_log("DEBUG TransExpress - City: $city_name, District ID: " . ($district_id ?? 'NULL'));
        }
        $cityStmt->close();
    }
    
    if (!$proceed_with_api) {
        $courier_warning = empty($city_id) ? 'City not specified' : 'Invalid city or district not found';
        error_log("TransExpress Validation Failed: $courier_warning");
    } else {
        $api_amount = ($order_status === 'Paid') ? 0 : $total_amount;
        $clean_phone = preg_replace('/[^0-9]/', '', $customer_phone);
        $clean_phone2 = !empty($customer_phone_2) ? preg_replace('/[^0-9]/', '', $customer_phone_2) : '';
        
        // FIXED: Include district_id
        $transexpress_data = array(
            'api_key' => $api_key,
            'order_no' => (string)$order_id,
            'customer_name' => $customer_name,
            'address' => trim($address_line1 . ' ' . $address_line2),
            'description' => 'Order #' . $order_id . ' - ' . count($order_items) . ' items',
            'phone_no' => $clean_phone,
            'phone_no2' => $clean_phone2,
            'cod' => (float)$api_amount,
            'city_id' => (int)$city_id,
            'district_id' => (int)$district_id,
            'note' => $notes ?? ''
        );
        
        error_log("TransExpress API Request: " . json_encode($transexpress_data));
        
        $response = callTransExpressApi($transexpress_data);
        error_log("TransExpress API Response: " . $response);
        
        $result = parseTransExpressResponse($response);
        
        if ($result['success']) {
            $tracking_number = $result['waybill_id'];
            
         $updateOrderHeaderSql = "UPDATE order_header SET 
    co_id = ?,
    courier_id = ?, 
    tracking_number = ?, 
    status = 'dispatch'
    WHERE order_id = ?";
$updateOrderStmt = $conn->prepare($updateOrderHeaderSql);
$updateOrderStmt->bind_param("iisi", $co_id, $default_courier_id, $tracking_number, $order_id);

            $updateStmt->execute();
            $updateStmt->close();
    
            $updateItemsSql = "UPDATE order_items SET status = 'dispatch' WHERE order_id = ?";
            $itemsStmt = $conn->prepare($updateItemsSql);
            $itemsStmt->bind_param("i", $order_id);
            $itemsStmt->execute();
            $itemsStmt->close();
            
            $status = 'dispatch';
            $tracking_assigned = true;
            
            error_log("TransExpress Success: Order $order_id, Tracking: $tracking_number");
            
        } else {
            $courier_warning = "TransExpress Error: " . ($result['error'] ?? 'Unknown error');
            error_log("TransExpress Failed: " . $courier_warning . " | Full result: " . json_encode($result));
        }
    }

// FIXED TransExpress Existing Parcel API Integration (Type 3)
} elseif ($courier_type == 3) {
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/api/transexpress_existing_parcel_api.php');
    
    $proceed_with_api = false;
    $city_name = '';
    $district_id = null;
    
    // FIXED: Fetch city_name AND district_id
    if (!empty($city_id)) {
        $getCityNameSql = "SELECT city_name, district_id FROM city_table WHERE city_id = ? AND is_active = 1";
        $cityStmt = $conn->prepare($getCityNameSql);
        $cityStmt->bind_param("i", $city_id);
        $cityStmt->execute();
        $cityResult = $cityStmt->get_result();
        
        if ($cityResult && $cityResult->num_rows > 0) {
            $cityData = $cityResult->fetch_assoc();
            $city_name = $cityData['city_name'];
            $district_id = $cityData['district_id'];
            $proceed_with_api = !empty($district_id);
            
            error_log("DEBUG TransExpress Existing - City: $city_name, District ID: " . ($district_id ?? 'NULL'));
        }
        $cityStmt->close();
    }
    
    if (!$proceed_with_api) {
        $courier_warning = empty($city_id) ? 'City not specified in delivery address' : 'Invalid city or district not found';
        error_log("TransExpress Existing Validation Failed: $courier_warning");
    } else {
        // Get unused tracking ID
        $getTrackingSql = "SELECT id, tracking_id FROM tracking WHERE courier_id = ? AND status = 'unused' LIMIT 1";
        $trackingStmt = $conn->prepare($getTrackingSql);
        $trackingStmt->bind_param("i", $default_courier_id);
        $trackingStmt->execute();
        $trackingResult = $trackingStmt->get_result();
        
        if ($trackingResult && $trackingResult->num_rows > 0) {
            $trackingData = $trackingResult->fetch_assoc();
            $tracking_record_id = $trackingData['id'];
            $raw_waybill_id = $trackingData['tracking_id'];
            $trackingStmt->close();
            
            // Format waybill - verify this matches TransExpress requirements
            $numeric_part = preg_replace('/[^0-9]/', '', $raw_waybill_id);
            if (strlen($numeric_part) >= 8) {
                $waybill_id = substr($numeric_part, -8);
            } else {
                $waybill_id = str_pad($numeric_part, 8, '0', STR_PAD_LEFT);
            }
            
            error_log("TransExpress Waybill Format: Raw=$raw_waybill_id, Formatted=$waybill_id");
            
            $api_amount = ($order_status === 'Paid') ? 0 : $total_amount;
            $clean_phone = preg_replace('/[^0-9]/', '', $customer_phone);
            $clean_phone2 = !empty($customer_phone_2) ? preg_replace('/[^0-9]/', '', $customer_phone_2) : '';
            
            // FIXED: Include district_id
            $transexpress_data = array(
                'api_key' => $api_key,
                'waybill_id' => $waybill_id,
                'order_no' => (string)$order_id,
                'customer_name' => $customer_name,
                'address' => trim($address_line1 . ' ' . $address_line2),
                'description' => 'Order #' . $order_id . ' - ' . count($order_items) . ' items',
                'phone_no' => $clean_phone,
                'phone_no2' => $clean_phone2,
                'cod' => (float)$api_amount,
                'city_id' => (int)$city_id,
                'district_id' => (int)$district_id,
                'note' => $notes ?? ''
            );
            
            error_log("TransExpress Existing API Request: " . json_encode($transexpress_data));
            
            $api_response = callTransExpressExistingParcelApi($transexpress_data);
            error_log("TransExpress Existing API Response: " . $api_response);
            
            $result = parseTransExpressExistingResponse($api_response);
            
            if ($result['success']) {
                $tracking_number = $result['waybill_id'] ?? $waybill_id;
                
                // Update tracking
                $updateTrackingSql = "UPDATE tracking SET status = 'used', updated_at = CURRENT_TIMESTAMP WHERE id = ?";
                $updateTrackingStmt = $conn->prepare($updateTrackingSql);
                $updateTrackingStmt->bind_param("i", $tracking_record_id);
                $tracking_updated = $updateTrackingStmt->execute();
                $updateTrackingStmt->close();
                
                // Update order
                $updateOrderSql = "UPDATE order_header SET courier_id = ?, tracking_number = ?, status = 'dispatch' WHERE order_id = ?";
                $updateOrderStmt = $conn->prepare($updateOrderSql);
                $updateOrderStmt->bind_param("isi", $default_courier_id, $tracking_number, $order_id);
                $order_updated = $updateOrderStmt->execute();
                $updateOrderStmt->close();
                
                // Update items
                $updateItemsSql = "UPDATE order_items SET status = 'dispatch' WHERE order_id = ?";
                $updateItemsStmt = $conn->prepare($updateItemsSql);
                $updateItemsStmt->bind_param("i", $order_id);
                $items_updated = $updateItemsStmt->execute();
                $updateItemsStmt->close();
                
                if ($tracking_updated && $order_updated && $items_updated) {
                    $status = 'dispatch';
                    $tracking_assigned = true;
                    error_log("TransExpress Existing Success: Order $order_id dispatched with tracking $tracking_number");
                } else {
                    $courier_warning = "API succeeded but database update failed";
                    error_log("TransExpress DB Update Failed: tracking=$tracking_updated, order=$order_updated, items=$items_updated");
                }
                
            } else {
                $courier_warning = $result['error'] ?? 'TransExpress API failed';
                error_log("TransExpress Existing API Failed: " . $courier_warning . " | Full result: " . json_encode($result));
            }
            
        } else {
            $courier_warning = "No unused waybill numbers available for {$courier_name}";
            error_log("TransExpress: No unused tracking IDs");
            if (isset($trackingStmt)) $trackingStmt->close();
        }
    }
}//Trans Express API end here

       } elseif ($default_courier_id == 14) {
        // Royal Express API start here
                if ($courier_type == 1) {
                    // INTERNAL TRACKING SYSTEM for Royal exp
                    // Get an unused tracking number for this courier
                    $getTrackingSql = "SELECT tracking_id FROM tracking WHERE courier_id = ? AND status = 'unused' LIMIT 1";
                    $trackingStmt = $conn->prepare($getTrackingSql);
                    $trackingStmt->bind_param("i", $default_courier_id);
                    $trackingStmt->execute();
                    $trackingResult = $trackingStmt->get_result();
                    
                    if ($trackingResult && $trackingResult->num_rows > 0) {
                        $trackingData = $trackingResult->fetch_assoc();
                        $tracking_number = $trackingData['tracking_id'];
                        
                        // Update the tracking record to 'used'
                        $updateTrackingSql = "UPDATE tracking SET status = 'used' WHERE tracking_id = ? AND courier_id = ?";
                        $updateTrackingStmt = $conn->prepare($updateTrackingSql);
                        $updateTrackingStmt->bind_param("si", $tracking_number, $default_courier_id);
                        $updateTrackingStmt->execute();
                        
                        // Update order_header with courier info and set status to 'dispatch'
                      $updateOrderHeaderSql = "UPDATE order_header SET 
    co_id = ?,
    courier_id = ?, 
    tracking_number = ?, 
    status = 'dispatch'
    WHERE order_id = ?";
$updateOrderStmt = $conn->prepare($updateOrderHeaderSql);
$updateOrderStmt->bind_param("iisi", $co_id, $default_courier_id, $tracking_number, $order_id);

                        $updateOrderStmt->execute();
                        
                        // Update all order_items status to 'dispatch'
                        $updateOrderItemsSql = "UPDATE order_items SET status = 'dispatch' WHERE order_id = ?";
                        $updateItemsStmt = $conn->prepare($updateOrderItemsSql);
                        $updateItemsStmt->bind_param("i", $order_id);
                        $updateItemsStmt->execute();
                        
                        // Update the main status variable for later use
                        $status = 'dispatch';
                        $tracking_assigned = true;
                        
                    } else {
                        $courier_warning = "No unused tracking numbers available for {$courier_name}";
                    }
                    
                } elseif ($courier_type == 2) {
                    // Royal express NEW PARCEL API INTEGRATION (placeholder for future implementation)
                    $courier_warning = "Royal Express New Parcel API not yet implemented";

                } elseif ($courier_type == 3) {

                    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/api/royal_express_existing_parcel_api.php');

                    // STEP 1: Validate city and state
                    $proceed_with_api = false;
                    $city_name = '';
                    $state_name = '';

                    if (!empty($city_id)) {
                        // Fetch city name and its state_id
                        $getCityNameSql = "SELECT city_name, state_id FROM city_table WHERE city_id = ? AND is_active = 1";
                        $cityStmt = $conn->prepare($getCityNameSql);
                        $cityStmt->bind_param("i", $city_id);
                        $cityStmt->execute();
                        $cityResult = $cityStmt->get_result();

                        if ($cityResult && $cityResult->num_rows > 0) {
                            $cityData = $cityResult->fetch_assoc();
                            $city_name = $cityData['city_name'];
                            $state_id = $cityData['state_id'];

                            // Fetch state name from state_table
                            if (!empty($state_id)) {
                                $getStateSql = "SELECT name FROM state_table WHERE id = ?";
                                $stateStmt = $conn->prepare($getStateSql);
                                $stateStmt->bind_param("i", $state_id);
                                $stateStmt->execute();
                                $stateResult = $stateStmt->get_result();
                                if ($stateResult && $stateResult->num_rows > 0) {
                                    $stateData = $stateResult->fetch_assoc();
                                    $state_name = $stateData['name'];
                                }
                                $stateStmt->close();
                            }

                            $proceed_with_api = true;
                        }
                        $cityStmt->close();
                    } else {
                        // If city_id is empty, try using customer's state_name if available
                        if (!empty($customer_state_name)) {
                            $state_name = $customer_state_name;
                            $proceed_with_api = true;
                        }
                    }

                    if (!$proceed_with_api) {
                        $courier_warning = empty($city_id) ? 'City not specified in delivery address' : 'Invalid city selected';
                    } else {
                        // STEP 2: Get unused tracking ID
                        $getTrackingSql = "SELECT id, tracking_id FROM tracking WHERE courier_id = 14 AND status = 'unused' LIMIT 1";
                        $trackingStmt = $conn->prepare($getTrackingSql);
                        $trackingStmt->execute();
                        $trackingResult = $trackingStmt->get_result();

                        if ($trackingResult && $trackingResult->num_rows > 0) {
                            $trackingData = $trackingResult->fetch_assoc();
                            $tracking_record_id = $trackingData['id'];
                            $waybill_number = trim($trackingData['tracking_id']);
                            $trackingStmt->close();

                            // STEP 3: Prepare payload
                            $api_amount = ($order_status === 'Paid') ? 0 : $total_amount;
                            $clean_phone = preg_replace('/[^0-9]/', '', $customer_phone);
                            $clean_phone2 = preg_replace('/[^0-9]/', '', $customer_secondary_phone ?? '');

                            $royal_data = [
                                "general_data" => [
                                    "merchant_business_id" => $client_id,
                                    "origin_city_name" => $origin_city_name,
                                    "origin_state_name" => $origin_state_name
                                ],
                                "order_data" => [
                                    [
                                        "waybill_number" => $waybill_number,
                                        "order_no" => (string)$order_id,
                                        "customer_name" => $customer_name,
                                        "customer_address" => trim($address_line1 . ' ' . $address_line2),
                                        "customer_phone" => $clean_phone,
                                        "customer_secondary_phone" => $clean_phone2,
                                        "destination_city_name" => $city_name,
                                        "destination_state_name" => $state_name,
                                        "cod" => floatval($api_amount),
                                        "description" => 'Order #' . $order_id . ' - ' . count($order_items) . ' items',
                                        "weight" => floatval($weight ?? 1),
                                        "remark" => $notes ?? ''
                                    ]
                                ]
                            ];

                            // STEP 4: Call Royal Express API
                            $api_response = callRoyalExpressSingleOrderApi($royal_data, $default_courier_id, $conn);
                            $result = parseRoyalExpressResponse($api_response);

                            // STEP 5: Handle response
                            if ($result['success'] == true) {

                                
                                $tracking_number = $result['tracking_number'] ?? $waybill_number;

                                // Update tracking
                                $updateTrackingSql = "UPDATE tracking SET status = 'used', updated_at = CURRENT_TIMESTAMP WHERE id = ?";
                                $updateTrackingStmt = $conn->prepare($updateTrackingSql);
                                $updateTrackingStmt->bind_param("i", $tracking_record_id);
                                $tracking_updated = $updateTrackingStmt->execute();
                                $updateTrackingStmt->close();

                                // Update order header
                                $updateOrderSql = "UPDATE order_header SET courier_id = 14, tracking_number = ?, status = 'dispatch' WHERE order_id = ?";
                                $updateOrderStmt = $conn->prepare($updateOrderSql);
                                $updateOrderStmt->bind_param("si", $tracking_number, $order_id);
                                $order_updated = $updateOrderStmt->execute();
                                $updateOrderStmt->close();

                                // Update order items
                                $updateItemsSql = "UPDATE order_items SET status = 'dispatch' WHERE order_id = ?";
                                $updateItemsStmt = $conn->prepare($updateItemsSql);
                                $updateItemsStmt->bind_param("i", $order_id);
                                $items_updated = $updateItemsStmt->execute();
                                $updateItemsStmt->close();

                                if ($tracking_updated && $order_updated && $items_updated) {
                                    $status = 'dispatch';
                                    $tracking_assigned = true;
                                    error_log("RoyalExpress Success: Order $order_id dispatched with tracking $tracking_number");
                                } else {
                                    $courier_warning = "API succeeded but DB update failed.";
                                }
                            } else {
                                $courier_warning = $result['error_message'] ?? 'Royal Express API failed';
                            }
                        } else {
                            $courier_warning = "No unused waybill numbers available for Royal Express.";
                            if (isset($trackingStmt)) $trackingStmt->close();
                        }
                    }
                }


             } else {
                // OTHER COURIER TYPES - Default internal tracking   
            }

        } else {
            // No courier configured
            $courier_warning = "No courier configured";
        }
        
        // If order is marked as Paid, insert into payments table
        if ($order_status === 'Paid') {
            // Default payment method to 'Cash'
            $payment_method = 'Cash';
            
            // Insert payment record
            $insertPaymentSql = "INSERT INTO payments (
                order_id, 
                amount_paid, 
                payment_method, 
                payment_date, 
                pay_by
            ) VALUES (?, ?, ?, ?, ?)";

            $current_datetime = date('Y-m-d H:i:s');

            $stmt = $conn->prepare($insertPaymentSql);
            $stmt->bind_param(
                "idssi", 
                $order_id, 
                $total_amount, 
                $payment_method, 
                $current_datetime, 
                $user_id
            );
            $stmt->execute();
        }
        
        // SINGLE USER LOG - CREATE ONLY ONE LOG ENTRY FOR ORDER CREATION
        $log_details = "Add a " . ($tracking_assigned ? 'dispatch' : 'pending') . " " . ($order_status === 'Paid' ? 'paid' : 'unpaid') . " order($order_id)" . 
                       ($total_discount > 0 ? " with discount" : "") . 
                       ($tracking_assigned && isset($tracking_number) ? " with tracking($tracking_number)" : "");
        
        // Log the order creation action - SINGLE LOG ENTRY
        $log_success = logUserAction($conn, $user_id, 'create_order', $order_id, $log_details);
        
        // Optional: Log any errors (but don't stop the process)
        if (!$log_success) {
            error_log("Failed to log user action for order creation: Order ID $order_id, User ID $user_id");
        }
        
        // Commit transaction
        $conn->commit();
        
        // UPDATED: Determine success message and redirect logic
        if ($tracking_assigned) {
            // Order created successfully with tracking
            $success_message = "Order #" . $order_id . " created successfully with tracking number assigned!";
            setMessageAndRedirect('success', $success_message, "download_order.php?id=" . $order_id);
        } else {
            // Order created but tracking assignment failed or skipped
            $success_message = "Order #" . $order_id . " created successfully!";
            
            if (!empty($courier_warning)) {
                // Set both success and warning messages
                $_SESSION['order_success'] = $success_message;
                $_SESSION['order_warning'] = $courier_warning;
                
                // Additional context for specific FDE errors
                if (strpos($courier_warning, 'Invalid City') !== false || strpos($courier_warning, 'Invalid city') !== false) {
                    $_SESSION['order_info'] = "Please ensure a valid delivery city is selected for automatic tracking assignment.";
                } elseif (strpos($courier_warning, 'Contact number') !== false || strpos($courier_warning, 'Invalid contact number') !== false) {
                    $_SESSION['order_info'] = "Please verify the customer's phone number format for FDE courier integration.";
                } elseif (strpos($courier_warning, 'Invalid or inactive client') !== false || strpos($courier_warning, 'Inactive Client') !== false) {
                    $_SESSION['order_info'] = "Courier API client configuration needs to be updated. Contact system administrator.";
                }
                
                // Clear any output buffers before redirect
                while (ob_get_level()) {
                    ob_end_clean();
                }
                
                header("Location: download_order.php?id=" . $order_id);
                exit();
            } else {
                setMessageAndRedirect('success', $success_message, "download_order.php?id=" . $order_id);
            }
        }
        
    } catch (Exception $e) {
        // Rollback transaction
        if ($conn->inTransaction()) {
            $conn->rollback();
        }
        
        // Log the error for debugging
        error_log("Order creation error: " . $e->getMessage());
        error_log("Error trace: " . $e->getTraceAsString());
        
        // Set error message and redirect
        setMessageAndRedirect('error', $e->getMessage());
    }
} else {
    // Not a POST request - redirect with info message
    setMessageAndRedirect('info', 'Invalid request method. Please use the order form to create orders.');
}
?>
