<?php
// Start session at the very beginning
session_start();

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // Clear any existing output buffers
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

// User role (role_id 2) cannot add products – view-only
if (isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] == 2) {
    header("Location: product_list.php");
    exit();
}

// Include the database connection file
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Function to generate CSRF token
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}



// Fetch tenants if main admin
$is_main_admin = isset($_SESSION['is_main_admin']) ? (int)$_SESSION['is_main_admin'] : 0;
$session_tenant_id = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;

// Fetch all categories for the single Category dropdown
// Main admin sees all (filtered live by selected target tenant); others only their own tenant
$categories = [];
try {
    if ($is_main_admin && $_SESSION['role_id'] == 1) {
        $catRes = $conn->query("SELECT id, name, tenant_id FROM categories WHERE status = 'active' ORDER BY name ASC");
    } else {
        $catRes = $conn->query("SELECT id, name, tenant_id FROM categories WHERE status = 'active' AND tenant_id = $session_tenant_id ORDER BY name ASC");
    }
    if ($catRes) {
        while ($row = $catRes->fetch_assoc()) {
            $categories[] = $row;
        }
    }
} catch (Exception $e) {
    error_log("Error fetching categories: " . $e->getMessage());
}

$tenants = [];
if ($is_main_admin && $_SESSION['role_id'] == 1) {
    try {
        $tRes = $conn->query("SELECT tenant_id, company_name FROM tenants WHERE status = 'active' ORDER BY is_main_admin DESC, company_name ASC");
        if ($tRes) {
            while ($row = $tRes->fetch_assoc()) {
                $tenants[] = $row;
            }
        }
    } catch (Exception $e) {
        error_log("Error fetching tenants: " . $e->getMessage());
    }
}
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <!-- TITLE -->
    <title>Add Product | <?= htmlspecialchars($_SESSION['company_name'] ?? '') ?></title>

    <?php
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php');
    ?>
    
    <!-- [Template CSS Files] -->
    <link rel="stylesheet" href="../assets/css/products.css" />
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
 
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
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 10000;
        }

        .loading-spinner {
            text-align: center;
            color: white;
        }

        .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid rgba(255, 255, 255, 0.3);
            border-top: 5px solid #fff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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

        .select2-container--default .select2-selection--single {
            height: 45px !important;
            border: 1px solid #ced4da !important;
            border-radius: 8px !important;
            padding: 8px 12px !important;
            display: flex;
            align-items: center;
            background-color: #fff !important;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: inherit !important;
            color: #495057 !important;
            padding-left: 0 !important;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 43px !important;
            top: 1px !important;
            right: 10px !important;
        }
        .select2-dropdown {
            border: 1px solid #ced4da !important;
            border-radius: 8px !important;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1) !important;
            z-index: 10001;
        }

        /* Integrated In-Field Search Styling */
        .select2-container--open .select2-selection__rendered {
            visibility: hidden;
        }
        .select2-container--open .select2-dropdown--below {
            margin-top: -45px !important;
            border-top: 1px solid #ced4da !important;
        }
        .select2-container--open .select2-dropdown--above {
            margin-top: 45px !important;
            border-bottom: 1px solid #ced4da !important;
        }
        .select2-search--dropdown {
            padding: 0 !important;
        }
        .select2-search--dropdown .select2-search__field {
            height: 44px !important;
            padding: 8px 12px !important;
            border: none !important;
            border-bottom: 1px solid #ced4da !important;
            border-radius: 8px 8px 0 0 !important;
            outline: none !important;
        }
        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background-color: #1565c0 !important;
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
            <p>Please wait while we add the product</p>
        </div>
    </div>

    <!-- [ Main Content ] start -->
    <div class="pc-container">
        <div class="pc-content">
            <!-- [ breadcrumb ] start -->
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">Add New Product</h5>
                    </div>
                </div>
            </div>
            <!-- [ breadcrumb ] end -->

            <!-- [ Main Content ] start -->
            <div class="main-container">
                <form method="POST" id="addProductForm" class="product-form" novalidate>
                    <!-- CSRF Token -->
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <!-- Product Details Section -->
                    <div class="form-section">
                        <div class="section-content">
                            <!-- First Row: Name and Status -->
                            <div class="form-row">
                                <div class="product-form-group">
                                    <label for="name" class="form-label">
                                        <i class="fas fa-box"></i> Product Name<span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="name" name="name"
                                        placeholder="Enter product name" required maxlength="255">
                                    <div class="error-feedback" id="name-error"></div>
                                </div>

                                <div class="product-form-group">
                                    <label for="status" class="form-label">
                                        <i class="fas fa-toggle-on"></i> Status<span class="required">*</span>
                                    </label>
                                    <select class="form-select" id="status" name="status" required>
                                        <option value="active" selected>Active</option>
                                        <option value="inactive">Inactive</option>
                                    </select>
                                    <div class="error-feedback" id="status-error"></div>
                                </div>
                            </div>

                            <!-- Tenant Selector (Main Admin Only) -->
                            <?php if ($is_main_admin && $_SESSION['role_id'] == 1): ?>
                            <div class="form-row">
                                <div class="product-form-group full-width">
                                    <label for="tenant_id" class="form-label">
                                        <i class="fas fa-building"></i> Tenant Company<span class="required">*</span>
                                    </label>
                                    <select class="form-select" id="tenant_id" name="tenant_id" required>
                                        <?php foreach ($tenants as $t): ?>
                                            <option value="<?php echo $t['tenant_id']; ?>" <?php echo ($t['tenant_id'] == $session_tenant_id) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($t['company_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="error-feedback" id="tenant_id-error"></div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Category and Product Code -->
                            <div class="form-row">
                                <div class="product-form-group">
                                    <label for="category_id" class="form-label">
                                        <i class="fas fa-tags"></i> Category<span class="required">*</span>
                                    </label>
                                    <select class="form-select" id="category_id" name="category_id" data-placeholder="Search category..." required>
                                        <option value=""></option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?php echo $cat['id']; ?>" data-tenant="<?php echo $cat['tenant_id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="error-feedback" id="category_id-error"></div>
                                </div>

                                <div class="product-form-group">
                                    <label for="product_code" class="form-label">
                                        <i class="fas fa-barcode"></i> Product Code<span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="product_code" name="product_code"
                                        placeholder="Enter product code" required maxlength="50">
                                    <div class="error-feedback" id="product_code-error"></div>
                                </div>
                            </div>

                            <!-- Stock is managed via GRN/orders; new products start at 0 -->
                            <input type="hidden" name="stock_quantity" value="0">

                            <!-- Stock Warning Level - only if enabled -->
                            <?php if (isset($_SESSION['allow_inventory']) && $_SESSION['allow_inventory'] == 1): ?>
                            <div class="form-row">
                                <div class="product-form-group">
                                    <label for="low_stock_threshold" class="form-label">
                                        <i class="fas fa-exclamation-circle"></i> Stock Warning Level<span class="required">*</span>
                                    </label>
                                    <input type="number" class="form-control" id="low_stock_threshold" name="low_stock_threshold"
                                        placeholder="10" required min="0" step="1" value="10">
                                    <div class="error-feedback" id="low_stock_threshold-error"></div>
                                </div>
                            </div>
                            <?php else: ?>
                            <input type="hidden" name="low_stock_threshold" value="0">
                            <?php endif; ?>

                            <!-- Fourth Row: Description -->
                           <div class="form-row">
                            <div class="product-form-group full-width">
                                <label for="description" class="form-label">
                                    <i class="fas fa-align-left"></i> Description <span class="required">*</span>
                                </label>
                                <textarea class="form-control" id="description" name="description" rows="4"
                                    placeholder="Enter product description" required></textarea>
                                <div class="error-feedback" id="description-error"></div>
                                <div class="char-counter">
                                    <span id="desc-char-count">0</span> characters
                                </div>
                            </div>
                        </div>

                        </div>
                    </div>

                    <!-- Submit Buttons -->
                    <div class="submit-container">
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-plus"></i> Add Product
                        </button>
                        <button type="button" class="btn btn-secondary ms-2" id="resetBtn">
                            <i class="fas fa-undo"></i> Reset Form
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
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
        $(document).ready(function() {
            // Initialize Select2 for category with placeholder refinement
            $('#category_id').select2({
                placeholder: function() {
                    return $(this).data('placeholder');
                },
                allowClear: true,
                width: '100%'
            }).on('select2:open', function(e) {
                // Focus the search field immediately and set its placeholder
                const placeholder = $(this).data('placeholder') || 'Search...';
                const searchField = document.querySelector('.select2-search__field');
                if (searchField) {
                    searchField.placeholder = placeholder;
                    searchField.focus();
                }
            });

            // Main admin: filter category dropdown live by selected Target Company
            const allCategories = <?php echo json_encode($categories, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
            const tenantSelect = $('#tenant_id');
            if (tenantSelect.length) {
                tenantSelect.on('change', function() {
                    const selectedTenant = parseInt($(this).val(), 10) || 0;
                    const filtered = allCategories.filter(function(c) {
                        return parseInt(c.tenant_id, 10) === selectedTenant;
                    });
                    const $cat = $('#category_id');
                    $cat.empty().append('<option value=""></option>');
                    filtered.forEach(function(c) {
                        $cat.append($('<option>', { value: c.id, text: c.name }));
                    });
                    $cat.val('').trigger('change');
                });
                tenantSelect.trigger('change');
            }

            // Initialize form
            initializeForm();
            
            // AJAX Form submission
            $('#addProductForm').on('submit', function(e) {
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
            $submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Adding Product...');
            
            // Prepare form data
            const formData = new FormData($('#addProductForm')[0]);
            
            // AJAX request
            $.ajax({
                url: 'save_product.php', // Your existing save_product.php file
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                timeout: 30000, // 30 seconds timeout
                success: function(response) {
                    hideLoading();
                    $submitBtn.prop('disabled', false).html(originalText);
                    
                    if (response.success) {
                        toastManager.success(response.message || 'Product added successfully!');
                        
                        // Reset form after success
                        resetForm();
                    } else {
                        if (response.errors) {
                            // Show field-specific errors
                            showFieldErrors(response.errors);
                        }
                        
                        toastManager.error(response.message || 'Failed to add product. Please try again.');
                    }
                },
                error: function(xhr, status, error) {
                    hideLoading();
                    $submitBtn.prop('disabled', false).html(originalText);
                    
                    let errorMessage = 'An error occurred while adding the product.';
                    
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
            $('#addProductForm')[0].reset();
            
            // Refresh Select2
            $('#category_id, #status').trigger('change');
            
            clearAllValidations();
            updateCharCount();
            $('#name').focus();
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
            $('#name').focus();
            updateCharCount();
        }
        
        // Setup real-time validation
        function setupRealTimeValidation() {
            $('#name').on('blur', function() {
                const validation = validateName($(this).val());
                if (!validation.valid) {
                    showError('name', validation.message);
                } else {
                    showSuccess('name');
                }
            });
            
            $('#product_code').on('blur', function() {
                const validation = validateProductCode($(this).val());
                if (!validation.valid) {
                    showError('product_code', validation.message);
                } else {
                    showSuccess('product_code');
                }
            });
            
            $('#description').on('blur', function() {
                const validation = validateDescription($(this).val());
                if (!validation.valid) {
                    showError('description', validation.message);
                } else if ($(this).val().trim() !== '') {
                    showSuccess('description');
                } else {
                    clearValidation('description');
                }
            });

            $('#category_id').on('change', function() {
                const catValue = $(this).val();
                if (catValue) {
                    showSuccess('category_id');
                } else {
                    showError('category_id', 'Please select a category');
                }
            });
        }

        // Setup other event listeners
        function setupEventListeners() {
            // Character counter for description
            $('#description').on('input', function() {
                updateCharCount();
                if ($(this).hasClass('is-invalid')) {
                    clearValidation('description');
                }
            });

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

        // Character counter for description
        function updateCharCount() {
            const textarea = $('#description');
            const counter = $('#desc-char-count');
            if (textarea.length && counter.length) {
                counter.text(textarea.val().length);
            }
        }

        // Validation functions
        function validateName(name) {
            if (name.trim() === '') {
                return { valid: false, message: 'Product name is required' };
            }
            if (name.trim().length < 2) {
                return { valid: false, message: 'Product name must be at least 2 characters long' };
            }
            if (name.length > 255) {
                return { valid: false, message: 'Product name is too long (maximum 255 characters)' };
            }
            return { valid: true, message: '' };
        }

        function validateCategory(categoryId) {
            if (!categoryId) {
                return { valid: false, message: 'Please select a category' };
            }
            return { valid: true, message: '' };
        }

        function validateProductCode(code) {
            if (code.trim() === '') {
                return { valid: false, message: 'Product code is required' };
            }
            
            if (code.trim().length < 2) {
                return { valid: false, message: 'Product code must be at least 2 characters long' };
            }
            
            if (code.length > 50) {
                return { valid: false, message: 'Product code is too long (maximum 50 characters)' };
            }
            
            // Allow alphanumeric, hyphens, underscores
            if (!/^[a-zA-Z0-9\-_]+$/.test(code.trim())) {
                return { valid: false, message: 'Product code can only contain letters, numbers, hyphens, and underscores' };
            }
            
            return { valid: true, message: '' };
        }

       function validateDescription(description) {
            if (description.trim() === '') {
                return { valid: false, message: 'Description is required' };
            }

            if (description.length < 5) {
                return { valid: false, message: 'Description must be at least 5 characters long' };
            }

            if (description.length > 65535) {
                return { valid: false, message: 'Description is too long (maximum 65,535 characters)' };
            }

            return { valid: true, message: '' };
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
            const name = $('#name').val();
            const productCode = $('#product_code').val();
            const description = $('#description').val();
            
            // Validate required fields
            const validations = [
                { field: 'name', validator: validateName, value: name },
                { field: 'product_code', validator: validateProductCode, value: productCode },
                { field: 'description', validator: validateDescription, value: description },
                { field: 'category_id', validator: validateCategory, value: $('#category_id').val() }
            ];
            
            validations.forEach(function(validation) {
                const result = validation.validator(validation.value);
                if (!result.valid) {
                    showError(validation.field, result.message);
                    isValid = false;
                } else if (validation.field === 'description' && validation.value.trim() !== '') {
                    showSuccess(validation.field);
                } else if (validation.field !== 'description') {
                    showSuccess(validation.field);
                }
            });
            
            return isValid;
        }
    </script>
</body>
</html>