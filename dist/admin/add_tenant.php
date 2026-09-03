<?php
// Start session at the very beginning
session_start();

// Include the database connection file FIRST
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // Clear any existing output buffers
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /OMS/dist/pages/login.php");
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
    // User not found or inactive
    session_destroy();
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

$user_role = $role_result->fetch_assoc();

// Check if user is admin (role_id = 1)
if ($user_role['role_id'] != 1) {
    // User is not admin, redirect to dashboard
    header("Location: /OMS/dist/dashboard/index.php");
    exit();
}

// TENANT CREATION LIMIT INFO

$tenantLimit = 0;
$currentTenantCount = 0;
$limitInfo = null;

// Fetch tenant_limit from branding table
$limitSql = "SELECT tenant_limit FROM branding WHERE active = 1 LIMIT 1";
$limitResult = $conn->query($limitSql);
if ($limitResult && $limitResult->num_rows > 0) {
    $limitRow = $limitResult->fetch_assoc();
    $tenantLimit = (int)$limitRow['tenant_limit'];
}

// Count current tenants
$countSql = "SELECT COUNT(*) as total FROM tenants";
$countResult = $conn->query($countSql);
if ($countResult && $countResult->num_rows > 0) {
    $countRow = $countResult->fetch_assoc();
    $currentTenantCount = (int)$countRow['total'];
}

$isFull = false;
if ($tenantLimit > 0) {
    $limitInfo = [
        'limit' => $tenantLimit,
        'current' => $currentTenantCount,
        'remaining' => max(0, $tenantLimit - $currentTenantCount),
        'is_full' => $currentTenantCount >= $tenantLimit
    ];
    $isFull = $limitInfo['is_full'];
}

$disabledAttr = $isFull ? 'disabled="disabled"' : '';
$disabledClass = $isFull ? 'limit-reached-disabled' : '';
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <!-- TITLE -->
    <title>Add New Tenant | <?= htmlspecialchars($_SESSION['company_name'] ?? '') ?></title>

    <?php
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php');
    ?>
    
    <!-- [Template CSS Files] -->
    
    <!-- Custom CSS for form UI (matching branding.php style) -->
   <style>
/* Form UI Styles - Matching Branding.php */
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

/* DISABLED FORM WHEN LIMIT IS REACHED */
.limit-reached-disabled .form-control,
.limit-reached-disabled .form-select {
    background-color: #e9ecef !important;
    cursor: not-allowed !important;
    opacity: 0.65;
    pointer-events: none;
}

.limit-reached-disabled .form-group label {
    color: #6c757d;
}

.limit-reached-disabled .btn-primary {
    background-color: #6c757d !important;
    border-color: #6c757d !important;
    cursor: not-allowed !important;
    opacity: 0.5;
}

.limit-reached-disabled .btn-secondary {
    cursor: not-allowed !important;
    opacity: 0.5;
}

.limit-reached-disabled input:hover,
.limit-reached-disabled select:hover,
.limit-reached-disabled textarea:hover {
    border-color: #ced4da !important;
}

.limit-reached-disabled .form-control:focus,
.limit-reached-disabled .form-select:focus {
    box-shadow: none !important;
    border-color: #ced4da !important;
}

.loading-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 9998;
    align-items: center;
    justify-content: center;
}

.loading-spinner {
    background: white;
    padding: 2rem;
    border-radius: 8px;
    text-align: center;
}

.spinner {
    border: 4px solid #f3f3f3;
    border-top: 4px solid #3498db;
    border-radius: 50%;
    width: 40px;
    height: 40px;
    animation: spin 1s linear infinite;
    margin: 0 auto 1rem;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.email-suggestions {
    font-size: 0.875rem;
    color: #0d6efd;
    margin-top: 0.25rem;
}

.email-suggestions a {
    color: #0d6efd;
    text-decoration: underline;
    cursor: pointer;
}

/* Form check styles for remove logo/favicon checkboxes */
.form-check {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-top: 0.5rem;
}
.form-check-input {
    width: auto;
    height: auto;
    margin: 0;
    cursor: pointer;
}
.form-check-label {
    margin: 0;
    cursor: pointer;
}

/* Mobile responsive fixes */
@media screen and (max-width: 767.98px) {
    .form-row {
        flex-direction: column;
        gap: 0;
    }

    .form-actions {
        display: flex;
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
    }

    .form-actions .btn {
        width: 100%;
        justify-content: center;
        min-height: 44px;
    }
}
</style>
</head>

<body>
    <!-- LOADER -->
    <?php
        include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/loader.php');
        include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
        include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');
    ?>
    <!-- END LOADER -->

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <div class="spinner"></div>
            <h5>Processing...</h5>
            <p>Please wait while we add the tenant</p>
        </div>
    </div>

    <!-- [ Main Content ] start -->
    <div class="pc-container">
        <div class="pc-content">
            <div class="row">
                <div class="col-xl-12">
                    <div class="card">
                        <div class="card-header">
                            <h5>Add New Tenant<?php if ($limitInfo !== null && $limitInfo['is_full']): ?> <span style="color:red;font-size:13px;font-weight:400;">— Max <?php echo $limitInfo['limit']; ?> tenant(s). Contact admin.</span><?php endif; ?></h5>
                        </div>
                        <div class="card-body">

                            <!-- Add Tenant Form -->
                            <form method="POST" id="addTenantForm" class="<?php echo $disabledClass; ?>" enctype="multipart/form-data" novalidate>

                                <div class="form-row">
                                    <!-- Company Name -->
                                    <div class="form-column">
                                        <div class="form-group">
                                            <label for="company_name" class="form-label">Company Name *</label>
                                            <input type="text" class="form-control" id="company_name" name="company_name" required <?php echo $disabledAttr; ?>
                                                   placeholder="Enter company name" value="">
                                            <div class="error-feedback" id="company_name-error"></div>
                                        </div>
                                    </div>
                                    <!-- Contact Person -->
                                    <div class="form-column">
                                        <div class="form-group">
                                            <label for="contact_person" class="form-label">Contact Person *</label>
                                            <input type="text" class="form-control" id="contact_person" name="contact_person" required <?php echo $disabledAttr; ?>
                                                   placeholder="Enter contact person name" value="">
                                            <div class="error-feedback" id="contact_person-error"></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <!-- Email -->
                                    <div class="form-column">
                                        <div class="form-group">
                                            <label for="email" class="form-label">Email Address</label>
                                            <input type="email" class="form-control" id="email" name="email" <?php echo $disabledAttr; ?>
                                                   placeholder="Enter email address" value="">
                                            <div class="error-feedback" id="email-error"></div>
                                        </div>
                                    </div>
                                    <!-- Phone -->
                                    <div class="form-column">
                                        <div class="form-group">
                                            <label for="phone" class="form-label">Phone Number *</label>
                                            <input type="tel" class="form-control" id="phone" name="phone" required <?php echo $disabledAttr; ?>
                                                   placeholder="Enter phone number" value="">
                                            <div class="error-feedback" id="phone-error"></div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Address -->
                                <div class="form-group">
                                    <label for="address" class="form-label">Company Address *</label>
                                    <textarea class="form-control" id="address" name="address" rows="3" placeholder="Enter full address" required></textarea>
                                    <div class="error-feedback" id="address-error"></div>
                                </div>

                                <div class="form-row">
                                    <!-- Delivery Fee -->
                                    <div class="form-column">
                                        <div class="form-group">
                                            <label for="delivery_fee" class="form-label">Delivery Fee (LKR)</label>
                                            <input type="number" step="0.01" class="form-control" id="delivery_fee" name="delivery_fee" <?php echo $disabledAttr; ?>
                                                   placeholder="0.00" value="0.00">
                                            <div class="error-feedback" id="delivery_fee-error"></div>
                                        </div>
                                    </div>
                                    <!-- Main Admin -->
                                    <div class="form-column">
                                        <div class="form-group">
                                            <label for="is_main_admin" class="form-label">Main Admin *</label>
                                            <select class="form-select form-control" id="is_main_admin" name="is_main_admin" required <?php echo $disabledAttr; ?>>
                                                <option value="0" selected>No</option>
                                                <option value="1">Yes</option>
                                            </select>
                                            <div class="error-feedback" id="is_main_admin-error"></div>
                                        </div>
                                    </div>
                                </div>

                                <h6 class="mb-3 mt-4">Logos and Icons</h6>

                                <div class="form-row">
                                    <!-- Logo Upload -->
                                    <div class="form-column">
                                        <div class="form-group">
                                            <label for="logo" class="form-label">Main Logo (JPEG, PNG, GIF)</label>
                                            <input type="file" class="form-control" id="logo" name="logo" accept=".jpg,.jpeg,.png,.gif" <?php echo $disabledAttr; ?>>
                                            <div class="error-feedback" id="logo-error"></div>
                                        </div>
                                    </div>
                                    <!-- Favicon Upload -->
                                    <div class="form-column">
                                        <div class="form-group">
                                            <label for="fav_icon" class="form-label">Fav Icon (ICO, PNG, JPG)</label>
                                            <input type="file" class="form-control" id="fav_icon" name="fav_icon" accept=".ico,.jpg,.jpeg,.png" <?php echo $disabledAttr; ?>>
                                            <div class="error-feedback" id="fav_icon-error"></div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Submit Buttons -->
                                <div class="d-flex justify-content-end mt-4 form-actions">
                                    <button type="submit" class="btn btn-primary" id="submitBtn" <?php echo $disabledAttr; ?>>
                                        Add Tenant
                                    </button>
                                    <button type="button" class="btn btn-secondary ms-2" id="resetBtn" <?php echo $disabledAttr; ?>>
                                        Reset Form
                                    </button>
                                </div>
                            </form>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- FOOTER -->
    <?php
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php');
    ?>
    <!-- END FOOTER -->

    <!-- SCRIPTS -->
    <?php
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php');
    ?>
    <!-- END SCRIPTS -->

    <!-- jQuery -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>

    <script>
        $(document).ready(function() {
            // Initialize form
            initializeForm();
            
            // AJAX Form submission
            $('#addTenantForm').on('submit', function(e) {
                e.preventDefault();
                
                // Clear previous validations
                clearAllValidations();
                
                // Validate form
                if (validateForm()) {
                    submitFormAjax();
                } else {
                    // Scroll to first error
                    scrollToFirstError();
                }
            });
            
            // Reset button
            $('#resetBtn').on('click', function() {
                resetForm();
            });
            
            // Real-time validation
            setupRealTimeValidation();
            
            // Other event listeners
            setupEventListeners();
        });

        // AJAX Form Submission Function
        function submitFormAjax() {
            // Show loading overlay
            showLoading();
            
            // Disable submit button
            const $submitBtn = $('#submitBtn');
            const originalText = $submitBtn.html();
            $submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Adding Tenant...');
            
            // Prepare form data
            const formData = new FormData($('#addTenantForm')[0]);
            
            // AJAX request
            $.ajax({
                url: 'save_tenant.php',
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
                        toastManager.success(response.message || 'Tenant added successfully!');
                        
                        // Reset form after success
                        setTimeout(function() {
                            resetForm();
                        }, 1500);
                    } else {
                        if (response.errors) {
                            // Show field-specific errors
                            showFieldErrors(response.errors);
                        }
                        
                        toastManager.error(response.message || 'Failed to add tenant. Please try again.');
                    }
                },
                error: function(xhr, status, error) {
                    hideLoading();
                    $submitBtn.prop('disabled', false).html(originalText);
                    
                    let errorMessage = 'An error occurred while adding the tenant.';
                    
                    if (status === 'timeout') {
                        errorMessage = 'Request timeout. Please try again.';
                    } else if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMessage = xhr.responseJSON.message;
                    } else if (xhr.status === 500) {
                        errorMessage = 'Server error. Please contact administrator.';
                    } else if (xhr.status === 0) {
                        errorMessage = 'No internet connection. Please check your connection.';
                    }
                    
                    toastManager.error(errorMessage);
                    console.error('AJAX Error:', {
                        status: xhr.status,
                        statusText: xhr.statusText,
                        responseText: xhr.responseText,
                        error: error
                    });
                }
            });
        }
        
        // Show field-specific errors from server
        function showFieldErrors(errors) {
            $.each(errors, function(field, message) {
                showError(field, message);
            });
        }
        
        // Loading functions
        function showLoading() {
            $('#loadingOverlay').css('display', 'flex');
            $('body').css('overflow', 'clip');
        }
        
        function hideLoading() {
            $('#loadingOverlay').hide();
            $('body').css('overflow', 'auto');
        }
        
        
        // Form reset function
        function resetForm() {
            $('#addTenantForm')[0].reset();
            clearAllValidations();
            $('#email-suggestions').html('');
            $('#company_name').focus();
        }
        
        // Clear all validations
        function clearAllValidations() {
            $('.form-control, .form-select').removeClass('is-valid is-invalid field-error field-success');
            $('.error-feedback').hide().text('');
        }
        
        // Scroll to first error
        function scrollToFirstError() {
            const $firstError = $('.is-invalid, .field-error').first();
            if ($firstError.length) {
                $('html, body').animate({
                    scrollTop: $firstError.offset().top - 100
                }, 500);
                $firstError.focus();
            }
        }
        
        // Initialize form
        function initializeForm() {
            $('#company_name').focus();
            
            // Auto-format phone number
            $('#phone').on('input', function() {
                let value = this.value.replace(/\D/g, '');
                if (value.length > 10) {
                    value = value.substring(0, 10);
                }
                this.value = value;
            });
            
            // Email formatting
            $('#email').on('input', function() {
                this.value = this.value.toLowerCase().trim();
                $('#email-suggestions').html('');
            });
        }
        
        // Setup real-time validation
        function setupRealTimeValidation() {
            $('#company_name').on('blur', function() {
                const validation = validateCompanyName($(this).val());
                if (!validation.valid) {
                    showError('company_name', validation.message);
                } else {
                    showSuccess('company_name');
                }
            });
            
            $('#contact_person').on('blur', function() {
                const validation = validateContactPerson($(this).val());
                if (!validation.valid) {
                    showError('contact_person', validation.message);
                } else {
                    showSuccess('contact_person');
                }
            });
            
            $('#email').on('blur', function() {
                const val = $(this).val();
                if (val.trim() === '') {
                    clearValidation('email');
                    $('#email-suggestions').html('');
                    return;
                }
                const validation = validateEmail(val);
                if (!validation.valid) {
                    showError('email', validation.message);
                } else {
                    showSuccess('email');
                }
                
                // Show email suggestions
                const suggestion = suggestEmail(val);
                if (suggestion && suggestion !== val.toLowerCase()) {
                    $('#email-suggestions').html(`Did you mean <a href="#" onclick="$('#email').val('${suggestion}'); $('#email-suggestions').html(''); $('#email').focus(); return false;">${suggestion}</a>?`);
                } else {
                    $('#email-suggestions').html('');
                }
            });
            
            $('#address').on('blur', function() {
                const validation = validateAddress($(this).val());
                if (!validation.valid) {
                    showError('address', validation.message);
                } else {
                    showSuccess('address');
                }
            });

            $('#phone').on('blur', function() {
                const validation = validatePhone($(this).val());
                if (!validation.valid) {
                    showError('phone', validation.message);
                } else {
                    showSuccess('phone');
                }
            });
        }
        
        // Setup other event listeners
        function setupEventListeners() {
            // Prevent form submission on Enter key in input fields
            $('input:not([type="submit"])').on('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const $inputs = $('input, select, textarea');
                    const currentIndex = $inputs.index(this);
                    if (currentIndex < $inputs.length - 1) {
                        $inputs.eq(currentIndex + 1).focus();
                    }
                }
            });
        }

        // Validation functions
        function validateCompanyName(name) {
            if (name.trim() === '') {
                return { valid: false, message: 'Company name is required' };
            }
            if (name.trim().length < 2) {
                return { valid: false, message: 'Company name must be at least 2 characters long' };
            }

            return { valid: true, message: '' };
        }

        function validateContactPerson(name) {
            if (name.trim() === '') {
                return { valid: false, message: 'Contact person name is required' };
            }
            if (name.trim().length < 2) {
                return { valid: false, message: 'Name must be at least 2 characters long' };
            }
            if (name.length > 255) {
                return { valid: false, message: 'Name is too long (maximum 255 characters)' };
            }
            if (!/^[a-zA-Z\s.\-']+$/.test(name)) {
                return { valid: false, message: 'Name can only contain letters, spaces, dots, hyphens, and apostrophes' };
            }
            return { valid: true, message: '' };
        }

        function validateEmail(email) {
            if (email.trim() === '') {
                return { valid: true, message: '' };
            }
            if (email.length > 100) {
                return { valid: false, message: 'Email address is too long (maximum 100 characters)' };
            }
            const emailRegex = /^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/;
            if (!emailRegex.test(email)) {
                return { valid: false, message: 'Please enter a valid email address' };
            }
            return { valid: true, message: '' };
        }

        function validateAddress(address) {
            if (address.trim() === '') {
                return { valid: false, message: 'Company address is required' };
            }
            return { valid: true, message: '' };
        }

        function validatePhone(phone) {
            if (phone.trim() === '') {
                return { valid: false, message: 'Phone number is required' };
            }
            const cleanPhone = phone.replace(/\s+/g, '');
            const sriLankanPhoneRegex = /^(0|94|\+94)?[1-9][0-9]{8}$/;
            if (!sriLankanPhoneRegex.test(cleanPhone)) {
                return { valid: false, message: 'Please enter a valid Sri Lankan phone number' };
            }
            return { valid: true, message: '' };
        }

        // Email suggestion function
        function suggestEmail(email) {
            if (!email || email.trim() === '' || !email.includes('@')) {
                return null;
            }
            
            const parts = email.split('@');
            const username = parts[0];
            const domain = parts[1].toLowerCase();
            
            const typos = {
                'gamil.com': 'gmail.com',
                'gmail.co': 'gmail.com',
                'gmail.cm': 'gmail.com',
                'gmal.com': 'gmail.com',
                'yahooo.com': 'yahoo.com',
                'yaho.com': 'yahoo.com',
                'yahoo.co': 'yahoo.com',
                'hotmai.com': 'hotmail.com',
                'hotmail.co': 'hotmail.com',
                'outlok.com': 'outlook.com',
                'outlook.co': 'outlook.com'
            };
            
            if (typos[domain]) {
                return username + '@' + typos[domain];
            }
            
            return null;
        }

        // Show/hide error functions
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

        // Form validation
        function validateForm() {
            let isValid = true;
            
            // Get all field values
            const companyName = $('#company_name').val();
            const contactPerson = $('#contact_person').val();
            const email = $('#email').val();
            const phone = $('#phone').val();
            
            // Validate required fields
            const validations = [
                { field: 'company_name', validator: validateCompanyName, value: companyName },
                { field: 'contact_person', validator: validateContactPerson, value: contactPerson },
                { field: 'phone', validator: validatePhone, value: phone },
                { field: 'address', validator: validateAddress, value: $('#address').val() }
            ];

            if (email.trim() !== '') {
                const emailResult = validateEmail(email);
                if (!emailResult.valid) {
                    showError('email', emailResult.message);
                    isValid = false;
                } else {
                    showSuccess('email');
                }
            } else {
                clearValidation('email');
            }
            
            validations.forEach(function(validation) {
                const result = validation.validator(validation.value);
                if (!result.valid) {
                    showError(validation.field, result.message);
                    isValid = false;
                } else {
                    showSuccess(validation.field);
                }
            });
            
            return isValid;
        }
    </script>

</body>
</html>