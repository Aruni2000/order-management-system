<?php
// Start session at the very beginning
session_start();

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /order_management/dist/pages/login.php");
    exit();
}

// Include the database connection file
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/connection/db_connection.php');

// Check database connection
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("Connection failed: " . $conn->connect_error);
}

// Function to generate CSRF token
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/sidebar.php');

$is_main_admin = $_SESSION['is_main_admin'];
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr"
    data-pc-theme="light">

<head>
    <title>Order Management Admin Portal - Add New Customer</title>

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
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
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
        0% {
            transform: rotate(0deg);
        }

        100% {
            transform: rotate(360deg);
        }
    }

    /* Autocomplete Styles */
    .autocomplete-container {
        position: relative;
        z-index: 100;
    }

    .autocomplete-dropdown {
        position: fixed !important;
        background: white;
        border: 1px solid #dee2e6;
        border-top: 2px solid #4680ff;
        border-radius: 0 0 8px 8px;
        max-height: 280px;
        overflow-y: auto;
        overflow-x: hidden;
        z-index: 99999 !important;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
        display: none;
        margin-top: 2px;
        min-width: 300px;
    }

    .autocomplete-dropdown.show {
        display: block !important;
    }

    .autocomplete-item {
        padding: 12px 16px;
        cursor: pointer;
        border-bottom: 1px solid #f0f0f0;
        transition: all 0.2s ease;
        background: white;
    }

    .autocomplete-item:last-child {
        border-bottom: none;
        border-radius: 0 0 8px 8px;
    }

    .autocomplete-item:hover,
    .autocomplete-item.active {
        background: linear-gradient(90deg, #f8f9fa 0%, #e9ecef 100%);
        padding-left: 20px;
    }

    .autocomplete-item .city-name {
        font-weight: 600;
        color: #2c3e50;
        font-size: 14px;
    }

    .autocomplete-item .city-details {
        font-size: 12px;
        color: #6c757d;
        margin-top: 4px;
        font-style: italic;
    }

    .autocomplete-no-results {
        padding: 20px 15px;
        text-align: center;
        color: #6c757d;
        font-style: italic;
    }

    .autocomplete-no-results i {
        display: block;
        font-size: 24px;
        margin-bottom: 8px;
        opacity: 0.5;
    }

    .autocomplete-loading {
        padding: 20px 15px;
        text-align: center;
        color: #495057;
    }

    .autocomplete-loading i {
        margin-right: 8px;
        animation: spin 1s linear infinite;
    }

    #city_input {
        position: relative;
        z-index: 1;
        transition: border-color 0.3s ease;
    }

    #city_input:focus {
        border-color: #4680ff;
        box-shadow: 0 0 0 0.2rem rgba(70, 128, 255, 0.25);
    }

    #city_input.loading {
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 20 20'%3E%3Cpath fill='%23666' d='M10 3a7 7 0 100 14 7 7 0 000-14zm0 12a5 5 0 110-10 5 5 0 010 10z' opacity='.3'/%3E%3Cpath fill='%23666' d='M10 1a9 9 0 100 18 9 9 0 000-18zm0 16a7 7 0 110-14 7 7 0 010 14z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 12px center;
        background-size: 18px;
        padding-right: 40px;
    }

    /* Make sure parent containers don't clip */
    .form-section,
    .section-content,
    .form-row,
    .customer-form-group {
        overflow: visible !important;
    }

    .main-container {
        overflow: visible !important;
    }

    /* Scrollbar styling for dropdown */
    .autocomplete-dropdown::-webkit-scrollbar {
        width: 8px;
    }

    .autocomplete-dropdown::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 0 0 8px 0;
    }

    .autocomplete-dropdown::-webkit-scrollbar-thumb {
        background: #888;
        border-radius: 4px;
    }

    .autocomplete-dropdown::-webkit-scrollbar-thumb:hover {
        background: #555;
    }
    </style>
</head>

<body>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/loader.php'); ?>

    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <div class="spinner"></div>
            <h5>Processing...</h5>
            <p>Please wait while we add the customer</p>
        </div>
    </div>

    <div class="pc-container">
        <div class="pc-content">
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">Add New Customer <!-- "-" <?php echo $is_main_admin;?>--></h5>
                    </div>
                </div>
            </div>

            <div class="main-container">
                <form method="POST" id="addCustomerForm" class="customer-form" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="city_id" id="city_id" value="">
                    <div class="form-section">
                        <div class="section-content">

                            <?php if ($is_main_admin == 1) { ?>
                            <div class="form-row">
                                <div class="customer-form-group">
                                    <label for="status" class="form-label">
                                        Tenent User <span class="required">*</span>
                                    </label>
                                    <?php 
                                        // Fetch and store tenant details in session
                                            $sql_tenant = "SELECT tenant_id,company_name FROM tenants WHERE status = 'Active'";
                                            $stmt_tenant = $conn->prepare($sql_tenant);
                                            $stmt_tenant->execute();
                                            $result_tenant = $stmt_tenant->get_result();
                                            ?>
                                    <select class="form-select" id="teanetID" name="teanetID" required>
                                        <option value="0">Select Tenent User</option>
                                        <?php while ($row = $result_tenant->fetch_assoc()) {?>
                                        <option value="<?php echo $row['tenant_id']; ?>">
                                            <?php echo $row['company_name']; ?>
                                        </option>
                                        <?php } ?>
                                    </select>
                                    <div class="error-feedback" id="status-error"></div>
                                </div>
                            </div>
                            <?php } else { ?>
                            <input type="hidden" name="teanetID" value="0">
                            <?php } ?>


                            <div class="form-row">
                                <div class="customer-form-group">
                                    <label for="name" class="form-label">
                                        <i class="fas fa-user"></i> Full Name<span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="name" name="name"
                                        placeholder="Enter customer's full name" required>
                                    <div class="error-feedback" id="name-error"></div>
                                </div>

                                <div class="customer-form-group">
                                    <label for="email" class="form-label">
                                        <i class="fas fa-envelope"></i> Email Address
                                    </label>
                                    <input type="email" class="form-control" id="email" name="email"
                                        placeholder="customer@example.com">
                                    <div class="error-feedback" id="email-error"></div>
                                    <div class="email-suggestions" id="email-suggestions"></div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="customer-form-group">
                                    <label for="phone" class="form-label">
                                        <i class="fas fa-phone"></i> Phone Number<span class="required">*</span>
                                    </label>
                                    <input type="tel" class="form-control" id="phone" name="phone"
                                        placeholder="0771234567" required>
                                    <div class="error-feedback" id="phone-error"></div>
                                    <div class="phone-hint">Enter 10-digit Sri Lankan mobile number</div>
                                </div>

                                <div class="customer-form-group">
                                    <label for="phone_2" class="form-label">
                                        <i class="fas fa-phone"></i> Phone Number 2 (Optional)
                                    </label>
                                    <input type="tel" class="form-control" id="phone_2" name="phone_2"
                                        placeholder="0771234567">
                                    <div class="error-feedback" id="phone_2-error"></div>
                                    <div class="phone-hint">Enter 10-digit Sri Lankan mobile number (optional)</div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="customer-form-group">
                                    <label for="status" class="form-label">
                                        <i class="fas fa-toggle-on"></i> Status<span class="required">*</span>
                                    </label>
                                    <select class="form-select" id="status" name="status" required>
                                        <option value="Active" selected>Active</option>
                                        <option value="Inactive">Inactive</option>
                                    </select>
                                    <div class="error-feedback" id="status-error"></div>
                                </div>
                            </div>
                        
                            <div class="form-row">
                                <div class="customer-form-group">
                                    <label for="address_line1" class="form-label">
                                        <i class="fas fa-home"></i> Address Line 1<span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="address_line1" name="address_line1"
                                        placeholder="House number, street name" required>
                                    <div class="error-feedback" id="address_line1-error"></div>
                                </div>

                                <div class="customer-form-group">
                                    <label for="address_line2" class="form-label">
                                        <i class="fas fa-building"></i> Address Line 2
                                    </label>
                                    <input type="text" class="form-control" id="address_line2" name="address_line2"
                                        placeholder="Apartment, suite, building (optional)">
                                    <div class="error-feedback" id="address_line2-error"></div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="customer-form-group autocomplete-container">
                                    <label for="city_input" class="form-label">
                                        <i class="fas fa-city"></i> City<span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="city_input"
                                        placeholder="Start typing city name..." autocomplete="off" required>
                                    <div class="autocomplete-dropdown" id="cityDropdown"></div>
                                    <div class="error-feedback" id="city_id-error"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="submit-container">
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-user-plus"></i> Add Customer
                        </button>
                        <button type="button" class="btn btn-secondary ms-2" id="resetBtn">
                            <i class="fas fa-undo"></i> Reset Form
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/footer.php'); ?>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/scripts.php'); ?>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>

    <script>
    $(document).ready(function() {
        initializeForm();
        setupCityAutocomplete();

        $('#addCustomerForm').on('submit', function(e) {
            e.preventDefault();
            clearAllValidations();

            if (validateForm()) {
                submitFormAjax();
            } else {
                scrollToFirstError();
            }
        });

        $('#resetBtn').on('click', function() {
            resetForm();
        });

        setupRealTimeValidation();
    });

    // City Autocomplete Setup
    let citySearchTimeout;
    let selectedCityId = null;
    let currentFocusIndex = -1;

    function setupCityAutocomplete() {
        const $cityInput = $('#city_input');
        const $dropdown = $('#cityDropdown');
        const $cityIdHidden = $('#city_id');

        // Handle input
        $cityInput.on('input', function() {
            const searchTerm = $(this).val().trim();
            selectedCityId = null;
            $cityIdHidden.val('');

            clearTimeout(citySearchTimeout);

            if (searchTerm.length < 2) {
                $dropdown.removeClass('show').empty();
                return;
            }

            // Show loading
            $cityInput.addClass('loading');

            citySearchTimeout = setTimeout(function() {
                searchCities(searchTerm);
            }, 300);
        });

        // Handle keyboard navigation
        $cityInput.on('keydown', function(e) {
            const $items = $dropdown.find('.autocomplete-item');

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                currentFocusIndex++;
                if (currentFocusIndex >= $items.length) currentFocusIndex = 0;
                setActiveItem($items);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                currentFocusIndex--;
                if (currentFocusIndex < 0) currentFocusIndex = $items.length - 1;
                setActiveItem($items);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (currentFocusIndex > -1 && $items.length > 0) {
                    $items.eq(currentFocusIndex).click();
                }
            } else if (e.key === 'Escape') {
                $dropdown.removeClass('show').empty();
                currentFocusIndex = -1;
            }
        });

        // Close dropdown when clicking outside
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.autocomplete-container').length) {
                $dropdown.removeClass('show').empty();
                currentFocusIndex = -1;
            }
        });
    }

    function setActiveItem($items) {
        $items.removeClass('active');
        if (currentFocusIndex >= 0 && currentFocusIndex < $items.length) {
            $items.eq(currentFocusIndex).addClass('active');
        }
    }

    function searchCities(searchTerm) {
        console.log('Searching for:', searchTerm);

        $.ajax({
            url: 'fetch_cities.php',
            type: 'GET',
            data: {
                term: searchTerm
            },
            dataType: 'json',
            success: function(response) {
                console.log('Search results:', response);
                $('#city_input').removeClass('loading');
                displayCityResults(response);
            },
            error: function(xhr, status, error) {
                $('#city_input').removeClass('loading');
                console.error('City search error:', error);
                console.error('Response:', xhr.responseText);

                const $dropdown = $('#cityDropdown');
                $dropdown.html(
                    '<div class="autocomplete-no-results">Error loading cities. Please try again.</div>'
                    );
                $dropdown.addClass('show');
            }
        });
    }

    function displayCityResults(cities) {
        const $dropdown = $('#cityDropdown');
        const $input = $('#city_input');
        $dropdown.empty();
        currentFocusIndex = -1;

        console.log('Displaying results, count:', cities ? cities.length : 0);

        if (!cities || cities.length === 0) {
            $dropdown.html(
                '<div class="autocomplete-no-results"><i class="fas fa-search"></i><br>No cities found</div>');
            positionDropdown();
            $dropdown.addClass('show');
            return;
        }

        cities.forEach(function(city) {
            const details = [];
            if (city.postal_code) details.push('Postal: ' + city.postal_code);

            const detailsHtml = details.length > 0 ?
                '<div class="city-details">' + details.join(' • ') + '</div>' :
                '';

            const $item = $('<div class="autocomplete-item">')
                .html('<div class="city-name">' + escapeHtml(city.city_name) + '</div>' + detailsHtml)
                .data('city-id', city.city_id)
                .data('city-name', city.city_name);

            $item.on('mousedown', function(e) {
                e.preventDefault();
                selectCity($(this).data('city-id'), $(this).data('city-name'));
            });

            $dropdown.append($item);
        });

        positionDropdown();
        $dropdown.addClass('show');
        console.log('Dropdown shown');
    }

    function positionDropdown() {
        const $input = $('#city_input');
        const $dropdown = $('#cityDropdown');
        const offset = $input.offset();
        const inputHeight = $input.outerHeight();
        const inputWidth = $input.outerWidth();

        $dropdown.css({
            // 'top': (offset.top + inputHeight) + 'px',
            'top': '450 px',
            'left': offset.left + 'px',
            'width': inputWidth + 'px'
        });
    }

    $(window).on('resize scroll', function() {
        if ($('#cityDropdown').hasClass('show')) {
            positionDropdown();
        }
    });

    function selectCity(cityId, cityName) {
        selectedCityId = cityId;
        $('#city_id').val(cityId);
        $('#city_input').val(cityName);
        $('#cityDropdown').removeClass('show').empty();
        currentFocusIndex = -1;

        showSuccess('city_id');
        clearError('city_id');
    }

    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) {
            return map[m];
        });
    }

    function submitFormAjax() {
        showLoading();

        const $submitBtn = $('#submitBtn');
        const originalText = $submitBtn.html();
        $submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Adding Customer...');

        const formData = new FormData($('#addCustomerForm')[0]);

        $.ajax({
            url: 'save_customer.php',
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
                    showSuccessNotification(response.message || 'Customer added successfully!');
                    setTimeout(function() {
                        resetForm();
                    }, 1500);
                } else {
                    if (response.errors) {
                        showFieldErrors(response.errors);
                    }
                    showErrorNotification(response.message || 'Failed to add customer.');
                }
            },
            error: function(xhr, status, error) {
                hideLoading();
                $submitBtn.prop('disabled', false).html(originalText);

                let errorMessage = 'An error occurred while adding the customer.';
                if (status === 'timeout') {
                    errorMessage = 'Request timeout. Please try again.';
                } else if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMessage = xhr.responseJSON.message;
                }

                showErrorNotification(errorMessage);
            }
        });
    }

    function showFieldErrors(errors) {
        $.each(errors, function(field, message) {
            showError(field, message);
        });
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
        $('#' + notificationId).fadeOut(300, function() {
            $(this).remove();
        });
    }

    function resetForm() {
        $('#addCustomerForm')[0].reset();
        clearAllValidations();
        $('#city_id').val('');
        $('#city_input').val('');
        selectedCityId = null;
        $('#email-suggestions').html('');
        $('#cityDropdown').removeClass('show').empty();
        $('#name').focus();
    }

    function clearAllValidations() {
        $('.form-control, .form-select').removeClass('is-valid is-invalid');
        $('.error-feedback').hide().text('');
    }

    function scrollToFirstError() {
        const $firstError = $('.is-invalid').first();
        if ($firstError.length) {
            $('html, body').animate({
                scrollTop: $firstError.offset().top - 100
            }, 500);
            $firstError.focus();
        }
    }

    function initializeForm() {
        $('#name').focus();

        // Format phone 1
        $('#phone').on('input', function() {
            let value = this.value.replace(/\D/g, '');
            if (value.length > 10) {
                value = value.substring(0, 10);
            }
            this.value = value;
        });

        // Format phone 2
        $('#phone_2').on('input', function() {
            let value = this.value.replace(/\D/g, '');
            if (value.length > 10) {
                value = value.substring(0, 10);
            }
            this.value = value;
        });

        $('#email').on('input', function() {
            this.value = this.value.toLowerCase().trim();
            $('#email-suggestions').html('');
        });
    }

    function setupRealTimeValidation() {
        $('#name').on('blur', function() {
            const validation = validateName($(this).val());
            validation.valid ? showSuccess('name') : showError('name', validation.message);
        });

        $('#email').on('blur', function() {
            const validation = validateEmail($(this).val());
            validation.valid ? showSuccess('email') : showError('email', validation.message);
        });

        $('#phone').on('blur', function() {
            const validation = validatePhone($(this).val());
            validation.valid ? showSuccess('phone') : showError('phone', validation.message);
        });

        $('#phone_2').on('blur', function() {
            const validation = validatePhone2($(this).val());
            if ($(this).val().trim()) {
                validation.valid ? showSuccess('phone_2') : showError('phone_2', validation.message);
            } else {
                clearError('phone_2');
            }
        });

        $('#address_line1').on('blur', function() {
            const validation = validateAddressLine1($(this).val());
            validation.valid ? showSuccess('address_line1') : showError('address_line1', validation.message);
        });
    }

    function validateForm() {
        let isValid = true;

        const validations = [{
                field: 'name',
                validator: validateName,
                value: $('#name').val()
            },
            {
                field: 'email',
                validator: validateEmail,
                value: $('#email').val()
            },
            {
                field: 'phone',
                validator: validatePhone,
                value: $('#phone').val()
            },
            {
                field: 'phone_2',
                validator: validatePhone2,
                value: $('#phone_2').val()
            },
            {
                field: 'address_line1',
                validator: validateAddressLine1,
                value: $('#address_line1').val()
            },
            {
                field: 'city_id',
                validator: validateCity,
                value: $('#city_id').val()
            }
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

    function validateName(name) {
        if (!name.trim()) return {
            valid: false,
            message: 'Customer name is required'
        };
        if (name.trim().length < 2) return {
            valid: false,
            message: 'Name must be at least 2 characters'
        };
        if (name.length > 255) return {
            valid: false,
            message: 'Name is too long'
        };
        if (!/^[a-zA-Z\s.\-']+$/.test(name)) return {
            valid: false,
            message: 'Invalid characters in name'
        };
        return {
            valid: true
        };
    }
    // Complete validation functions - add these to your script section

    function validateEmail(email) {
        if (!email.trim()) return {
            valid: true
        }; // Optional field
        if (email.length > 100) return {
            valid: false,
            message: 'Email is too long'
        };
        const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!regex.test(email)) return {
            valid: false,
            message: 'Invalid email format'
        };
        return {
            valid: true
        };
    }

    function validatePhone(phone) {
        if (!phone.trim()) return {
            valid: false,
            message: 'Phone is required'
        };
        const digits = phone.replace(/\D/g, '');
        if (digits.length !== 10) return {
            valid: false,
            message: 'Phone must be 10 digits'
        };
        if (!/^0[1-9][0-9]{8}$/.test(digits)) return {
            valid: false,
            message: 'Invalid phone format'
        };
        return {
            valid: true
        };
    }

    function validatePhone2(phone) {
        // If empty, it's valid (optional field)
        if (!phone || !phone.trim()) return {
            valid: true
        };

        const digits = phone.replace(/\D/g, '');
        if (digits.length !== 10) return {
            valid: false,
            message: 'Phone 2 must be 10 digits'
        };
        if (!/^0[1-9][0-9]{8}$/.test(digits)) return {
            valid: false,
            message: 'Invalid phone 2 format'
        };

        // Check if same as phone 1
        const phone1 = $('#phone').val().replace(/\D/g, '');
        if (digits === phone1) return {
            valid: false,
            message: 'Phone 2 cannot be same as Phone 1'
        };

        return {
            valid: true
        };
    }

    function validateAddressLine1(address) {
        if (!address.trim()) return {
            valid: false,
            message: 'Address Line 1 is required'
        };
        if (address.trim().length < 3) return {
            valid: false,
            message: 'Address too short'
        };
        if (address.length > 255) return {
            valid: false,
            message: 'Address is too long'
        };
        return {
            valid: true
        };
    }

    function validateCity(cityId) {
        if (!cityId || cityId.trim() === '') return {
            valid: false,
            message: 'Please select a city'
        };
        return {
            valid: true
        };
    }

    function showError(fieldId, message) {
        $('#' + fieldId).addClass('is-invalid').removeClass('is-valid');
        $('#' + fieldId + '-error').text(message).show();
    }

    function showSuccess(fieldId) {
        $('#' + fieldId).addClass('is-valid').removeClass('is-invalid');
        $('#' + fieldId + '-error').hide();
    }

    function clearError(fieldId) {
        $('#' + fieldId).removeClass('is-invalid');
        $('#' + fieldId + '-error').hide();
    }
    </script>
</body>

</html>