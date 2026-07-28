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

// Determine if current user is a main admin
$current_is_main_admin = isset($_SESSION['is_main_admin']) && $_SESSION['is_main_admin'] == 1;

// Function to generate CSRF token
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Get tenant ID from URL parameter
$tenant_data = null;
$errorMsg = "";

$tenantId = null;
$session_tenant_id = $_SESSION['tenant_id'] ?? null;

if (isset($_GET['id']) && !empty($_GET['id'])) {
    $tenantId = (int)$_GET['id'];
    
    // Company admin can ONLY edit their own tenant
    if (!$current_is_main_admin && $tenantId !== (int)$session_tenant_id) {
        header("Location: /OMS/dist/dashboard/index.php");
        exit();
    }
} elseif (!$current_is_main_admin && $session_tenant_id) {
    // Company admin with no ?id param: auto-load their own tenant
    $tenantId = (int)$session_tenant_id;
} elseif ($current_is_main_admin) {
    // Main admin must provide an id
    header("Location: tenant_list.php?error=No tenant ID specified");
    exit();
} else {
    // Fallback: not main admin and no session tenant
    header("Location: /OMS/dist/dashboard/index.php");
    exit();
}

// Fetch tenant data from database
$stmt = mysqli_prepare($conn, "SELECT * FROM tenants WHERE tenant_id = ?");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $tenantId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $tenant_data = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    if (!$tenant_data) {
        header("Location: tenant_list.php?error=Tenant not found");
        exit();
    }
} else {
    header("Location: tenant_list.php?error=Database error: " . mysqli_error($conn));
    exit();
}


?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <!-- TITLE -->
    <title>Order Management Admin Portal - Edit Tenant</title>

    <?php
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php');
    ?>
    
    <!-- [Template CSS Files] -->
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/customers.css" id="main-style-link" />
    
    <!-- Custom CSS for AJAX notifications -->
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

/* Enhanced Bootstrap alert colors with gradients and left border */
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
            <p>Please wait while we update the tenant</p>
        </div>
    </div>

    <!-- [ Main Content ] start -->
    <div class="pc-container">
        <div class="pc-content">
            <!-- [ breadcrumb ] start -->
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">Edit Tenant</h5>
                    </div>
                    <div class="page-header-breadcrumb">
                        <ul class="breadcrumb">
                            <li class="breadcrumb-item">
                                <a href="<?php echo $current_is_main_admin ? 'tenant_list.php' : '../dashboard/index.php'; ?>">
                                    <?php echo $current_is_main_admin ? 'Tenants List' : 'Dashboard'; ?>
                                </a>
                            </li>
                            <li class="breadcrumb-item active">Company Settings</li>
                        </ul>
                    </div>
                </div>
            </div>
            <!-- [ breadcrumb ] end -->

            <!-- [ Main Content ] start -->
            <div class="main-container">
                <!-- Edit Tenant Form -->
                <form method="POST" id="editTenantForm" class="customer-form" enctype="multipart/form-data" novalidate>
                    <!-- CSRF Token -->
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <!-- Tenant ID -->
                    <input type="hidden" name="tenant_id" value="<?php echo $tenant_data['tenant_id']; ?>">
                    
                    <!-- Tenant Details Section -->
                    <div class="form-section">
                        <div class="section-content">
                            <!-- First Row: Company Name and Contact Person -->
                            <div class="form-row">
                                <div class="customer-form-group">
                                    <label for="company_name" class="form-label">
                                        <i class="fas fa-building"></i> Company Name<span class="required">*</span><span class="small" style="font-size:10px;font-weight:normal;">(Max 15 characters)</span>
                                    </label>
                                    <input type="text" class="form-control" id="company_name" name="company_name"
                                        placeholder="Enter company name" maxlength="15"
                                        value="<?php echo htmlspecialchars($tenant_data['company_name'] ?? ''); ?>" required>
                                    <div class="error-feedback" id="company_name-error"></div>
                                </div>

                                <div class="customer-form-group">
                                    <label for="contact_person" class="form-label">
                                        <i class="fas fa-user"></i> Contact Person<span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="contact_person" name="contact_person"
                                        placeholder="Enter contact person name" 
                                        value="<?php echo htmlspecialchars($tenant_data['contact_person'] ?? ''); ?>" required>
                                    <div class="error-feedback" id="contact_person-error"></div>
                                </div>
                            </div>

                            <!-- Second Row: Email and Phone -->
                            <div class="form-row">
                                <div class="customer-form-group">
                                    <label for="email" class="form-label">
                                        <i class="fas fa-envelope"></i> Email Address<span class="required">*</span>
                                    </label>
                                    <input type="email" class="form-control" id="email" name="email"
                                        placeholder="company@example.com" 
                                        value="<?php echo htmlspecialchars($tenant_data['email'] ?? ''); ?>" required>
                                    <div class="error-feedback" id="email-error"></div>
                                    <div class="email-suggestions" id="email-suggestions"></div>
                                </div>

                                <div class="customer-form-group">
                                    <label for="phone" class="form-label">
                                        <i class="fas fa-phone"></i> Phone Number<span class="required">*</span>
                                    </label>
                                    <input type="tel" class="form-control" id="phone" name="phone"
                                        placeholder="0112345678" 
                                        value="<?php echo htmlspecialchars($tenant_data['phone'] ?? ''); ?>" required>
                                    <div class="error-feedback" id="phone-error"></div>
                                </div>
                            </div>

                            <!-- Address Row -->
                            <div class="form-row single">
                                <div class="customer-form-group">
                                    <label for="address" class="form-label">
                                        <i class="fas fa-map-marker-alt"></i> Company Address
                                    </label>
                                    <textarea class="form-control" id="address" name="address" rows="3"
                                        placeholder="Enter company physical address"><?php echo htmlspecialchars($tenant_data['address'] ?? ''); ?></textarea>
                                    <div class="error-feedback" id="address-error"></div>
                                </div>
                            </div>

                            <!-- Delivery Fee & Main Admin Row -->
                            <?php if ($current_is_main_admin): ?>
                            <div class="form-row">
                                <div class="customer-form-group">
                                    <label for="delivery_fee" class="form-label">
                                        <i class="fas fa-truck"></i> Delivery Fee (LKR)
                                    </label>
                                    <input type="number" step="0.01" min="0" class="form-control" id="delivery_fee" name="delivery_fee"
                                        placeholder="0.00" value="<?php echo htmlspecialchars($tenant_data['delivery_fee'] ?? '0.00'); ?>">
                                    <div class="error-feedback" id="delivery_fee-error"></div>
                                </div>

                                <div class="customer-form-group">
                                    <label for="is_main_admin" class="form-label">
                                        <i class="fas fa-crown"></i> Main Admin<span class="required">*</span>
                                    </label>
                                    <select class="form-select" id="is_main_admin" name="is_main_admin" required>
                                        <option value="0" <?php echo (($tenant_data['is_main_admin'] ?? 0) == 0) ? 'selected' : ''; ?>>No</option>
                                        <option value="1" <?php echo (($tenant_data['is_main_admin'] ?? 0) == 1) ? 'selected' : ''; ?>>Yes</option>
                                    </select>
                                    <div class="error-feedback" id="is_main_admin-error"></div>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="form-row single">
                                <div class="customer-form-group">
                                    <label for="delivery_fee" class="form-label">
                                        <i class="fas fa-truck"></i> Delivery Fee (LKR)
                                    </label>
                                    <input type="number" step="0.01" min="0" class="form-control" id="delivery_fee" name="delivery_fee"
                                        placeholder="0.00" value="<?php echo htmlspecialchars($tenant_data['delivery_fee'] ?? '0.00'); ?>">
                                    <div class="error-feedback" id="delivery_fee-error"></div>
                                </div>
                            </div>
                            <input type="hidden" name="is_main_admin" value="<?php echo (int)($tenant_data['is_main_admin'] ?? 0); ?>">
                            <?php endif; ?>

                            <!-- Logo & Favicon Upload Row -->
                            <div class="form-row">
                                <div class="customer-form-group">
                                    <label for="logo" class="form-label">
                                        <i class="fas fa-image"></i> Company Logo
                                    </label>
                                    <input type="file" class="form-control" id="logo" name="logo" accept=".jpg,.jpeg,.png,.gif">
                                    <?php if (!empty($tenant_data['logo_url'])): ?>
                                        <div style="margin-top: 8px; display: flex; align-items: center; gap: 10px;">
                                            <img src="<?php echo htmlspecialchars($tenant_data['logo_url']); ?>" alt="Current Logo" style="max-width: 80px; max-height: 40px; border: 1px solid #ddd; border-radius: 4px;">
                                            <label style="font-size: 12px; color: #6c757d;">
                                                <input type="checkbox" name="remove_logo" value="1"> Remove logo
                                            </label>
                                        </div>
                                    <?php endif; ?>
                                    <div class="error-feedback" id="logo-error"></div>
                                    <div class="phone-hint">Upload logo (JPG, PNG, GIF)</div>
                                </div>

                                <div class="customer-form-group">
                                    <label for="fav_icon" class="form-label">
                                        <i class="fas fa-bolt"></i> Favicon
                                    </label>
                                    <input type="file" class="form-control" id="fav_icon" name="fav_icon" accept=".ico,.jpg,.jpeg,.png">
                                    <?php if (!empty($tenant_data['fav_icon_url'])): ?>
                                        <div style="margin-top: 8px; display: flex; align-items: center; gap: 10px;">
                                            <img src="<?php echo htmlspecialchars($tenant_data['fav_icon_url']); ?>" alt="Current Favicon" style="max-width: 32px; max-height: 32px; border: 1px solid #ddd; border-radius: 4px;">
                                            <label style="font-size: 12px; color: #6c757d;">
                                                <input type="checkbox" name="remove_favicon" value="1"> Remove favicon
                                            </label>
                                        </div>
                                    <?php endif; ?>
                                    <div class="error-feedback" id="fav_icon-error"></div>
                                    <div class="phone-hint">Upload favicon (ICO, PNG, JPG)</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Submit Buttons -->
                    <div class="submit-container">
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-save"></i> Update Tenant
                        </button>
                        <button type="button" class="btn btn-secondary ms-2" id="cancelBtn" onclick="window.location.href='<?php echo $current_is_main_admin ? 'tenant_list.php' : '../dashboard/index.php'; ?>'">
                            <i class="fas fa-times"></i> <?php echo $current_is_main_admin ? 'Back to All Tenants' : 'Back to Dashboard'; ?>
                        </button>
                    </div>
                </form>
            </div>
            <!-- [ Main Content ] end -->
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

    <!-- jQuery (make sure this is loaded before your custom script) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>

    <script>
        // Store original values for change detection
        const originalValues = {
            company_name: '<?php echo addslashes($tenant_data['company_name'] ?? ''); ?>',
            contact_person: '<?php echo addslashes($tenant_data['contact_person'] ?? ''); ?>',
            email: '<?php echo addslashes($tenant_data['email'] ?? ''); ?>',
            phone: '<?php echo addslashes($tenant_data['phone'] ?? ''); ?>',
            address: '<?php echo addslashes($tenant_data['address'] ?? ''); ?>',
            delivery_fee: '<?php echo ($tenant_data['delivery_fee'] ?? '0.00'); ?>',
            is_main_admin: '<?php echo (int)($tenant_data['is_main_admin'] ?? 0); ?>'
        };

        $(document).ready(function() {
            // Initialize form
            initializeForm();
            
            // AJAX Form submission
            $('#editTenantForm').on('submit', function(e) {
                e.preventDefault();
                
                // Clear previous validations
                clearAllValidations();
                
                // Check for changes first
                if (!hasFormChanged()) {
                    toastManager.warning('No changes were made to the tenant.');
                    return;
                }
                
                // Validate form
                if (validateForm()) {
                    submitFormAjax();
                } else {
                    // Scroll to first error
                    scrollToFirstError();
                }
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
            $submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Updating Tenant...');
            
            // Prepare form data
            const formData = new FormData($('#editTenantForm')[0]);
            
            // AJAX request
            $.ajax({
                url: 'update_tenant.php',
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
                        toastManager.success(response.message || 'Tenant updated successfully!');
                        updateOriginalValues();
                        
                        // Redirect after 2 seconds (company admin goes to dashboard)
                        var redirectUrl = <?php echo json_encode($current_is_main_admin ? 'tenant_list.php' : '../dashboard/index.php'); ?>;
                        setTimeout(function() {
                            window.location.href = redirectUrl;
                        }, 2000);
                    } else {
                        if (response.errors) {
                            showFieldErrors(response.errors);
                        }
                        toastManager.error(response.message || 'Failed to update tenant. Please try again.');
                    }
                },
                error: function(xhr, status, error) {
                    hideLoading();
                    $submitBtn.prop('disabled', false).html(originalText);
                    
                    let errorMessage = 'An error occurred while updating the tenant.';
                    
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
        
        // Change detection functions
        function hasFormChanged() {
            return (
                $('#company_name').val() !== originalValues.company_name ||
                $('#contact_person').val() !== originalValues.contact_person ||
                $('#email').val() !== originalValues.email ||
                $('#phone').val() !== originalValues.phone ||
                $('#address').val() !== originalValues.address ||
                $('#delivery_fee').val() !== originalValues.delivery_fee ||
                $('[name="is_main_admin"]').val() !== originalValues.is_main_admin ||
                $('#logo').get(0).files.length > 0 ||
                $('#fav_icon').get(0).files.length > 0 ||
                $('input[name="remove_logo"]').is(':checked') ||
                $('input[name="remove_favicon"]').is(':checked')
            );
        }
        
        function updateOriginalValues() {
            originalValues.company_name = $('#company_name').val();
            originalValues.contact_person = $('#contact_person').val();
            originalValues.email = $('#email').val();
            originalValues.phone = $('#phone').val();
            originalValues.address = $('#address').val();
            originalValues.delivery_fee = $('#delivery_fee').val();
            originalValues.is_main_admin = $('[name="is_main_admin"]').val();
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
                const validation = validateEmail($(this).val());
                if (!validation.valid) {
                    showError('email', validation.message);
                } else {
                    showSuccess('email');
                }
                
                // Show email suggestions
                const suggestion = suggestEmail($(this).val());
                if (suggestion && suggestion !== $(this).val().toLowerCase()) {
                    $('#email-suggestions').html(`Did you mean <a href="#" onclick="$('#email').val('${suggestion}'); $('#email-suggestions').html(''); $('#email').focus(); return false;">${suggestion}</a>?`);
                } else {
                    $('#email-suggestions').html('');
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
                    const $inputs = $('input:visible, select, textarea');
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
            if (name.length > 15) {
                return { valid: false, message: 'Company name is too long (maximum 15 characters)' };
            }
            return { valid: true, message: '' };
        }

        function validateContactPerson(name) {
            if (name.trim() === '') {
                return { valid: false, message: 'Contact person name is required' };
            }
            if (name.trim().length < 2) {
                return { valid: false, message: 'Contact person name must be at least 2 characters long' };
            }
            if (name.length > 255) {
                return { valid: false, message: 'Contact person name is too long (maximum 255 characters)' };
            }
            if (!/^[a-zA-Z\s.\-']+$/.test(name)) {
                return { valid: false, message: 'Name can only contain letters, spaces, dots, hyphens, and apostrophes' };
            }
            return { valid: true, message: '' };
        }

        function validateEmail(email) {
            if (email.trim() === '') {
                return { valid: false, message: 'Email address is required' };
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

        function validatePhone(phone) {
            if (phone.trim() === '') {
                return { valid: false, message: 'Phone number is required' };
            }
            const cleanPhone = phone.replace(/\s+/g, '');
            const sriLankanPhoneRegex = /^(0|94|\+94)?[1-9][0-9]{8}$/;
            if (!sriLankanPhoneRegex.test(cleanPhone)) {
                return { valid: false, message: 'Please enter a valid Sri Lankan phone number (e.g., 0112345678)' };
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
                { field: 'email', validator: validateEmail, value: email },
                { field: 'phone', validator: validatePhone, value: phone }
            ];
            
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