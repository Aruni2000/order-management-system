<?php
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

// Handle AJAX request for fetching couriers
if (isset($_GET['action']) && $_GET['action'] === 'get_couriers' && isset($_GET['tenant_id'])) {
    header('Content-Type: application/json');
    $tenantId = intval($_GET['tenant_id']);
    $sql = "SELECT co_id, courier_id, courier_name 
            FROM couriers 
            WHERE tenant_id = ? AND status = 'active' 
            ORDER BY courier_name ASC";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Database error']);
        exit();
    }
    
    $stmt->bind_param("i", $tenantId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $couriers = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $couriers[] = [
                'co_id' => $row['co_id'],
                'courier_id' => $row['courier_id'],
                'courier_name' => $row['courier_name'],
                'display_name' => $row['courier_name'] . ' (ID: ' . $row['courier_id'] . ')'
            ];
        }
    }
    $stmt->close();
    echo json_encode(['success' => true, 'couriers' => $couriers]);
    exit();
}

// Check if user is main admin
$is_main_admin = $_SESSION['is_main_admin'];
$role_id = $_SESSION['role_id'];
$teanent_id = $_SESSION['tenant_id'];

// FIXED: Only get co_id when form is submitted
$co_id = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['co_id'])) {
    $co_id = $_POST['co_id'];
}

// Fetch tenants for the dropdown
$tenants = [];
if ($is_main_admin === 1 && $role_id === 1) {
    // Main Admin gets all active tenants
    $tenantSql = "SELECT tenant_id, company_name FROM tenants WHERE status = 'active' ORDER BY company_name";
} else {
    // Others get only their assigned tenant
    $tenantSql = "SELECT tenant_id, company_name FROM tenants WHERE tenant_id = $teanent_id AND status = 'active' LIMIT 1";
}
$tenantResult = $conn->query($tenantSql);
if ($tenantResult && $tenantResult->num_rows > 0) {
    while ($row = $tenantResult->fetch_assoc()) {
        $tenants[] = $row;
    }
}
$restricted_tenant_id = (count($tenants) === 1 && !($is_main_admin === 1 && $role_id === 1)) ? $tenants[0]['tenant_id'] : 0;

//function for tenant name
function TenantName($tenant_id) {
    global $conn;
    $sql = "SELECT company_name FROM tenants WHERE tenant_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['company_name'];
    }
    return "Unknown Tenant";
}

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
    
    // Check tracking number length (adjust as needed for your system)
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

// Function to check if tracking number exists in order_header table with return complete status
function validateTrackingNumberInDB($trackingNumber, $conn, $co_id) {
    if (empty($trackingNumber)) return ['valid' => false, 'message' => 'Tracking number is required'];
    
    // First validate format
    $formatValidation = validateTrackingNumber($trackingNumber);
    if (!$formatValidation['valid']) {
        return $formatValidation;
    }
    
    $cleanTracking = $formatValidation['clean_tracking'];
    
    // Check if tracking number exists in database with return complete status
    if ($GLOBALS['is_main_admin'] === 1 && $GLOBALS['role_id'] === 1) {
        $findTrackingSql = "SELECT order_id, status FROM order_header WHERE tracking_number = ? AND co_id = ? LIMIT 1";
        $findTrackingStmt = $conn->prepare($findTrackingSql);
        if (!$findTrackingStmt) return ['valid' => false, 'message' => 'Database error'];
        $findTrackingStmt->bind_param("si", $cleanTracking, $co_id);
    } else {
        $findTrackingSql = "SELECT order_id, status FROM order_header WHERE tracking_number = ? AND co_id = ? AND tenant_id = ? LIMIT 1";
        $findTrackingStmt = $conn->prepare($findTrackingSql);
        if (!$findTrackingStmt) return ['valid' => false, 'message' => 'Database error'];
        $findTrackingStmt->bind_param("sii", $cleanTracking, $co_id, $GLOBALS['teanent_id']);
    }
    
    $findTrackingStmt->execute();
    $trackingResult = $findTrackingStmt->get_result();
    
    if ($trackingResult && $trackingResult->num_rows > 0) {
        $trackingRow = $trackingResult->fetch_assoc();
        $findTrackingStmt->close();
        
        // Check if the order status is 'return complete'
        if ($trackingRow['status'] !== 'return complete') {
            return [
                'valid' => false, 
                'message' => "Tracking number '{$cleanTracking}' status is '{$trackingRow['status']}' - only orders with 'return complete' status can be updated to 'return_handover'"
            ];
        }
        
        return [
            'valid' => true, 
            'clean_tracking' => $cleanTracking, 
            'order_id' => $trackingRow['order_id'],
            'current_status' => $trackingRow['status']
        ];
    } else {
        $findTrackingStmt->close();
        return ['valid' => false, 'message' => "Tracking number '{$cleanTracking}' does not exist in the database"];
    }
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

// Function to validate entire row data
function validateRowData($rowData, $rowNumber, $conn, $co_id) {
    $errors = [];
    $cleanData = [];
    
    // Validate Tracking Number (Required)
    $trackingValidation = validateTrackingNumberInDB($rowData['tracking_number'], $conn, $co_id);
    if (!$trackingValidation['valid']) {
        $errors[] = "Row $rowNumber: " . $trackingValidation['message'];
    } else {
        $cleanData['tracking_number'] = $trackingValidation['clean_tracking'];
        $cleanData['order_id'] = $trackingValidation['order_id'];
        $cleanData['current_status'] = $trackingValidation['current_status'];
    }
    
    return ['errors' => $errors, 'clean_data' => $cleanData];
}

// Initialize variables
$successCount = 0;
$errorCount = 0;
$skippedCount = 0;
$errors = [];
$warnings = [];
$rowNumber = 2; // Start from row 2 (after header)

// Process CSV file if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    
    // Validate co_id is provided
    if (empty($co_id)) {
        $_SESSION['import_error'] = 'Please select a courier before uploading the CSV file.';
        ob_end_clean();
        header("Location: return_csv_upload.php");
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
            header("Location: return_csv_upload.php");
            exit();
        }
        
        // Validate file size (max 5MB)
        if ($_FILES['csv_file']['size'] > 5 * 1024 * 1024) {
            $_SESSION['import_error'] = 'File size too large. Maximum allowed size is 5MB.';
            ob_end_clean();
            header("Location: return_csv_upload.php");
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
                header("Location: return_csv_upload.php");
                exit();
            }
            
            // Check if CSV is empty
            if (empty($rawHeader) || (count($rawHeader) == 1 && empty(trim($rawHeader[0])))) {
                $_SESSION['import_error'] = 'CSV file appears to be empty or has no headers.';
                fclose($handle);
                ob_end_clean();
                header("Location: return_csv_upload.php");
                exit();
            }
            
            // Clean headers from BOM and invisible characters
            $header = array_map('cleanCsvHeader', $rawHeader);
            
            // Define flexible column mappings for tracking number
            $columnMappings = [
                'tracking_number' => ['trackingnumber', 'tracking_number', 'tracking', 'track', 'trackno', 'track_no']
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
                            break 2; // Break both loops
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
                    header("Location: return_csv_upload.php");
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
            fseek($handle, $currentPos); // Reset file pointer
            
            if ($tempRowCount === 0) {
                $_SESSION['import_error'] = 'CSV file has no data rows to process.';
                fclose($handle);
                ob_end_clean();
                header("Location: return_csv_upload.php");
                exit();
            }
            
            // Prepare SQL statement to update order status
            if ($is_main_admin === 1 && $role_id === 1) {
                $updateOrderSql = "UPDATE order_header SET status = 'return_handover', updated_at = NOW() WHERE order_id = ? AND status = 'return complete'";
                $updateOrderStmt = $conn->prepare($updateOrderSql);
            } else {
                $updateOrderSql = "UPDATE order_header SET status = 'return_handover', updated_at = NOW() WHERE order_id = ? AND status = 'return complete' AND tenant_id = ?";
                $updateOrderStmt = $conn->prepare($updateOrderSql);
            }
            
            if (!$updateOrderStmt) {
                $_SESSION['import_error'] = 'Database error: ' . $conn->error;
                fclose($handle);
                ob_end_clean();
                header("Location: return_csv_upload.php");
                exit();
            }
            
            // Get current user ID for logging
            $currentUserId = $_SESSION['user_id'] ?? null;
            if (!$currentUserId) {
                $_SESSION['import_error'] = 'User session not found. Please login again.';
                fclose($handle);
                ob_end_clean();
                header("Location: /OMS/dist/pages/login.php");
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
                
                // Validate row data - now passing $co_id
                $validation = validateRowData($trackingData, $rowNumber, $conn, $co_id);
                
                if (!empty($validation['errors'])) {
                    $errors = array_merge($errors, $validation['errors']);
                    $errorCount++;
                    $rowNumber++;
                    continue;
                }
                
                // Use cleaned data
                $trackingData = array_merge($trackingData, $validation['clean_data']);
                
                // Double check - this should already be handled in validation, but extra safety
                if ($trackingData['current_status'] !== 'return complete') {
                    $errors[] = "Row $rowNumber: Tracking number '{$trackingData['tracking_number']}' has status '{$trackingData['current_status']}' - only 'return complete' orders can be updated";
                    $errorCount++;
                    $rowNumber++;
                    continue;
                }
                
                // Update order status
                try {
                    if ($is_main_admin === 1 && $role_id === 1) {
                        $updateOrderStmt->bind_param("i", $trackingData['order_id']);
                    } else {
                        $updateOrderStmt->bind_param("ii", $trackingData['order_id'], $teanent_id);
                    }
                    
                    if ($updateOrderStmt->execute()) {
                        // Check if any rows were actually affected
                        if ($updateOrderStmt->affected_rows > 0) {
                            $successCount++;
                            
                            // Log the successful status update with the requested format
                            $logDetails = "Return CSV bulk handover order updated with tracking: {$trackingData['tracking_number']}, Order ID: {$trackingData['order_id']}";
                            
                            if (!logUserAction($conn, $currentUserId, 'return_csv', $trackingData['order_id'], $logDetails)) {
                                // Log the error but don't stop processing
                                error_log("Failed to log user action for order ID: " . $trackingData['order_id']);
                            }
                        } else {
                            // This shouldn't happen due to our validation, but just in case
                            $errors[] = "Row $rowNumber: No update performed for tracking number '{$trackingData['tracking_number']}' - status may have changed";
                            $errorCount++;
                        }
                    } else {
                        $errors[] = "Row $rowNumber: Database error - " . $updateOrderStmt->error;
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
            $updateOrderStmt->close();
            
            // Close the CSV file
            fclose($handle);
            
            // Store results in session
            $_SESSION['import_result'] = [
                'success' => $successCount,
                'errors' => $errorCount,
                'skipped' => $skippedCount,
                'messages' => array_slice($errors, 0, 50), // Limit to first 50 error messages
                'warnings' => array_slice($warnings, 0, 20) // Limit to first 20 warnings
            ];
            
            ob_end_clean();
            header("Location: return_csv_upload.php");
            exit();
        } else {
            $_SESSION['import_error'] = 'Could not read the uploaded file. Please ensure it is a valid CSV file.';
            ob_end_clean();
            header("Location: return_csv_upload.php");
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
        header("Location: return_csv_upload.php");
        exit();
    }
}

// Include UI files after processing POST request to avoid header issues
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr"
    data-pc-theme="light">

<head>
    <title>Order Management Admin Portal - Return CSV Upload</title>

    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>

    <!-- Stylesheets -->
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/leads.css" id="main-style-link" />
</head>

<body>
    <!-- Page Loader -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/loader.php'); ?>

    <div class="pc-container">
        <div class="pc-content">

            <!-- Page Header -->
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">Return Handover Management</h5>
                    </div>
                </div>
            </div>

            <div class="main-content-wrapper">

                <!-- Display import results/errors -->
                <?php if (isset($_SESSION['import_result'])): ?>
                <div
                    class="alert alert-<?php echo $_SESSION['import_result']['errors'] > 0 ? 'warning' : 'success'; ?>">
                    <h4>Processing Results</h4>
                    <p><strong>Successfully updated to 'return_handover':</strong>
                        <?php echo $_SESSION['import_result']['success']; ?> tracking numbers</p>
                    <?php if ($_SESSION['import_result']['skipped'] > 0): ?>
                    <p><strong>Skipped:</strong> <?php echo $_SESSION['import_result']['skipped']; ?> tracking numbers
                    </p>
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
                    <?php if (!empty($_SESSION['import_result']['warnings'])): ?>
                    <details>
                        <summary>View Warnings</summary>
                        <ul class="mt-2">
                            <?php foreach ($_SESSION['import_result']['warnings'] as $warning): ?>
                            <li><?php echo htmlspecialchars($warning); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                    <?php endif; ?>
                </div>
                <script>
                    setTimeout(function() {
                        window.location.reload();
                    }, 5000);
                </script>
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
                            <a href="/OMS/dist/templates/return_csv.php" class="template-download-btn">
                                Download CSV Template
                            </a>
                        </div>

                        <div class="form-container">
                            <?php if ($restricted_tenant_id > 0): ?>
                                <input type="hidden" id="tenant_id" name="tenant_id" value="<?php echo $restricted_tenant_id; ?>">
                            <?php else: ?>
                            <div class="tenant-section">
                                <label for="tenant_id" class="form-label">Select Tenant <span class="required">*</span></label>
                                    <select id="tenant_id" name="tenant_id" class="form-select" required>
                                        <option value="">Select Tenant</option>
                                        <?php foreach ($tenants as $tenant): ?>
                                            <option value="<?php echo $tenant['tenant_id']; ?>">
                                                <?php echo htmlspecialchars($tenant['company_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                            </div>
                            <?php endif; ?>

                            <div class="courier-section">
                                <label for="co_id" class="form-label">Select Courier <span class="required">*</span></label>
                                <select id="co_id" name="co_id" class="form-select" required disabled>
                                    <option value=""> Select Tenant First </option>
                                </select>
                            </div>

                            <div class="file-section">
                                <label class="form-label">CSV File <span class="required">*</span></label>
                                <div class="file-input-wrapper">
                                    <input type="file" id="csv_file" name="csv_file" accept=".csv" class="file-input" required>
                                    <div class="file-display">
                                        <span id="file-name">No file selected</span>
                                        <button type="button" class="file-btn" onclick="document.getElementById('csv_file').click()">
                                            Choose File
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="action-buttons">
                            <button type="button" class="action-btn reset-btn" id="resetBtn"> Reset</button>
                            <button type="submit" class="action-btn import-btn" id="importBtn">
                                Update to Return Handover
                            </button>
                        </div>
                    </form>
                    <!-- Instruction Box -->
                    <div class="instruction-box">
                        <h4>📋 How to Use Return Handover CSV Upload</h4>

                        <div class="important-notes">
                            <h5>⚠️ Important Requirements:</h5>
                            <ul>
                                <li><strong>Only orders with "return complete" status</strong> will be updated</li>
                                <li>Tracking numbers must exist in the database</li>
                                <li>Maximum file size: <strong>5MB</strong></li>
                                <li>File format: <strong>CSV only</strong></li>
                                <li>Status will change from: <code>return complete</code> → <code>return_handover</code></li>
                            </ul>
                        </div>
                        
                        <div class="quick-tips">
                            <h5>💡 Quick Tips:</h5>
                            <ul>
                                <li>Check tracking numbers are correct before uploading</li>
                                <li>Remove any extra spaces or special characters</li>
                                <li>Orders with other statuses will be skipped</li>
                                <li>You'll see a detailed report after processing</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Footer -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php'); ?>

    <!-- Scripts -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php'); ?>

    <script>
        // Load couriers when tenant is selected
        document.getElementById('tenant_id').addEventListener('change', function() {
            const tenantId = this.value;
            const courierSelect = document.getElementById('co_id');
            
            courierSelect.innerHTML = '<option value="">Loading Couriers...</option>';
            courierSelect.disabled = true;
            
            if (tenantId) {
                fetch('return_csv_upload.php?action=get_couriers&tenant_id=' + tenantId)
                    .then(response => response.json())
                    .then(data => {
                        courierSelect.innerHTML = '<option value="">Select Courier</option>';
                        
                        if (data.success && data.couriers.length > 0) {
                            data.couriers.forEach(courier => {
                                const option = document.createElement('option');
                                option.value = courier.co_id;
                                option.textContent = courier.display_name;
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

        // Auto-load couriers if tenant is pre-selected
        document.addEventListener('DOMContentLoaded', function() {
            const tenantSelect = document.getElementById('tenant_id');
            if (tenantSelect && tenantSelect.value) {
                tenantSelect.dispatchEvent(new Event('change'));
            }
        });

        // Form validation
        document.getElementById('uploadForm').addEventListener('submit', function(e) {
            const tenantSelect = document.getElementById('tenant_id');
            const fileInput = document.getElementById('csv_file');
            const courierSelect = document.getElementById('co_id');
            const importBtn = document.getElementById('importBtn');
            
            if (!tenantSelect.value) {
                e.preventDefault();
                alert('Please select a tenant before proceeding.');
                tenantSelect.focus();
                return false;
            }

            if (!courierSelect.value) {
                e.preventDefault();
                alert('Please select a courier before uploading.');
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
                alert('File size must be less than 5MB.');
                return false;
            }
            
            importBtn.disabled = true;
            importBtn.innerHTML = '⏳ Processing...';
            
            return true;
        });
        
        // Reset button functionality
        document.getElementById('resetBtn').addEventListener('click', function() {
            if (confirm('Are you sure you want to reset the form?')) {
                document.getElementById('uploadForm').reset();
                document.getElementById('file-name').textContent = 'No file selected';
                
                const courierSelect = document.getElementById('co_id');
                courierSelect.innerHTML = '<option value="">Select Tenant First </option>';
                courierSelect.disabled = true;
                
                const importBtn = document.getElementById('importBtn');
                importBtn.disabled = false;
                importBtn.innerHTML = ' Update to Return Handover';

                const tenantSelect = document.getElementById('tenant_id');
                if (tenantSelect && tenantSelect.value) {
                    tenantSelect.dispatchEvent(new Event('change'));
                }
            }
        });
        
        // Show selected file name and validate file type
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
                    fileNameEl.textContent = 'No file selected';
                    return;
                }
                
                const maxSize = 5 * 1024 * 1024;
                if (file.size > maxSize) {
                    alert('File size must be less than 5MB.');
                    this.value = '';
                    fileNameEl.textContent = 'No file selected';
                    return;
                }
                
                fileNameEl.textContent = file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
            } else {
                fileNameEl.textContent = 'No file selected';
            }
        });
    </script>

    <style>
        .instruction-box {
            background-color: #e8f4fd;
            border: 1px solid #bee5eb;
            border-radius: 0.375rem;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }

        .instruction-box h4 {
            color: #0c5460;
            margin-bottom: 0.75rem;
            font-size: 1.25rem;
            font-weight: bold;
        }

        .instruction-box h5 {
            color: #0c5460;
            margin-top: 1rem;
            margin-bottom: 0.5rem;
            font-size: 1rem;
            font-weight: bold;
        }

        .instruction-box p,
        .instruction-box ul {
            color: #0c5460;
            margin-bottom: 0.5rem;
        }

        .instruction-box ul {
            list-style-type: disc;
            margin-left: 1.5rem;
            padding-left: 0;
        }

        .instruction-box li {
            margin-bottom: 0.25rem;
        }

        .instruction-box .important-notes,
        .instruction-box .quick-tips {
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid #bee5eb;
        }
        
        .file-upload-section .customer-form-group {
            margin-bottom: 1.5rem;
        }
        
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid transparent;
            border-radius: 4px;
        }
        
        .alert-success {
            color: #155724;
            background-color: #d4edda;
            border-color: #c3e6cb;
        }
        
        .alert-warning {
            color: #856404;
            background-color: #fff3cd;
            border-color: #ffeaa7;
        }
        
        .alert-danger {
            color: #721c24;
            background-color: #f8d7da;
            border-color: #f5c6cb;
        }
        
        .alert h4 {
            margin-top: 0;
            margin-bottom: 10px;
        }
        
        .alert p {
            margin: 5px 0;
        }
        
        .alert details {
            margin-top: 10px;
        }
        
        .alert summary {
            cursor: pointer;
            font-weight: bold;
            padding: 5px;
            background-color: rgba(0,0,0,0.05);
            border-radius: 3px;
        }
        
        .alert summary:hover {
            background-color: rgba(0,0,0,0.1);
        }
        
        .alert ul {
            margin: 10px 0;
            padding-left: 20px;
        }
        
        .alert li {
            margin: 5px 0;
            line-height: 1.5;
        }
    </style>
</body>

</html>