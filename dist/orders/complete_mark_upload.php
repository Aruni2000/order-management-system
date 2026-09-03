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

// Check if user is main admin
$is_main_admin = $_SESSION['is_main_admin'];

$role_id = $_SESSION['role_id'];
$session_tenant_id = $_SESSION['tenant_id'];

// Function to get tenants based on permission
function getTenants($conn, $is_main_admin, $role_id, $session_tenant_id) {
    $tenants = [];
    
    if ($is_main_admin === 1 && $role_id === 1) {
        // Main Admin gets all active tenants
        $result = $conn->query("SELECT tenant_id, company_name FROM tenants WHERE status = 'active' ORDER BY company_name");
    } else {
        // Others get only their assigned tenant
        $stmt = $conn->prepare("SELECT tenant_id, company_name FROM tenants WHERE tenant_id = ? AND status = 'active' LIMIT 1");
        $stmt->bind_param("i", $session_tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
    }
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $tenants[] = $row;
        }
    }
    
    return $tenants;
}

// Get tenants based on permissions
$tenants = getTenants($conn, $is_main_admin, $role_id, $session_tenant_id);

// If user is restricted to one tenant, pre-select it
$restricted_tenant_id = 0;
if (!($is_main_admin === 1 && $role_id === 1) && !empty($tenants)) {
    $restricted_tenant_id = $tenants[0]['tenant_id'];
}

// Include UI files after processing POST request to avoid header issues


?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr"
    data-pc-theme="light">

<head>
    <title>Delivery CSV Upload | <?= htmlspecialchars($_SESSION['company_name'] ?? '') ?></title>

    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>

    <!-- Stylesheets -->
    <link rel="stylesheet" href="../assets/css/leads.css" />
</head>

<body>
    <!-- Page Loader -->
    <?php 
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/loader.php');
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');
    ?>

    <div class="pc-container">
        <div class="pc-content">

            <!-- Page Header -->
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">
                            Delivery Complete Management
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
                toastManager.warning('Import completed: <?php echo $impSuccess; ?> updated, <?php echo $impErrors; ?> failed', 8000);
            <?php else: ?>
                toastManager.success('Successfully updated <?php echo $impSuccess; ?> orders', 5000);
            <?php endif; ?>
        });
    </script>

    <?php if ($impErrors > 0 || !empty($impInfo)): ?>
    <div style="background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 20px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">
        <?php if ($impErrors > 0): ?>
            <div style="margin-bottom: 16px;">
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
                <div style="background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 1rem; margin-bottom: 1.5rem; border-radius: 5px;">
                    <strong>Error:</strong> <?php echo htmlspecialchars($_SESSION['import_error']); ?>
                </div>
                <?php unset($_SESSION['import_error']); ?>
            <?php endif; ?>
            <div class="lead-upload-container">
                <form enctype="multipart/form-data" id="uploadForm" name="uploadForm" method="POST" action="complete_mark_upload_submit.php">
                    <!-- Download CSV Temp late Section -->
                    <div class="file-upload-section template-download-section">
                        <a href="/OMS/dist/templates/delivery_csv.php" class="choose-file-btn template-download-btn">
                            Download CSV Template
                        </a>
                        <div class="form-container">
                            <?php if ($restricted_tenant_id > 0): ?>
                            <input type="hidden" id="tenant_id" name="tenant_id" value="<?php echo $restricted_tenant_id; ?>">
                            <?php else: ?>
                            <div class="tenant-section">
                                <label for="tenant_id" class="form-label">Select Tenant <span
                                        class="required">*</span></label>
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
                                <label for="courier_id" class="form-label">Select Courier <span
                                        class="required">*</span></label>
                                <select id="courier_id" name="co_id" class="form-select" required disabled>
                                    <option value=""> Select Tenant First </option>
                                </select>
                            </div>

                            <div class="file-section">
                                <label class="form-label">CSV File <span class="required">*</span></label>
                                <div class="file-input-wrapper">
                                    <input type="file" id="csv_file" name="csv_file" accept=".csv" class="file-input">
                                    <div class="file-display">
                                        <span id="file-name">No file chosen</span>
                                        <button type="button" class="file-btn"
                                            onclick="document.getElementById('csv_file').click()">
                                            Choose File
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>


                            <div class="error-feedback" id="courier-error"></div>

                        </div>
                        <!--<div class="file-upload-box">
                            <p><strong>Select CSV File</strong></p>
                            <p id="file-name">No file selected</p>
                            <input type="file" id="csv_file" name="csv_file" accept=".csv" style="display: none;"
                                required>
                            <button type="button" class="choose-file-btn"
                                onclick="document.getElementById('csv_file').click()">
                                Choose File
                            </button>
                        </div>-->
                        <hr>
                        <!-- Action Buttons -->
                        <div class="action-buttons">
                            <button type="button" class="action-btn reset-btn" id="resetBtn"> Reset</button>
                            <button type="submit" class="action-btn import-btn" id="importBtn">
                                Update to Complete
                            </button>
                        </div>
                </form>
            </div>
        </div>
    </div>
    <?php
    include_once($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/info_modal.php');
    renderInfoModal(
        'How Delivery Completion CSV Upload Works',
        'fas fa-truck',
        '<div style="font-family: system-ui, -apple-system, sans-serif;">

        <div style="margin-bottom: 16px;">
            <h6 style="margin: 0 0 10px; font-size: 14px;">📌 3 Simple Steps</h6>
            <ol style="margin: 0; padding-left: 20px; color: #374151; font-size: 13.5px; line-height: 1.8;">
                <li><strong>Download the template</strong></li>
                <li><strong>Pick a tenant & courier</strong>, then upload your CSV</li>
                <li>Click <strong>Update to Complete</strong> to process</li>
            </ol>
        </div>

        <div style="margin-bottom: 16px;">
            <h6 style="margin: 0 0 10px; font-size: 14px;">✅ Requirements</h6>
            <ul style="margin: 0; padding-left: 20px; color: #374151; font-size: 13px; line-height: 1.7;">
                <li>Only <strong>Delivery</strong> status orders get marked complete</li>
                <li>CSV must match the template structure</li>
                <li>Max 5MB · .csv only</li>
            </ul>
        </div>

        <div style="margin-bottom: 16px;">
            <h6 style="margin: 0 0 10px; font-size: 14px;">📊 After Upload</h6>
            <ul style="margin: 0; padding-left: 20px; color: #374151; font-size: 13px; line-height: 1.7;">
                <li>Results and errors shown on screen</li>
                <li>Incorrect data shows specific error messages</li>
            </ul>
        </div>

        <div style="background: #fef3c7; border-radius: 6px; padding: 10px 12px; font-size: 13px; color: #92400e;">
            💡 Always use the downloaded template for the correct format.
        </div>

        </div>'
    );
    ?>
    <!-- Footer -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php'); ?>

    <!-- Scripts -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php'); ?>




            <script>
            // Load couriers when tenant is selected
            document.getElementById('tenant_id').addEventListener('change', function() {
                const tenantId = this.value;
                const courierSelect = document.getElementById('courier_id');

                courierSelect.innerHTML = '<option value="">Loading Couriers...</option>';
                courierSelect.disabled = true;

                if (tenantId) {
                    fetch('../tracking/get_couriers_by_tenant.php?tenant_id=' + tenantId)
                        .then(response => response.json())
                        .then(data => {
                            courierSelect.innerHTML = '<option value="">Select Courier</option>';

                            if (data.success && data.couriers.length > 0) {
                                data.couriers.forEach(courier => {
                                    const option = document.createElement('option');
                                    option.value = courier.co_id;
                                    option.textContent = courier.courier_name + ' (ID: ' + courier
                                        .co_id + ')';
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

            // Show selected file name and validate file type
            document.getElementById('csv_file').addEventListener('change', function() {
                const file = this.files[0];
                const fileNameEl = document.getElementById('file-name');

                if (file) {
                    // Check file extension
                    const validExtensions = ['.csv'];
                    const fileName = file.name.toLowerCase();
                    const isValidExtension = validExtensions.some(ext => fileName.endsWith(ext));

                    if (!isValidExtension) {
                        alert('Please select a valid CSV file.');
                        this.value = '';
                        fileNameEl.textContent = 'No file selected';
                        return;
                    }

                    // Check file size (5MB limit)
                    const maxSize = 5 * 1024 * 1024; // 5MB in bytes
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


            // Reset Form
            document.getElementById('resetBtn')?.addEventListener('click', function() {
                document.getElementById('uploadForm').reset();

                const courierSelect = document.getElementById('courier_id');
                courierSelect.innerHTML = '<option value="">Select Tenant First</option>';
                courierSelect.disabled = true;

                const fileNameEl = document.getElementById('file-name');
                if (fileNameEl) fileNameEl.textContent = 'No file chosen';
            });

            // Form validation before submit
            document.getElementById('uploadForm')?.addEventListener('submit', function(e) {
                const fileInput = document.getElementById('csv_file');
                const courierSelect = document.getElementById('courier_id');
                const tenantSelect = document.getElementById('tenant_id');
                
                if (!tenantSelect.value) {
                    e.preventDefault();
                    alert('Please select a tenant.');
                    return false;
                }

                if (!courierSelect.value) {
                    e.preventDefault();
                    alert('Please select a courier.');
                    return false;
                }

                if (!fileInput.files.length) {
                    e.preventDefault();
                    alert('Please select a CSV file to upload.');
                    return false;
                }
                
                // Disable submit button to prevent double submission
                const submitBtn = document.getElementById('importBtn');
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';
                
                return true;
            });
            </script>


            <style>
            .file-upload-section .customer-form-group {
                margin-bottom: 1.5rem;
            }

            </style>
</body>

</html>