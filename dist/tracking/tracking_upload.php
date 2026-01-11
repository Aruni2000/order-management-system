<?php
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

// Function to remove BOM and clean CSV headers
function cleanCsvHeader($header) {
    // Remove BOM if present
    $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);
    // Remove any other invisible characters and trim
    $header = trim(preg_replace('/[\x00-\x1F\x7F-\x9F]/u', '', $header));
    return $header;
}

// Function to normalize column names for flexible matching
function normalizeColumnName($name) {
    // Clean the name first
    $name = cleanCsvHeader($name);
    // Convert to lowercase and remove special characters for matching
    return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $name));
}

// Tracking number validation function
function validateTrackingNumber($trackingNumber) {
    if (empty($trackingNumber)) return ['valid' => false, 'message' => 'Tracking number is required'];
    
    // Remove extra spaces
    $cleanTracking = trim($trackingNumber);
    
    // Check tracking number length
    if (strlen($cleanTracking) < 5) {
        return ['valid' => false, 'message' => 'Tracking number must be at least 5 characters'];
    }
    
    if (strlen($cleanTracking) > 50) {
        return ['valid' => false, 'message' => 'Tracking number cannot exceed 50 characters'];
    }
    
    // Check for valid characters (alphanumeric, hyphens, underscores)
    if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $cleanTracking)) {
        return ['valid' => false, 'message' => 'Tracking number contains invalid characters'];
    }
    
    return ['valid' => true, 'clean_tracking' => $cleanTracking];
}

// Function to check if tracking number already exists for the same courier and tenant
function checkTrackingNumberExistsForCourier($trackingNumber, $courierId, $tenantId, $conn) {
    $checkSql = "SELECT tracking_id FROM tracking WHERE tracking_id = ? AND courier_id = ? AND tenant_id = ? LIMIT 1";
    $checkStmt = $conn->prepare($checkSql);
    if (!$checkStmt) {
        return ['exists' => false, 'error' => 'Database error while checking tracking number'];
    }
    
    $checkStmt->bind_param("sii", $trackingNumber, $courierId, $tenantId);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    $exists = $result && $result->num_rows > 0;
    $checkStmt->close();
    
    return ['exists' => $exists, 'error' => null];
}

// Function to log user actions
function logUserAction($conn, $userId, $actionType, $orderId, $details) {
    $logSql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, ?, ?, ?, NOW())";
    $logStmt = $conn->prepare($logSql);
    
    if (!$logStmt) {
        error_log("Failed to prepare user log statement: " . $conn->error);
        return false;
    }
    
    $logStmt->bind_param("isis", $userId, $actionType, $orderId, $details);
    $result = $logStmt->execute();
    
    if (!$result) {
        error_log("Failed to log user action: " . $logStmt->error);
    }
    
    $logStmt->close();
    return $result;
}

// Function to get courier name by ID
function getCourierName($conn, $courierId) {
    $sql = "SELECT courier_name FROM couriers WHERE courier_id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        return "Unknown Courier";
    }
    
    $stmt->bind_param("i", $courierId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row['courier_name'];
    } else {
        $stmt->close();
        return "Unknown Courier";
    }
}

// Function to get tenant name by ID
function getTenantName($conn, $tenantId) {
    $sql = "SELECT company_name FROM tenants WHERE tenant_id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        return "Unknown Tenant";
    }
    
    $stmt->bind_param("i", $tenantId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row['company_name'];
    } else {
        $stmt->close();
        return "Unknown Tenant";
    }
}

// Function to validate entire row data
function validateRowData($rowData, $rowNumber, $courierId, $tenantId, $conn) {
    $errors = [];
    $cleanData = [];
    
    // Validate Tracking Number (Required)
    $trackingValidation = validateTrackingNumber($rowData['tracking_number']);
    if (!$trackingValidation['valid']) {
        $errors[] = "Row $rowNumber: " . $trackingValidation['message'];
    } else {
        $cleanData['tracking_number'] = $trackingValidation['clean_tracking'];
        
        // Check if tracking number already exists for THIS courier IN THIS TENANT
        $existsCheck = checkTrackingNumberExistsForCourier($trackingValidation['clean_tracking'], $courierId, $tenantId, $conn);
        if ($existsCheck['error']) {
            $errors[] = "Row $rowNumber: " . $existsCheck['error'];
        } elseif ($existsCheck['exists']) {
            $errors[] = "Row $rowNumber: Tracking number '{$trackingValidation['clean_tracking']}' already exists for this courier in the selected tenant";
        }
    }
    
    return ['errors' => $errors, 'clean_data' => $cleanData];
}

// Function to get tenants based on permission
function getTenants($conn, $is_main_admin, $role_id, $session_tenant_id) {
    $tenants = [];
    
    if ($is_main_admin === 1 && $role_id === 1) {
        // Main Admin gets all active tenants
        $sql = "SELECT tenant_id, company_name FROM tenants WHERE status = 'active' ORDER BY company_name";
    } else {
        // Others get only their assigned tenant
        $sql = "SELECT tenant_id, company_name FROM tenants WHERE tenant_id = $session_tenant_id AND status = 'active' LIMIT 1";
    }
    
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $tenants[] = $row;
        }
    }
    
    return $tenants;
}

// Initialize variables
$successCount = 0;
$errorCount = 0;
$skippedCount = 0;
$errors = [];
$warnings = [];
$rowNumber = 2;

// Access Control Variables
$is_main_admin = isset($_SESSION['is_main_admin']) ? (int)$_SESSION['is_main_admin'] : 0;
$role_id = isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : 0;
$session_tenant_id = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;

// Get tenants based on permissions
$tenants = getTenants($conn, $is_main_admin, $role_id, $session_tenant_id);

// If user is restricted to one tenant, pre-select it
$restricted_tenant_id = 0;
if (!($is_main_admin === 1 && $role_id === 1) && !empty($tenants)) {
    $restricted_tenant_id = $tenants[0]['tenant_id'];
}

// Process CSV file if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file']) && isset($_POST['tenant_id']) && isset($_POST['courier_id'])) {
    
    // Validate tenant selection
    $selectedTenantId = intval($_POST['tenant_id']);
    
    // Security: Enforce tenant restriction on backend
    if ($restricted_tenant_id > 0 && $selectedTenantId !== $restricted_tenant_id) {
        // Force the restricted tenant ID if they try to bypass
        $selectedTenantId = $restricted_tenant_id;
    }

    if ($selectedTenantId <= 0) {
        $_SESSION['import_error'] = 'Please select a valid tenant.';
        ob_end_clean();
        header("Location: tracking_upload.php");
        exit();
    }
    
    // Verify tenant exists
    $tenantCheckSql = "SELECT tenant_id FROM tenants WHERE tenant_id = ? AND status = 'active' LIMIT 1";
    $tenantCheckStmt = $conn->prepare($tenantCheckSql);
    $tenantCheckStmt->bind_param("i", $selectedTenantId);
    $tenantCheckStmt->execute();
    $tenantResult = $tenantCheckStmt->get_result();
    
    if (!$tenantResult || $tenantResult->num_rows === 0) {
        $_SESSION['import_error'] = 'Selected tenant does not exist or is inactive.';
        $tenantCheckStmt->close();
        ob_end_clean();
        header("Location: tracking_upload.php");
        exit();
    }
    $tenantCheckStmt->close();
    
    // Validate courier selection
    $selectedCourierId = intval($_POST['courier_id']);
    if ($selectedCourierId <= 0) {
        $_SESSION['import_error'] = 'Please select a valid courier.';
        ob_end_clean();
        header("Location: tracking_upload.php");
        exit();
    }
    
    // Verify courier exists AND belongs to selected tenant
    $courierCheckSql = "SELECT courier_id FROM couriers WHERE courier_id = ? AND tenant_id = ? AND status = 'active' LIMIT 1";
    $courierCheckStmt = $conn->prepare($courierCheckSql);
    $courierCheckStmt->bind_param("ii", $selectedCourierId, $selectedTenantId);
    $courierCheckStmt->execute();
    $courierResult = $courierCheckStmt->get_result();
    
    if (!$courierResult || $courierResult->num_rows === 0) {
        $_SESSION['import_error'] = 'Selected courier does not exist or does not belong to the selected tenant.';
        $courierCheckStmt->close();
        ob_end_clean();
        header("Location: tracking_upload.php");
        exit();
    }
    $courierCheckStmt->close();
    
    // Get current user ID for logging
    $currentUserId = $_SESSION['user_id'] ?? null;
    if (!$currentUserId) {
        $_SESSION['import_error'] = 'User session not found. Please login again.';
        ob_end_clean();
        header("Location: /order_management/dist/pages/login.php");
        exit();
    }
    
    // Check if file was uploaded without errors
    if ($_FILES['csv_file']['error'] == UPLOAD_ERR_OK) {
        $csvFile = $_FILES['csv_file']['tmp_name'];
        
        // Validate file type
        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($fileInfo, $csvFile);
        finfo_close($fileInfo);
        
        if (!in_array($mimeType, ['text/csv', 'text/plain', 'application/csv'])) {
            $_SESSION['import_error'] = 'Invalid file type. Please upload a CSV file.';
            ob_end_clean();
            header("Location: tracking_upload.php");
            exit();
        }
        
        // Validate file size (max 5MB)
        if ($_FILES['csv_file']['size'] > 5 * 1024 * 1024) {
            $_SESSION['import_error'] = 'File size too large. Maximum allowed size is 5MB.';
            ob_end_clean();
            header("Location: tracking_upload.php");
            exit();
        }
        
        // Process the CSV file
        if (($handle = fopen($csvFile, "r")) !== FALSE) {
            // Read the first line and clean headers
            $rawHeader = fgetcsv($handle);
            if ($rawHeader === FALSE) {
                $_SESSION['import_error'] = 'Could not read CSV headers. Please ensure the file is a valid CSV.';
                fclose($handle);
                ob_end_clean();
                header("Location: tracking_upload.php");
                exit();
            }
            
            // Check if CSV is empty
            if (empty($rawHeader) || (count($rawHeader) == 1 && empty(trim($rawHeader[0])))) {
                $_SESSION['import_error'] = 'CSV file appears to be empty or has no headers.';
                fclose($handle);
                ob_end_clean();
                header("Location: tracking_upload.php");
                exit();
            }
            
            // Clean headers from BOM and invisible characters
            $header = array_map('cleanCsvHeader', $rawHeader);
            
            // Define flexible column mappings for tracking number
            $columnMappings = [
                'tracking_number' => ['trackingnumber', 'tracking_number', 'tracking', 'track', 'trackno', 'track_no', 'trackingid', 'tracking_id']
            ];
            
            // Build field mapping based on actual CSV headers
            $fieldMap = [];
            $foundColumns = [];
            
            // Create normalized header mapping
            $normalizedHeaders = [];
            foreach ($header as $index => $headerName) {
                $normalizedHeaders[$index] = [
                    'original' => $headerName,
                    'normalized' => normalizeColumnName($headerName)
                ];
            }
            
            foreach ($columnMappings as $dbField => $possibleNames) {
                $found = false;
                foreach ($possibleNames as $possibleNormalized) {
                    foreach ($normalizedHeaders as $index => $headerInfo) {
                        if ($headerInfo['normalized'] === $possibleNormalized) {
                            $fieldMap[$index] = $dbField;
                            $foundColumns[] = $headerInfo['original'];
                            $found = true;
                            break 2;
                        }
                    }
                }
                
                // Check required fields
                if (!$found && $dbField === 'tracking_number') {
                    $_SESSION['import_error'] = "Required column \"Tracking Number\" not found.<br>" .
                                              'Found columns: ' . implode(', ', $header) . '<br>' .
                                              'Please ensure you have a column for tracking numbers.';
                    fclose($handle);
                    ob_end_clean();
                    header("Location: tracking_upload.php");
                    exit();
                }
            }
            
            // Pre-validation: Check if CSV has data rows
            $tempRowCount = 0;
            $currentPos = ftell($handle);
            while (($tempData = fgetcsv($handle)) !== FALSE) {
                if (!empty(array_filter($tempData))) {
                    $tempRowCount++;
                }
            }
            fseek($handle, $currentPos);
            
            if ($tempRowCount === 0) {
                $_SESSION['import_error'] = 'CSV file has no data rows to process.';
                fclose($handle);
                ob_end_clean();
                header("Location: tracking_upload.php");
                exit();
            }
            
            // Prepare SQL statement to insert tracking numbers WITH tenant_id
            $insertTrackingSql = "INSERT INTO tracking (tenant_id, tracking_id, courier_id, status, created_at, updated_at) VALUES (?, ?, ?, 'unused', NOW(), NOW())";
            $insertTrackingStmt = $conn->prepare($insertTrackingSql);
            
            if (!$insertTrackingStmt) {
                $_SESSION['import_error'] = 'Database prepare error: ' . $conn->error;
                fclose($handle);
                ob_end_clean();
                header("Location: tracking_upload.php");
                exit();
            }
            
            // Process each row of the CSV
            while (($data = fgetcsv($handle)) !== FALSE) {
                // Skip completely empty rows
                if (empty(array_filter($data))) {
                    $skippedCount++;
                    $rowNumber++;
                    continue;
                }
                
                // Initialize tracking data
                $trackingData = [
                    'tracking_number' => ''
                ];
                
                // Map CSV data to fields
                foreach ($fieldMap as $csvIndex => $dbField) {
                    if (isset($data[$csvIndex])) {
                        $trackingData[$dbField] = trim($data[$csvIndex]);
                    }
                }
                
                // Validate row data
                $validation = validateRowData($trackingData, $rowNumber, $selectedCourierId, $selectedTenantId, $conn);
                
                if (!empty($validation['errors'])) {
                    $errors = array_merge($errors, $validation['errors']);
                    $errorCount++;
                    $rowNumber++;
                    continue;
                }
                
                // Use cleaned data
                $trackingData = array_merge($trackingData, $validation['clean_data']);
                
                // Insert tracking number
                try {
                    $insertTrackingStmt->bind_param("isi", $selectedTenantId, $trackingData['tracking_number'], $selectedCourierId);
                    
                    if ($insertTrackingStmt->execute()) {
                        $successCount++;
                    } else {
                        $errors[] = "Row $rowNumber: Database error - " . $insertTrackingStmt->error;
                        $errorCount++;
                    }
                } catch (Exception $e) {
                    $errors[] = "Row $rowNumber: Error - " . $e->getMessage();
                    $errorCount++;
                }
                
                $rowNumber++;
                
                // Limit error messages to prevent memory issues
                if (count($errors) > 100) {
                    $errors[] = "Too many errors. Processing stopped to prevent memory issues.";
                    break;
                }
            }
            
            // Close statement
            $insertTrackingStmt->close();
            
            // Log the tracking upload activity
            if ($successCount > 0) {
                $courierName = getCourierName($conn, $selectedCourierId);
                $tenantName = getTenantName($conn, $selectedTenantId);
                $logDetails = "Tracking CSV upload: {$successCount} tracking numbers uploaded for Tenant: {$tenantName} (ID: {$selectedTenantId}), Courier: {$courierName} (ID: {$selectedCourierId})";
                
                if (!logUserAction($conn, $currentUserId, 'tracking', 0, $logDetails)) {
                    error_log("Failed to log tracking upload action for user ID: " . $currentUserId);
                }
            }
            
            fclose($handle);
            
            // Store results in session
            $_SESSION['import_result'] = [
                'success' => $successCount,
                'errors' => $errorCount,
                'skipped' => $skippedCount,
                'messages' => array_slice($errors, 0, 50),
                'warnings' => array_slice($warnings, 0, 20)
            ];
        
            ob_end_clean();
            header("Location: tracking_upload.php");
            exit();
        } else {
            $_SESSION['import_error'] = 'Could not read the uploaded file. Please ensure it is a valid CSV file.';
            ob_end_clean();
            header("Location: tracking_upload.php");
            exit();
        }
    } else {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE => 'File too large (exceeds upload_max_filesize)',
            UPLOAD_ERR_FORM_SIZE => 'File too large (exceeds MAX_FILE_SIZE)',
            UPLOAD_ERR_PARTIAL => 'File upload was only partial',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
        ];
        
        $errorMessage = $uploadErrors[$_FILES['csv_file']['error']] ?? 'Unknown upload error';
        $_SESSION['import_error'] = 'File upload error: ' . $errorMessage;
        ob_end_clean();
        header("Location: tracking_upload.php");
        exit();
    }
}

// Include UI files
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/sidebar.php');
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Order Management Admin Portal - Tracking CSV Upload</title>
    
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/head.php'); ?>
    
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/leads.css" id="main-style-link" />
</head>

<body>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/loader.php'); ?>

    <div class="pc-container">
        <div class="pc-content">
            
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">Tracking Numbers Upload</h5>
                    </div>
                </div>
            </div>
            <div class="main-content-wrapper">
                <div class="info-box">
                    <h4>📋 Instructions</h4>
                    <p><strong>How to upload tracking numbers:</strong></p>
                    <ul>
                        <li>Select a tenant from the dropdown menu</li>
                        <li>Select a courier belonging to the selected tenant</li>
                        <li>Download the CSV template below</li>
                        <li>Fill in your tracking numbers in the template</li>
                        <li>Upload the completed CSV file</li>
                        <li>All tracking numbers will be added with 'unused' status</li>
                    </ul>
                    <p><strong>CSV Format Requirements:</strong></p>
                    <ul>
                        <li>Must have a header row with 'Tracking Number' column</li>
                        <li>Tracking numbers must be 5-50 characters long</li>
                        <li>Only alphanumeric characters, hyphens, and underscores allowed</li>
                        <li>Maximum file size: 5MB</li>
                        <li>Tracking numbers are unique per courier within each tenant</li>
                    </ul>
                </div>

                <?php if (isset($_SESSION['import_result'])): ?>
                    <div class="alert alert-<?php echo $_SESSION['import_result']['errors'] > 0 ? 'warning' : 'success'; ?>">
                        <h4>Processing Results</h4>
                        <p><strong>Successfully added:</strong> <?php echo $_SESSION['import_result']['success']; ?> tracking numbers</p>
                        <?php if ($_SESSION['import_result']['skipped'] > 0): ?>
                            <p><strong>Skipped:</strong> <?php echo $_SESSION['import_result']['skipped']; ?> empty rows</p>
                        <?php endif; ?>
                        <?php if ($_SESSION['import_result']['errors'] > 0): ?>
                            <p><strong>Failed:</strong> <?php echo $_SESSION['import_result']['errors']; ?> tracking numbers</p>
                            <?php if (!empty($_SESSION['import_result']['messages'])): ?>
                                <details>
                                    <summary>View Error Details</summary>
                                    <ul class="mt-2">
                                        <?php foreach ($_SESSION['import_result']['messages'] as $message): ?>
                                            <li><?php echo htmlspecialchars($message); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php unset($_SESSION['import_result']); ?>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['import_error'])): ?>
                    <div class="alert alert-danger">
                        <strong>Error:</strong> <?php echo $_SESSION['import_error']; ?>
                    </div>
                    <?php unset($_SESSION['import_error']); ?>
                <?php endif; ?>

                <div class="lead-upload-container">
                    <form method="POST" enctype="multipart/form-data" id="uploadForm">
                        <div class="template-download-section">
                            <a href="/order_management/dist/templates/tracking_csv.php" class="template-download-btn">
                                 Download CSV Template
                            </a>
                        </div>

                        <div class="form-container">
                            <div class="tenant-section">
                                <label for="tenant_id" class="form-label">Select Tenant <span class="required">*</span></label>
                                <?php if ($restricted_tenant_id > 0): ?>
                                    <!-- Restricted View: Read-only dropdown or hidden input -->
                                    <select id="tenant_id" name="tenant_id" class="form-select" readonly style="pointer-events: none; background-color: #e9ecef;">
                                        <?php foreach ($tenants as $tenant): ?>
                                            <option value="<?php echo $tenant['tenant_id']; ?>" selected>
                                                <?php echo htmlspecialchars($tenant['company_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <!-- Main Admin View: Full Selection -->
                                    <select id="tenant_id" name="tenant_id" class="form-select" required>
                                        <option value="">Select Tenant</option>
                                        <?php foreach ($tenants as $tenant): ?>
                                            <option value="<?php echo $tenant['tenant_id']; ?>">
                                                <?php echo htmlspecialchars($tenant['company_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>
                            
                            <div class="courier-section">
                                <label for="courier_id" class="form-label">Select Courier <span class="required">*</span></label>
                                <select id="courier_id" name="courier_id" class="form-select" required disabled>
                                    <option value=""> Select Tenant First </option>
                                </select>
                            </div>

                            <div class="file-section">
                                <label class="form-label">CSV File <span class="required">*</span></label>
                                <div class="file-input-wrapper">
                                    <input type="file" id="csv_file" name="csv_file" accept=".csv" class="file-input" required>
                                    <div class="file-display">
                                        <span id="file-name">No file chosen</span>
                                        <button type="button" class="file-btn" onclick="document.getElementById('csv_file').click()">
                                            Choose File
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="action-buttons">
                            <button type="button" class="action-btn reset-btn" id="resetBtn">Reset</button>
                            <button type="submit" class="action-btn import-btn" id="importBtn">
                                Upload Tracking Numbers
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/footer.php'); ?>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/scripts.php'); ?>
    
    <script>
     // Load couriers when tenant is selected
document.getElementById('tenant_id').addEventListener('change', function() {
    const tenantId = this.value;
    const courierSelect = document.getElementById('courier_id');
    
    courierSelect.innerHTML = '<option value="">Loading Couriers...</option>';
    courierSelect.disabled = true;
    
    if (tenantId) {
        fetch('get_couriers_by_tenant.php?tenant_id=' + tenantId)
            .then(response => response.json())
            .then(data => {
                courierSelect.innerHTML = '<option value="">Select Courier</option>';
                
                if (data.success && data.couriers.length > 0) {
                    data.couriers.forEach(courier => {
                        const option = document.createElement('option');
                        option.value = courier.courier_id;
                        // Display courier name with ID
                        option.textContent = courier.courier_name + ' (ID: ' + courier.courier_id + ')';
                        courierSelect.appendChild(option);
                    });
                    courierSelect.disabled = false;
                } else {
                    courierSelect.innerHTML = '<option value="">No Couriers Available</option>';
                }
            })
            .catch(error => {
                console.error('Error fetching couriers:', error);
                courierSelect.innerHTML = '<option value="">Error Loading Couriers</option>';
            });
    } else {
        courierSelect.innerHTML = '<option value="">Select Tenant First</option>';
    }
});

// Auto-load couriers if tenant is pre-selected (Restricted User)
document.addEventListener('DOMContentLoaded', function() {
    const tenantSelect = document.getElementById('tenant_id');
    if (tenantSelect.value) {
        // Trigger manual change event to load couriers
        tenantSelect.dispatchEvent(new Event('change'));
    }
});
        
        document.getElementById('resetBtn').addEventListener('click', function() {
            document.getElementById('uploadForm').reset();
            document.getElementById('file-name').textContent = 'No file chosen';
            document.getElementById('courier_id').innerHTML = '<option value="">Select Tenant First </option>';
            document.getElementById('courier_id').disabled = true;
            
            const importBtn = document.getElementById('importBtn');
            importBtn.disabled = false;
            importBtn.innerHTML = ' Upload Tracking Numbers';
        });

        document.getElementById('uploadForm').addEventListener('submit', function(e) {
            const tenantSelect = document.getElementById('tenant_id');
            const courierSelect = document.getElementById('courier_id');
            const fileInput = document.getElementById('csv_file');
            
            if (!tenantSelect.value) {
                e.preventDefault();
                alert('Please select a tenant before proceeding.');
                tenantSelect.focus();
                return false;
            }
            
            if (!courierSelect.value) {
                e.preventDefault();
                alert('Please select a courier before proceeding.');
                courierSelect.focus();
                return false;
            }
            
            if (!fileInput.files.length) {
                e.preventDefault();
                alert('Please upload the CSV file before proceeding.');
                return false;
            }
            
            const file = fileInput.files[0];
            const validExtensions = ['.csv'];
            const fileName = file.name.toLowerCase();
            const isValidExtension = validExtensions.some(ext => fileName.endsWith(ext));
            
            if (!isValidExtension) {
                e.preventDefault();
                alert('Please upload a valid CSV file.');
                return false;
            }
            
            const maxSize = 5 * 1024 * 1024;
            if (file.size > maxSize) {
                e.preventDefault();
                alert('File size must be less than 5MB. Please upload a smaller CSV file.');
                return false;
            }
            
            const importBtn = document.getElementById('importBtn');
            importBtn.disabled = true;
            importBtn.innerHTML = '⏳ Processing...';
            
            return true;
        });
        
        document.getElementById('csv_file').addEventListener('change', function() {
            const file = this.files[0];
            const fileNameEl = document.getElementById('file-name');
            
            if (file) {
                const validExtensions = ['.csv'];
                const fileName = file.name.toLowerCase();
                const isValidExtension = validExtensions.some(ext => fileName.endsWith(ext));
                
                if (!isValidExtension) {
                    alert('Please select a valid CSV file.');
                    this.value = '';
                    fileNameEl.textContent = 'No file chosen';
                    return;
                }
                
                // Check file size (5MB limit)
                const maxSize = 5 * 1024 * 1024; // 5MB in bytes
                if (file.size > maxSize) {
                    alert('File size must be less than 5MB.');
                    this.value = '';
                    fileNameEl.textContent = 'No file chosen';
                    return;
                }
                
                fileNameEl.textContent = file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
            } else {
                fileNameEl.textContent = 'No file chosen';
            }
        });
    </script>
</body>
</html>