<?php
// STEP 1: Initialization and Authentication
session_start();

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /order_management/dist/pages/login.php");
    exit();
}

// Get user's role and admin status
$user_id = $_SESSION['user_id'];
$is_main_admin = isset($_SESSION['is_main_admin']) && $_SESSION['is_main_admin'] == 1;

// Include the database connection file
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/connection/db_connection.php');

// Check if user is admin (role_id = 1) or main admin
$role_check_sql = "SELECT role_id FROM users WHERE id = ?";
$role_stmt = $conn->prepare($role_check_sql);
$role_stmt->bind_param("i", $user_id);
$role_stmt->execute();
$role_result = $role_stmt->get_result();
$user_data = $role_result->fetch_assoc();

if (!$is_main_admin && (!isset($user_data['role_id']) || $user_data['role_id'] != 1)) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    // Redirect to access denied page or dashboard
    header("Location: /order_management/dist/pages/access_denied.php");
    exit();
}


// Function to generate CSRF token
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Function to log user actions
function logUserAction($conn, $user_id, $action_type, $details = null, $inquiry_id = 0) {
    $stmt = $conn->prepare("INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->bind_param("isis", $user_id, $action_type, $inquiry_id, $details);
    $stmt->execute();
    $stmt->close();
}

// Initialize message variables
$success_message = '';
$error_message = '';

// Check for success/error messages from session
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// STEP 2: Tenant Selection Handling
$target_tenant_id = $_SESSION['tenant_id'] ?? 0;

// If main admin, they can override the tenant_id via GET or POST
if ($is_main_admin) {
    if (isset($_REQUEST['tenant_filter']) && !empty($_REQUEST['tenant_filter'])) {
        $target_tenant_id = (int)$_REQUEST['tenant_filter'];
    }
}

// Fetch tenants if user is main admin 
$tenants_list = [];
if ($is_main_admin) {
    $tenantQuery = "SELECT tenant_id, company_name FROM tenants WHERE status = 'active' ORDER BY company_name";
    $tenantResult = mysqli_query($conn, $tenantQuery);
    if ($tenantResult) {
        while ($row = mysqli_fetch_assoc($tenantResult)) {
            $tenants_list[] = $row;
        }
    }
}

// STEP 3: Form Submission Handling (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_branding'])) {

    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error_message'] = "Invalid request. Please try again.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // Get form data with proper sanitization
    $company_name = mysqli_real_escape_string($conn, trim($_POST['company_name']));
    $web_name = mysqli_real_escape_string($conn, trim($_POST['web_name']));
    $address = mysqli_real_escape_string($conn, trim($_POST['address']));
    $hotline = mysqli_real_escape_string($conn, trim($_POST['hotline']));
    $email = mysqli_real_escape_string($conn, trim($_POST['email']));
    $delivery_fee = mysqli_real_escape_string($conn, trim($_POST['delivery_fee']));
    
    // NEW BRANDING FIELDS (Added from database schema)
    $primary_color = mysqli_real_escape_string($conn, trim($_POST['primary_color']));
    $secondary_color = mysqli_real_escape_string($conn, trim($_POST['secondary_color']));
    $font_family = mysqli_real_escape_string($conn, trim($_POST['font_family']));
    
    // Validate required fields
    if (empty($company_name)) {
        $_SESSION['error_message'] = "Company name is required.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error_message'] = "Please enter a valid email address.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    
    // Note: delivery_fee is decimal(10,2) and nullable, defaults to 0.00
    if (!is_numeric($delivery_fee) || $delivery_fee < 0) {
        $_SESSION['error_message'] = "Please enter a valid delivery fee (non-negative number).";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    
    // Logo upload handling
    $logo_url = '';
    $logo_uploaded = false;
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
        $allowed = array('jpg', 'jpeg', 'png', 'gif');
        $filename = $_FILES['logo']['name'];
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        
        if (in_array(strtolower($ext), $allowed)) {
            $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/uploads/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $new_name = 'logo_' . time() . '.' . $ext;
            $destination = $upload_dir . $new_name;
            
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $destination)) {
                $logo_url = '/order_management/dist/uploads/' . $new_name;
                $logo_uploaded = true;
            } else {
                $_SESSION['error_message'] = "Error uploading logo file.";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            }
        } else {
            $_SESSION['error_message'] = "Invalid logo file type. Please upload JPG, JPEG, PNG, or GIF files only.";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    }
    
    // Favicon upload handling
    $fav_icon_url = '';
    $favicon_uploaded = false;
    if (isset($_FILES['fav_icon']) && $_FILES['fav_icon']['error'] == 0) {
        $allowed = array('jpg', 'jpeg', 'png', 'ico');
        $filename = $_FILES['fav_icon']['name'];
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        
        if (in_array(strtolower($ext), $allowed)) {
            $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/uploads/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $new_name = 'favicon_' . time() . '.' . $ext;
            $destination = $upload_dir . $new_name;
            
            if (move_uploaded_file($_FILES['fav_icon']['tmp_name'], $destination)) {
                $fav_icon_url = '/order_management/dist/uploads/' . $new_name;
                $favicon_uploaded = true;
            } else {
                $_SESSION['error_message'] = "Error uploading favicon file.";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            }
        } else {
            $_SESSION['error_message'] = "Invalid favicon file type. Please upload JPG, JPEG, PNG, or ICO files only.";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    }
    
    // Check if we need to update or insert (UPSERT logic)
    $check_query = "SELECT * FROM branding WHERE tenant_id = ?";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("i", $target_tenant_id);
    $stmt->execute();
    $check_result = $stmt->get_result();

    
    if ($check_result && $check_result->num_rows > 0) {
        // UPDATE existing record - Get old values first
        $old_branding = $check_result->fetch_assoc();
        $branding_id = $old_branding['branding_id'];
        
        // Define core fields for UPDATE
        $update_sql_parts = [
            "company_name = ?", "web_name = ?", "address = ?", "hotline = ?", "email = ?", 
            "delivery_fee = ?", "primary_color = ?", "secondary_color = ?", "font_family = ?"
        ];
        $params = [
            $company_name, $web_name, $address, $hotline, $email, 
            $delivery_fee, $primary_color, $secondary_color, $font_family
        ];
        $types = "sssssssss"; // 9 's' for strings/varchar/decimal
        
        // Add file fields if a new one was uploaded
        if (!empty($logo_url)) {
            $update_sql_parts[] = "logo_url = ?";
            $params[] = $logo_url;
            $types .= "s";
        }
        
        if (!empty($fav_icon_url)) {
            $update_sql_parts[] = "fav_icon_url = ?";
            $params[] = $fav_icon_url;
            $types .= "s";
        }
        
        $update_sql = "UPDATE branding SET " . implode(', ', $update_sql_parts) . " WHERE branding_id = ? AND tenant_id = ?";
        $params[] = $branding_id;
        $params[] = $target_tenant_id;
        $types .= "ii"; // 'i' for branding_id and 'i' for tenant_id

        
        $stmt = $conn->prepare($update_sql);
        // Using the spread operator to pass the array of parameters to bind_param
        $stmt->bind_param($types, ...$params);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Branding settings updated successfully!";
            
            // Build structured log details showing what changed
            $changes = [];

            if ($old_branding['company_name'] != $company_name) {
                $changes['company_name'] = ['from' => $old_branding['company_name'], 'to' => $company_name];
            }
            if ($old_branding['web_name'] != $web_name) {
                $changes['web_name'] = ['from' => $old_branding['web_name'], 'to' => $web_name];
            }
            if ($old_branding['address'] != $address) {
                $changes['address'] = ['from' => $old_branding['address'], 'to' => $address];
            }
            if ($old_branding['hotline'] != $hotline) {
                $changes['hotline'] = ['from' => $old_branding['hotline'], 'to' => $hotline];
            }
            if ($old_branding['email'] != $email) {
                $changes['email'] = ['from' => $old_branding['email'], 'to' => $email];
            }
            if ($old_branding['delivery_fee'] != $delivery_fee) {
                $changes['delivery_fee'] = ['from' => $old_branding['delivery_fee'], 'to' => $delivery_fee];
            }
            if ($old_branding['primary_color'] != $primary_color) {
                $changes['primary_color'] = ['from' => $old_branding['primary_color'], 'to' => $primary_color];
            }
            if ($old_branding['secondary_color'] != $secondary_color) {
                $changes['secondary_color'] = ['from' => $old_branding['secondary_color'], 'to' => $secondary_color];
            }
            if ($old_branding['font_family'] != $font_family) {
                $changes['font_family'] = ['from' => $old_branding['font_family'], 'to' => $font_family];
            }
            if ($logo_uploaded) {
                $old_logo = !empty($old_branding['logo_url']) ? basename($old_branding['logo_url']) : 'None';
                $new_logo = basename($logo_url);
                $changes['logo'] = ['from' => $old_logo, 'to' => $new_logo];
            }
            if ($favicon_uploaded) {
                $old_favicon = !empty($old_branding['fav_icon_url']) ? basename($old_branding['fav_icon_url']) : 'None';
                $new_favicon = basename($fav_icon_url);
                $changes['favicon'] = ['from' => $old_favicon, 'to' => $new_favicon];
            }
            
            // Log only if there were actual changes
            if (!empty($changes)) {
                $log_details = json_encode($changes);
                logUserAction($conn, $_SESSION['user_id'], 'branding_update', $log_details);
            }
        } else {
            $_SESSION['error_message'] = "Error updating branding settings: " . $stmt->error;
        }
        $stmt->close();
    } else {
        // INSERT new record
        $insert_sql = "INSERT INTO branding (company_name, web_name, address, hotline, email, logo_url, fav_icon_url, delivery_fee, primary_color, secondary_color, font_family, tenant_id, active) 
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";
        
        $stmt = $conn->prepare($insert_sql);
        // 11 's' for the non-active fields + 'i' for tenant_id
        $stmt->bind_param("sssssssssssi", 
            $company_name, $web_name, $address, $hotline, $email, $logo_url, $fav_icon_url, 
            $delivery_fee, $primary_color, $secondary_color, $font_family, $target_tenant_id
        );

        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Branding settings saved successfully! Note: Only one branding record is supported.";
            
            // Create simple log for first-time creation
            $log_details = "Branding created: Company Name '{$company_name}', Website '{$web_name}'";
            
            logUserAction($conn, $_SESSION['user_id'], 'branding_create', $log_details);
        } else {
            $_SESSION['error_message'] = "Error saving branding settings: " . $stmt->error;
        }
        $stmt->close();
    }
    
    // Redirect to prevent form resubmission (Post-Redirect-Get)
    $redirect_url = $_SERVER['PHP_SELF'];
    if ($is_main_admin && !empty($target_tenant_id)) {
        $redirect_url .= "?tenant_filter=" . $target_tenant_id;
    }
    header("Location: " . $redirect_url);
    exit();
}

// STEP 4: Fetch current branding settings for form pre-filling

$branding = array(
    'company_name' => '',
    'web_name' => '',
    'address' => '',
    'hotline' => '',
    'email' => '',
    'logo_url' => '',
    'fav_icon_url' => '',
    'delivery_fee' => '0.00',
    'primary_color' => '#1C5B5D', // Default color if none set
    'secondary_color' => '#D8E2DC', // Default color if none set
    'font_family' => 'Inter' // Default font
);

$query = "SELECT * FROM branding WHERE tenant_id = ? LIMIT 1";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $target_tenant_id);
$stmt->execute();
$result = $stmt->get_result();


if ($result && $result->num_rows > 0) {
    $branding = $result->fetch_assoc();
    // Ensure colors have a default if they are NULL in DB for color picker to work
    $branding['primary_color'] = $branding['primary_color'] ?? '#1C5B5D';
    $branding['secondary_color'] = $branding['secondary_color'] ?? '#D8E2DC';
    $branding['font_family'] = $branding['font_family'] ?? 'Inter';
    $branding['delivery_fee'] = $branding['delivery_fee'] ?? '0.00';
}

// Include navbar and sidebar
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/sidebar.php');
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <!-- TITLE -->
    <title>Branding Settings - Order Management Admin Portal</title>
    
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/head.php'); ?>
    
    <!-- [Template CSS Files] - Assuming this loads your styling -->
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <style>
        /* Minimal CSS for form clarity */
        .form-control {
            border: 1px solid #ccc;
            padding: 8px 12px;
            border-radius: 4px;
            width: 100%;
            box-sizing: border-box;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 10px;
        }
        .form-column {
            flex: 1;
        }
        .file-preview {
            max-width: 100px;
            height: auto;
            border: 1px solid #eee;
            margin-top: 10px;
        }
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .alert-success {
            color: #155724;
            background-color: #d4edda;
            border-color: #c3e6cb;
        }
        .alert-danger {
            color: #721c24;
            background-color: #f8d7da;
            border-color: #f5c6cb;
        }
    </style>

    <link rel="icon" href="<?php echo !empty($branding['fav_icon_url']) ? $branding['fav_icon_url'] : '/order_management/dist/assets/images/favicon.ico'; ?>" type="image/x-icon">
</head>

<body>
    <!-- [ Main Content ] start -->
    <div class="pc-container">
        <div class="pc-content">
            <!-- [ breadcrumb ] start -->
            <!-- ... breadcrumb code here ... -->
            <!-- [ breadcrumb ] end -->
            
            <div class="row">
                <div class="col-xl-12">
                    <div class="card">
                        <div class="card-header">
                            <h5>Branding Settings</h5>
                        </div>
                        <div class="card-body">
                            
                            <?php if ($is_main_admin): ?>
                            <div class="mb-4">
                                <form method="GET" action="" id="tenantFilterForm">
                                    <div class="form-group col-md-4">
                                        <label for="tenant_filter" class="form-label">Select Tenant to Manage Branding</label>
                                        <select name="tenant_filter" id="tenant_filter" class="form-select" onchange="this.form.submit()">
                                            <?php foreach ($tenants_list as $tenant): ?>
                                                <option value="<?php echo $tenant['tenant_id']; ?>" <?php echo $target_tenant_id == $tenant['tenant_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($tenant['company_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </form>
                            </div>
                            <hr>
                            <?php endif; ?>

                            
                            <?php if ($success_message): ?>
                                <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
                            <?php endif; ?>
                            
                            <?php if ($error_message): ?>
                                <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
                            <?php endif; ?>
                            
                            <!-- STEP 4: The Form Structure (CRITICAL FOR SUBMISSION) -->
                            <!-- IMPORTANT: use method="POST" and enctype="multipart/form-data" for file uploads -->
                            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . ($is_main_admin ? '?tenant_filter=' . $target_tenant_id : ''); ?>" enctype="multipart/form-data">

                                
                                <!-- CSRF Token for security -->
                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                                <div class="form-row">
                                    <!-- Company Name -->
                                    <div class="form-column">
                                        <div class="form-group">
                                            <label for="company_name" class="form-label">Company Name *</label>
                                            <input type="text" class="form-control" id="company_name" name="company_name" 
                                                   value="<?php echo htmlspecialchars($branding['company_name']); ?>" required>
                                        </div>
                                    </div>
                                    <!-- Website Name -->
                                    <div class="form-column">
                                        <div class="form-group">
                                            <label for="web_name" class="form-label">Website Name</label>
                                            <input type="text" class="form-control" id="web_name" name="web_name" 
                                                   value="<?php echo htmlspecialchars($branding['web_name']); ?>">
                                        </div>
                                    </div>
                                </div>

                                <!-- Address -->
                                <div class="form-group">
                                    <label for="address" class="form-label">Company Address</label>
                                    <textarea class="form-control" id="address" name="address" rows="3"><?php echo htmlspecialchars($branding['address']); ?></textarea>
                                </div>
                                
                                <div class="form-row">
                                    <!-- Hotline -->
                                    <div class="form-column">
                                        <div class="form-group">
                                            <label for="hotline" class="form-label">Hotline</label>
                                            <input type="text" class="form-control" id="hotline" name="hotline" 
                                                   value="<?php echo htmlspecialchars($branding['hotline']); ?>">
                                        </div>
                                    </div>
                                    <!-- Email -->
                                    <div class="form-column">
                                        <div class="form-group">
                                            <label for="email" class="form-label">Email Address</label>
                                            <input type="email" class="form-control" id="email" name="email" 
                                                   value="<?php echo htmlspecialchars($branding['email']); ?>">
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Delivery Fee -->
                                <div class="form-row">
                                    <div class="form-column">
                                        <div class="form-group">
                                            <label for="delivery_fee" class="form-label">Delivery Fee (decimal)</label>
                                            <input type="number" step="0.01" class="form-control" id="delivery_fee" name="delivery_fee" 
                                                   value="<?php echo htmlspecialchars($branding['delivery_fee']); ?>">
                                        </div>
                                    </div>
                                    <div class="form-column">
                                        <!-- Spacer or another field -->
                                    </div>
                                </div>
                            
                                
                                <h6 class="mb-3 mt-4">Logos and Icons</h6>
                                
                                <div class="form-row">
                                    <!-- Logo Upload -->
                                    <div class="form-column">
                                        <div class="form-group">
                                            <label for="logo" class="form-label">Main Logo (JPEG, PNG, GIF)</label>
                                            <input type="file" class="form-control" id="logo" name="logo" accept=".jpg,.jpeg,.png,.gif">
                                            <?php if (!empty($branding['logo_url'])): ?>
                                                <p class="mt-2">Current Logo:</p>
                                                <img src="<?php echo htmlspecialchars($branding['logo_url']); ?>" alt="Current Logo" class="file-preview">
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <!-- Favicon Upload -->
                                    <div class="form-column">
                                        <div class="form-group">
                                            <label for="fav_icon" class="form-label">Fav Icon (ICO, PNG, JPG)</label>
                                            <input type="file" class="form-control" id="fav_icon" name="fav_icon" accept=".ico,.jpg,.jpeg,.png">
                                            <?php if (!empty($branding['fav_icon_url'])): ?>
                                                <p class="mt-2">Current Favicon:</p>
                                                <img src="<?php echo htmlspecialchars($branding['fav_icon_url']); ?>" alt="Current Favicon" class="file-preview">
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-end mt-4">
                                    <!-- This button submits the form -->
                                    <button type="submit" class="btn btn-primary" name="submit_branding">
                                        Save Branding Settings
                                    </button>
                                </div>
                            </form>

                        </div>
                    </div>
                </div>
            </div>
            
        </div>
    </div>
    <!-- [ Main Content ] end -->
    
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/footer.php'); ?>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/scripts.php'); ?>
</body>
</html>

<?php
// Close the database connection
$conn->close();
?>