<?php
// Start session at the very beginning
session_start();

// Include the database connection file FIRST
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/connection/db_connection.php');

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /order_management/dist/pages/login.php");
    exit();
}

// Get user's role from database
$user_id = $_SESSION['user_id'];
$role_check_sql = "SELECT u.role_id, r.name as role_name 
                   FROM users u 
                   LEFT JOIN roles r ON u.role_id = r.id 
                   WHERE u.id = ? AND u.status = 'active'";
$role_stmt = $conn->prepare($role_check_sql);
$role_stmt->bind_param("i", $user_id);
$role_stmt->execute();
$role_result = $role_stmt->get_result();

if ($role_result->num_rows === 0) {
    session_destroy();
    header("Location: /order_management/dist/pages/login.php");
    exit();
}

$user_role = $role_result->fetch_assoc();

// Check if user is admin (role_id = 1)
if ($user_role['role_id'] != 1) {
    header("Location: /order_management/dist/dashboard/index.php");
    exit();
}

// Fetch tenants from database
$tenantQuery = "SELECT tenant_id, company_name FROM tenants WHERE status = 'active' ORDER BY company_name";
$tenantResult = mysqli_query($conn, $tenantQuery);

// Fetch courier companies from database
// Fetch courier companies from database
// Fetch courier companies from database
$courierQuery = "SELECT courier_id, courier_name, phone_number, email, address_line1, address_line2, city, notes 
                 FROM courier_company 
                 WHERE status = 'active' 
                 ORDER BY courier_name";
$courierResult = mysqli_query($conn, $courierQuery);

if (!$courierResult) {
    error_log("Courier query error: " . mysqli_error($conn));
    die("Error loading courier companies. Please contact administrator.");
}

include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/sidebar.php');
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Order Management Admin Portal - Add Courier Account</title>

    <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/head.php'); ?>
    
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/customers.css" id="main-style-link" />
    
    <style>
        .ajax-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            max-width: 400px;
            margin-bottom: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            border-radius: 8px;
            animation: slideInRight 0.3s ease-out;
            border: 1px solid transparent;
            padding: 1rem 1.5rem;
            border-left: 4px solid;
        }

        .alert-success {
            color: #0f5132;
            background: linear-gradient(135deg, #f8f9fa 0%, #d1e7dd 100%);
            border-left-color: #28a745;
        }

        .alert-danger {
            color: #842029;
            background: linear-gradient(135deg, #f8f9fa 0%, #f8d7da 100%);
            border-left-color: #dc3545;
        }

        .alert-warning {
            color: #664d03;
            background: linear-gradient(135deg, #f8f9fa 0%, #fff3cd 100%);
            border-left-color: #ffc107;
        }

        .alert-info {
            color: #0c5460;
            background: linear-gradient(135deg, #f8f9fa 0%, #d1ecf1 100%);
            border-left-color: #17a2b8;
        }

        .alert .btn-close {
            padding: 0.5rem 0.5rem;
            position: absolute;
            top: 0;
            right: 0;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .checkbox-group {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
        }

        .checkbox-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-right: 8px;
            cursor: pointer;
        }

        .checkbox-item label {
            margin: 0;
            cursor: pointer;
            user-select: none;
        }

        .field-hint {
            font-size: 0.875rem;
            color: #6c757d;
            margin-top: 0.25rem;
        }

        .conditional-field {
            display: none;
        }

        .conditional-field.show {
            display: block;
        }

        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 9998;
            justify-content: center;
            align-items: center;
        }

        .loading-spinner {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            text-align: center;
        }

        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #3498db;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 0 auto 1rem;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>

<body>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/loader.php'); ?>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <div class="spinner"></div>
            <h5>Processing...</h5>
            <p>Please wait while we add the courier account</p>
        </div>
    </div>

    <div class="pc-container">
        <div class="pc-content">
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">Add Courier Account</h5>
                    </div>
                </div>
            </div>

            <div class="main-container">
                <form method="POST" id="addCourierForm" class="customer-form" novalidate>
                    
                    <!-- Tenant and Courier Selection Section -->
                    <div class="form-section">
                        <div class="section-content">
                            
                            <!-- First Row: Tenant and Courier Selection -->
                            <div class="form-row">
                                <div class="customer-form-group">
                                    <label for="tenant_id" class="form-label">
                                        <i class="fas fa-building"></i> Select Tenant<span class="required">*</span>
                                    </label>
                                    <select class="form-select" id="tenant_id" name="tenant_id" required>
                                        <option value="">-- Select Tenant --</option>
                                        <?php
                                        if ($tenantResult && mysqli_num_rows($tenantResult) > 0) {
                                            while ($tenant = mysqli_fetch_assoc($tenantResult)) {
                                                echo "<option value='{$tenant['tenant_id']}'>" . htmlspecialchars($tenant['company_name']) . "</option>";
                                            }
                                        }
                                        ?>
                                    </select>
                                    <div class="error-feedback" id="tenant_id-error"></div>
                                    <div class="field-hint">Select the tenant/company for this courier account</div>
                                </div>

                                <div class="customer-form-group">
                                    <label for="courier_id" class="form-label">
                                        <i class="fas fa-truck"></i> Select Courier Company<span class="required">*</span>
                                    </label>
                                    <select class="form-select" id="courier_id" name="courier_id" required>
                                        <option value="">-- Select Courier Company --</option>
                                        <?php
                                        if ($courierResult && mysqli_num_rows($courierResult) > 0) {
                                            while ($courier = mysqli_fetch_assoc($courierResult)) {
                                                echo "<option value='{$courier['courier_id']}' 
                                                      data-phone='{$courier['phone_number']}' 
                                                      data-email='{$courier['email']}'
                                                      data-address1='{$courier['address_line1']}'
                                                      data-address2='{$courier['address_line2']}'
                                                      data-city='{$courier['city']}'>" 
                                                      . htmlspecialchars($courier['courier_name']) . "</option>";
                                            }
                                        }
                                        ?>
                                    </select>
                                    <div class="error-feedback" id="courier_id-error"></div>
                                    <div class="field-hint">Choose the courier company to add for this tenant</div>
                                </div>
                            </div>

                     

                            <!-- Third Row: Royal Express Conditional Fields (Hidden by default) -->
                            <div class="form-row conditional-field" id="royal-express-fields">
                                <div class="customer-form-group">
                                    <label for="origin_city_name" class="form-label">
                                        <i class="fas fa-city"></i> Origin City Name<span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="origin_city_name" name="origin_city_name"
                                        placeholder="Enter origin city">
                                    <div class="error-feedback" id="origin_city_name-error"></div>
                                </div>

                                <div class="customer-form-group">
                                    <label for="origin_state_name" class="form-label">
                                        <i class="fas fa-map-marked-alt"></i> Origin State Name<span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="origin_state_name" name="origin_state_name"
                                        placeholder="Enter origin state">
                                    <div class="error-feedback" id="origin_state_name-error"></div>
                                </div>
                            </div>

                            <!-- Fourth Row: API Credentials (Optional) -->
                            <div class="form-row">
                                <div class="customer-form-group">
                                    <label for="api_key" class="form-label">
                                        <i class="fas fa-key"></i> API Key
                                    </label>
                                    <input type="text" class="form-control" id="api_key" name="api_key"
                                        placeholder="Enter API key (optional)">
                                    <div class="error-feedback" id="api_key-error"></div>
                                </div>

                                <div class="customer-form-group">
                                    <label for="client_id" class="form-label">
                                        <i class="fas fa-id-badge"></i> Client ID
                                    </label>
                                    <input type="text" class="form-control" id="client_id" name="client_id"
                                        placeholder="Enter client ID (optional)">
                                    <div class="error-feedback" id="client_id-error"></div>
                                </div>
                            </div>
                                   <!-- Second Row: API Integration Type -->
                            <div class="form-row">
                                <div class="customer-form-group">
                                    <label class="form-label">
                                        <i class="fas fa-code"></i> API Integration Type<span class="required">*</span>
                                    </label>
                                    <div class="checkbox-group">
                                        <div class="checkbox-item">
                                            <input type="checkbox" id="has_api_new" name="has_api_new" value="1">
                                            <label for="has_api_new">API New</label>
                                        </div>
                                        <div class="checkbox-item">
                                            <input type="checkbox" id="has_api_existing" name="has_api_existing" value="1">
                                            <label for="has_api_existing">API Existing</label>
                                        </div>
                                    </div>
                                    <div class="error-feedback" id="api_integration-error"></div>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- Submit Buttons -->
                    <div class="submit-container">
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-plus-circle"></i> Add Courier Account
                        </button>
                        <button type="button" class="btn btn-secondary ms-2" id="resetBtn">
                            <i class="fas fa-undo"></i> Reset Form
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php
    include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/footer.php');
    include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/scripts.php');
    ?>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>

    <script>
        $(document).ready(function() {
            initializeForm();
            
            // Handle courier selection change
            $('#courier_id').on('change', function() {
                const courierId = $(this).val();
                
                // Show/hide Royal Express fields
                if (courierId == '14') { // Royal Express Courier
                    $('#royal-express-fields').addClass('show');
                    $('#origin_city_name, #origin_state_name').attr('required', true);
                } else {
                    $('#royal-express-fields').removeClass('show');
                    $('#origin_city_name, #origin_state_name').attr('required', false).val('');
                }
            });

            // AJAX Form submission
            $('#addCourierForm').on('submit', function(e) {
                e.preventDefault();
                
                clearAllValidations();
                
                if (validateForm()) {
                    submitFormAjax();
                } else {
                    scrollToFirstError();
                }
            });
            
            // Reset button
            $('#resetBtn').on('click', function() {
                resetForm();
            });
            
            setupRealTimeValidation();
        });

        function initializeForm() {
            $('#tenant_id').focus();
        }

        function submitFormAjax() {
            showLoading();
            
            const $submitBtn = $('#submitBtn');
            const originalText = $submitBtn.html();
            $submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Adding...');
            
            const formData = new FormData($('#addCourierForm')[0]);
            
            $.ajax({
                url: 'save_courier_account.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                timeout: 30000,
                success: function(response) {
                    hideLoading();
                    $submitBtn.prop('disabled', false).html(originalText);
                    
                    if (response.success) {
                        showSuccessNotification(response.message || 'Courier account added successfully!');
                        setTimeout(() => {
                            window.location.href = 'couriers.php';
                        }, 2000);
                    } else {
                        if (response.errors) {
                            showFieldErrors(response.errors);
                        }
                        showErrorNotification(response.message || 'Failed to add courier account.');
                    }
                },
                error: function(xhr, status, error) {
                    hideLoading();
                    $submitBtn.prop('disabled', false).html(originalText);
                    
                    let errorMessage = 'An error occurred while adding the courier account.';
                    
                    if (status === 'timeout') {
                        errorMessage = 'Request timeout. Please try again.';
                    } else if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMessage = xhr.responseJSON.message;
                    }
                    
                    showErrorNotification(errorMessage);
                    console.error('AJAX Error:', xhr, status, error);
                }
            });
        }

        function validateForm() {
            let isValid = true;
            
            // Validate tenant
            const tenantId = $('#tenant_id').val();
            if (!tenantId) {
                showError('tenant_id', 'Please select a tenant');
                isValid = false;
            } else {
                showSuccess('tenant_id');
            }
            
            // Validate courier
            const courierId = $('#courier_id').val();
            if (!courierId) {
                showError('courier_id', 'Please select a courier company');
                isValid = false;
            } else {
                showSuccess('courier_id');
            }
            
            // Validate API integration
            const hasApiNew = $('#has_api_new').is(':checked');
            const hasApiExisting = $('#has_api_existing').is(':checked');
            if (!hasApiNew && !hasApiExisting) {
                showError('api_integration', 'Please select at least one API integration type');
                isValid = false;
            } else {
                clearValidation('api_integration');
            }
            
            // Validate Royal Express fields if visible
            if ($('#royal-express-fields').hasClass('show')) {
                const originCity = $('#origin_city_name').val();
                const originState = $('#origin_state_name').val();
                
                if (!originCity || originCity.trim() === '') {
                    showError('origin_city_name', 'Origin city name is required for Royal Express');
                    isValid = false;
                } else {
                    showSuccess('origin_city_name');
                }
                
                if (!originState || originState.trim() === '') {
                    showError('origin_state_name', 'Origin state name is required for Royal Express');
                    isValid = false;
                } else {
                    showSuccess('origin_state_name');
                }
            }
            
            return isValid;
        }

        function setupRealTimeValidation() {
            $('#tenant_id').on('change', function() {
                if ($(this).val()) {
                    showSuccess('tenant_id');
                } else {
                    showError('tenant_id', 'Please select a tenant');
                }
            });
            
            $('#courier_id').on('change', function() {
                if ($(this).val()) {
                    showSuccess('courier_id');
                } else {
                    showError('courier_id', 'Please select a courier company');
                }
            });
            
            $('#has_api_new, #has_api_existing').on('change', function() {
                if ($('#has_api_new').is(':checked') || $('#has_api_existing').is(':checked')) {
                    clearValidation('api_integration');
                }
            });
        }

        function showFieldErrors(errors) {
            $.each(errors, function(field, message) {
                showError(field, message);
            });
        }

        function showError(fieldId, message) {
            const $field = $('#' + fieldId);
            const $errorDiv = $('#' + fieldId + '-error');
            
            if ($field.length && $errorDiv.length) {
                $field.addClass('is-invalid field-error').removeClass('is-valid field-success');
                $errorDiv.text(message).show();
            }
        }

        function showSuccess(fieldId) {
            const $field = $('#' + fieldId);
            const $errorDiv = $('#' + fieldId + '-error');
            
            if ($field.length && $errorDiv.length) {
                $field.addClass('is-valid field-success').removeClass('is-invalid field-error');
                $errorDiv.hide();
            }
        }

        function clearValidation(fieldId) {
            const $field = $('#' + fieldId);
            const $errorDiv = $('#' + fieldId + '-error');
            
            if ($field.length && $errorDiv.length) {
                $field.removeClass('is-valid is-invalid field-error field-success');
                $errorDiv.hide();
            }
        }

        function clearAllValidations() {
            $('.form-control, .form-select').removeClass('is-valid is-invalid field-error field-success');
            $('.error-feedback').hide().text('');
        }

        function scrollToFirstError() {
            const $firstError = $('.is-invalid, .field-error').first();
            if ($firstError.length) {
                $('html, body').animate({
                    scrollTop: $firstError.offset().top - 100
                }, 500);
                $firstError.focus();
            }
        }

        function resetForm() {
            $('#addCourierForm')[0].reset();
            clearAllValidations();
            $('#royal-express-fields').removeClass('show');
            $('#origin_city_name, #origin_state_name').attr('required', false);
            $('#tenant_id').focus();
        }

        function showLoading() {
            $('#loadingOverlay').css('display', 'flex');
            $('body').css('overflow', 'hidden');
        }

        function hideLoading() {
            $('#loadingOverlay').hide();
            $('body').css('overflow', 'auto');
        }

        function showSuccessNotification(message) {
            showNotification(message, 'success');
        }

        function showErrorNotification(message) {
            showNotification(message, 'danger');
        }

        function showNotification(message, type) {
            const notificationId = 'notification_' + Date.now();
            const iconClass = type === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-circle';
            
            const notification = `
                <div class="alert alert-${type} alert-dismissible fade show ajax-notification" id="${notificationId}" role="alert">
                    <div class="d-flex align-items-center">
                        <i class="${iconClass} me-2"></i>
                        <div>${message}</div>
                    </div>
                    <button type="button" class="btn-close" onclick="hideNotification('${notificationId}')" aria-label="Close"></button>
                </div>
            `;
            
            $('body').append(notification);
            
            setTimeout(() => {
                hideNotification(notificationId);
            }, 5000);
        }

        function hideNotification(notificationId) {
            const $notification = $('#' + notificationId);
            if ($notification.length) {
                $notification.removeClass('show');
                setTimeout(() => {
                    $notification.remove();
                }, 300);
            }
        }
    </script>

</body>
</html>