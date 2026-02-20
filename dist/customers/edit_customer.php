<?php
// Start session at the very beginning
session_start();

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

// Include the database connection file
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

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

// Get customer ID from URL parameter
$customer_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($customer_id <= 0) {
    header("Location: customer_list.php");
    exit();
}

// Fetch customer data
$customer = null;
$customerStmt = $conn->prepare("
    SELECT c.*, ct.city_name 
    FROM customers c 
    LEFT JOIN city_table ct ON c.city_id = ct.city_id 
    WHERE c.customer_id = ?
");
$customerStmt->bind_param("i", $customer_id);
$customerStmt->execute();
$customerResult = $customerStmt->get_result();

if ($customerResult->num_rows === 0) {
    header("Location: customer_list.php");
    exit();
}

$customer = $customerResult->fetch_assoc();
$customerStmt->close();

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Order Management Admin Portal - Edit Customer</title>

    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>
    
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
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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

        .form-section,
        .section-content,
        .form-row,
        .customer-form-group {
            overflow: visible !important;
        }

        .main-container {
            overflow: visible !important;
        }

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
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/loader.php'); ?>

    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <div class="spinner"></div>
            <h5>Processing...</h5>
            <p>Please wait while we update the customer</p>
        </div>
    </div>

    <div class="pc-container">
        <div class="pc-content">
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">Edit Customer</h5>
                    </div>
                    <div class="page-header-breadcrumb">
                        <ul class="breadcrumb">
                            <li class="breadcrumb-item">
                                <a href="customer_list.php">Customer List</a>
                            </li>
                            <li class="breadcrumb-item active">Edit Customer</li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="main-container">
                <form method="POST" id="editCustomerForm" class="customer-form" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="customer_id" value="<?= $customer['customer_id'] ?>">
                    <input type="hidden" name="city_id" id="city_id" value="<?= $customer['city_id'] ?>">
                    
                    <div class="form-section">
                        <div class="section-content">
                            <div class="form-row">
                                <div class="customer-form-group">
                                    <label for="name" class="form-label">
                                        <i class="fas fa-user"></i> Full Name<span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="name" name="name"
                                        placeholder="Enter customer's full name" 
                                        value="<?= htmlspecialchars($customer['name']) ?>" required>
                                    <div class="error-feedback" id="name-error"></div>
                                </div>

                                <div class="customer-form-group">
                                    <label for="email" class="form-label">
                                        <i class="fas fa-envelope"></i> Email Address
                                    </label>
                                    <input type="email" class="form-control" id="email" name="email"
                                        placeholder="customer@example.com (optional)" 
                                        value="<?= htmlspecialchars($customer['email']) ?>">
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
                                        placeholder="0771234567" 
                                        value="<?= htmlspecialchars($customer['phone']) ?>" required>
                                    <div class="error-feedback" id="phone-error"></div>
                                    <div class="phone-hint">Enter 10-digit Sri Lankan mobile number</div>
                                </div>

                                <div class="customer-form-group">
                                    <label for="phone_2" class="form-label">
                                        <i class="fas fa-phone-alt"></i> Phone Number 2
                                    </label>
                                    <input type="tel" class="form-control" id="phone_2" name="phone_2"
                                        placeholder="0771234567 (optional)" 
                                        value="<?= htmlspecialchars($customer['phone_2'] ?? '') ?>">
                                    <div class="error-feedback" id="phone_2-error"></div>
                                    <div class="phone-hint">Additional contact number (optional)</div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="customer-form-group">
                                    <label for="status" class="form-label">
                                        <i class="fas fa-toggle-on"></i> Status<span class="required">*</span>
                                    </label>
                                    <select class="form-select" id="status" name="status" required>
                                        <option value="Active" <?= $customer['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                                        <option value="Inactive" <?= $customer['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                                    </select>
                                    <div class="error-feedback" id="status-error"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-section address-section">
                        <div class="section-content">
                            <div class="form-row">
                                <div class="customer-form-group">
                                    <label for="address_line1" class="form-label">
                                        <i class="fas fa-home"></i> Address Line 1<span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="address_line1" name="address_line1"
                                        placeholder="House number, street name" 
                                        value="<?= htmlspecialchars($customer['address_line1']) ?>" required>
                                    <div class="error-feedback" id="address_line1-error"></div>
                                </div>

                                <div class="customer-form-group">
                                    <label for="address_line2" class="form-label">
                                        <i class="fas fa-building"></i> Address Line 2
                                    </label>
                                    <input type="text" class="form-control" id="address_line2" name="address_line2"
                                        placeholder="Apartment, suite, building (optional)"
                                        value="<?= htmlspecialchars($customer['address_line2'] ?? '') ?>">
                                    <div class="error-feedback" id="address_line2-error"></div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="customer-form-group autocomplete-container">
                                    <label for="city_input" class="form-label">
                                        <i class="fas fa-city"></i> City<span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="city_input" 
                                        value="<?= htmlspecialchars($customer['city_name']) ?>"
                                        placeholder="Start typing city name..." 
                                        autocomplete="off" required>
                                    <div class="autocomplete-dropdown" id="cityDropdown"></div>
                                    <div class="error-feedback" id="city_id-error"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="submit-container">
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-save"></i> Update Customer
                        </button>
                        <button type="button" class="btn btn-secondary ms-2" id="cancelBtn" onclick="window.location.href='customer_list.php'">
                            <i class="fas fa-times"></i> Back to Customers
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php'); ?>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php'); ?>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>

    <script>
        let originalFormData = {};
        let citySearchTimeout;
        let selectedCityId = <?= $customer['city_id'] ?>;
        let currentFocusIndex = -1;

        $(document).ready(function() {
            storeOriginalData();
            initializeForm();
            setupCityAutocomplete();
            
            $('#editCustomerForm').on('submit', function(e) {
                e.preventDefault();
                clearAllValidations();
                
                if (validateForm()) {
                    submitFormAjax();
                } else {
                    scrollToFirstError();
                }
            });
            
            setupRealTimeValidation();
        });

        // City Autocomplete Setup
        function setupCityAutocomplete() {
            const $cityInput = $('#city_input');
            const $dropdown = $('#cityDropdown');
            const $cityIdHidden = $('#city_id');

            $cityInput.on('input', function() {
                const searchTerm = $(this).val().trim();
                
                clearTimeout(citySearchTimeout);
                
                if (searchTerm.length < 2) {
                    $dropdown.removeClass('show').empty();
                    return;
                }
                
                $cityInput.addClass('loading');
                
                citySearchTimeout = setTimeout(function() {
                    searchCities(searchTerm);
                }, 300);
            });

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
            $.ajax({
                url: 'fetch_cities.php',
                type: 'GET',
                data: { term: searchTerm },
                dataType: 'json',
                success: function(response) {
                    $('#city_input').removeClass('loading');
                    displayCityResults(response);
                },
                error: function(xhr, status, error) {
                    $('#city_input').removeClass('loading');
                    console.error('City search error:', error);
                    
                    const $dropdown = $('#cityDropdown');
                    $dropdown.html('<div class="autocomplete-no-results">Error loading cities. Please try again.</div>');
                    $dropdown.addClass('show');
                }
            });
        }

        function displayCityResults(cities) {
            const $dropdown = $('#cityDropdown');
            $dropdown.empty();
            currentFocusIndex = -1;

            if (!cities || cities.length === 0) {
                $dropdown.html('<div class="autocomplete-no-results"><i class="fas fa-search"></i><br>No cities found</div>');
                positionDropdown();
                $dropdown.addClass('show');
                return;
            }

            cities.forEach(function(city) {
                const details = [];
                if (city.postal_code) details.push('Postal: ' + city.postal_code);
                
                const detailsHtml = details.length > 0 
                    ? '<div class="city-details">' + details.join(' • ') + '</div>' 
                    : '';

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
        }

        function positionDropdown() {
            const $input = $('#city_input');
            const $dropdown = $('#cityDropdown');
            const offset = $input.offset();
            const inputHeight = $input.outerHeight();
            const inputWidth = $input.outerWidth();

            $dropdown.css({
                'top': (offset.top + inputHeight) + 'px',
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
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }

        function storeOriginalData() {
            originalFormData = {
                name: $('#name').val(),
                email: $('#email').val(),
                phone: $('#phone').val(),
                phone_2: $('#phone_2').val(),
                status: $('#status').val(),
                address_line1: $('#address_line1').val(),
                address_line2: $('#address_line2').val(),
                city_id: $('#city_id').val(),
                city_name: $('#city_input').val()
            };
        }

        function submitFormAjax() {
            showLoading();
            
            const $submitBtn = $('#submitBtn');
            const originalText = $submitBtn.html();
            $submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Updating Customer...');
            
            const formData = new FormData($('#editCustomerForm')[0]);
            
            $.ajax({
                url: 'update_customer.php',
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
                        showSuccessNotification(response.message || 'Customer updated successfully!');
                        storeOriginalData();
                    } else {
                        if (response.errors) {
                            showFieldErrors(response.errors);
                        }
                        showErrorNotification(response.message || 'Failed to update customer.');
                    }
                },
                error: function(xhr, status, error) {
                    hideLoading();
                    $submitBtn.prop('disabled', false).html(originalText);
                    
                    let errorMessage = 'An error occurred while updating the customer.';
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
            
            // Format both phone fields
            $('#phone, #phone_2').on('input', function() {
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
            
            $('#email').on('blur', function() {
                const emailValue = $(this).val().trim();
                
                if (emailValue !== '') {
                    const validation = validateEmail(emailValue);
                    if (!validation.valid) {
                        showError('email', validation.message);
                    } else {
                        showSuccess('email');
                        
                        const suggestion = suggestEmail(emailValue);
                        if (suggestion && suggestion !== emailValue.toLowerCase()) {
                            $('#email-suggestions').html(`Did you mean <a href="#" onclick="$('#email').val('${suggestion}'); $('#email-suggestions').html(''); $('#email').focus(); return false;">${suggestion}</a>?`);
                        } else {
                            $('#email-suggestions').html('');
                        }
                    }
                } else {
                    clearValidation('email');
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
            
            // Validation for optional phone_2
            $('#phone_2').on('blur', function() {
                const phoneValue = $(this).val().trim();
                
                if (phoneValue !== '') {
                    const validation = validatePhone(phoneValue);
                    if (!validation.valid) {
                        showError('phone_2', validation.message);
                    } else {
                        showSuccess('phone_2');
                    }
                } else {
                    clearValidation('phone_2');
                }
            });
            
            // Validation for optional phone_2
            $('#phone_2').on('blur', function() {
                const phoneValue = $(this).val().trim();
                
                if (phoneValue !== '') {
                    const validation = validatePhone(phoneValue);
                    if (!validation.valid) {
                        showError('phone_2', validation.message);
                    } else {
                        showSuccess('phone_2');
                    }
                } else {
                    clearValidation('phone_2');
                }
            });
           $('#address_line1').on('blur', function() {
                const validation = validateAddressLine1($(this).val());
                if (!validation.valid) {
                    showError('address_line1', validation.message);
                } else {
                    showSuccess('address_line1');
                }
            });
            
            $('#address_line2').on('blur', function() {
                if ($(this).val().trim() !== '') {
                    const validation = validateAddressLine($(this).val(), 'Address Line 2');
                    if (!validation.valid) {
                        showError('address_line2', validation.message);
                    } else {
                        showSuccess('address_line2');
                    }
                } else {
                    clearValidation('address_line2');
                }
            });
            
            $('#city_id').on('change', function() {
                const validation = validateCity($(this).val());
                if (!validation.valid) {
                    showError('city_id', validation.message);
                } else {
                    showSuccess('city_id');
                }
            });
        }

         // Validation functions (same as add customer page)
        function validateName(name) {
            if (name.trim() === '') {
                return { valid: false, message: 'Customer name is required' };
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
                // If email is empty, it's valid since it's optional
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

        function validatePhone(phone) {
            if (phone.trim() === '') {
                return { valid: false, message: 'Phone number is required' };
            }
            if (phone.length > 20) {
                return { valid: false, message: 'Phone number is too long (maximum 20 characters)' };
            }
            const cleanPhone = phone.replace(/\s+/g, '');
            const digitsOnly = cleanPhone.replace(/[^0-9]/g, '');
            
            if (digitsOnly.length !== 10) {
                return { valid: false, message: 'Phone number must be exactly 10 digits' };
            }
            
            const localPattern = /^0[1-9][0-9]{8}$/;
            const internationalPattern = /^(\+94|94)[1-9][0-9]{8}$/;
            
            if (!localPattern.test(cleanPhone) && !internationalPattern.test(cleanPhone)) {
                return { valid: false, message: 'Please enter a valid Sri Lankan phone number (e.g., 0771234567)' };
            }
            return { valid: true, message: '' };
        }

        function validateAddressLine1(address) {
            if (address.trim() === '') {
                return { valid: false, message: 'Address Line 1 is required' };
            }
            if (address.trim().length < 3) {
                return { valid: false, message: 'Address Line 1 must be at least 3 characters long' };
            }
            if (address.length > 255) {
                return { valid: false, message: 'Address Line 1 is too long (maximum 255 characters)' };
            }
            return { valid: true, message: '' };
        }

        function validateCity(cityId) {
            if (cityId.trim() === '') {
                return { valid: false, message: 'City selection is required' };
            }
            return { valid: true, message: '' };
        }

        function validateAddressLine(address, fieldName, maxLength = 255) {
            if (address.length > maxLength) {
                return { valid: false, message: `${fieldName} is too long (maximum ${maxLength} characters)` };
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
                $field.addClass('is-invalid').removeClass('is-valid');
                $errorDiv.text(message).show();
            }
        }

        function showSuccess(fieldId) {
            const $field = $('#' + fieldId);
            const $errorDiv = $('#' + fieldId + '-error');
            
            if ($field.length && $errorDiv.length) {
                $field.addClass('is-valid').removeClass('is-invalid');
                $errorDiv.hide();
            }
        }

        function clearValidation(fieldId) {
            const $field = $('#' + fieldId);
            const $errorDiv = $('#' + fieldId + '-error');
            
            if ($field.length && $errorDiv.length) {
                $field.removeClass('is-valid is-invalid');
                $errorDiv.hide();
            }
        }

      function validateForm() {
    let isValid = true;
    
    // Get all field values
    const name = $('#name').val();
    const email = $('#email').val();
    const phone = $('#phone').val();
    const addressLine1 = $('#address_line1').val();
    const cityId = $('#city_id').val();
    
    // Validate required fields
    const validations = [
        { field: 'name', validator: validateName, value: name },
        { field: 'phone', validator: validatePhone, value: phone },
        { field: 'address_line1', validator: validateAddressLine1, value: addressLine1 },
        { field: 'city_id', validator: validateCity, value: cityId }
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
    
    // Validate optional email field (only if provided)
    if (email.trim() !== '') {
        const emailValidation = validateEmail(email);
        if (!emailValidation.valid) {
            showError('email', emailValidation.message);
            isValid = false;
        } else {
            showSuccess('email');
        }
    }

    // Optional phone_2 validation
const phone2 = $('#phone_2').val();
if (phone2.trim() !== '') {
    const phone2Validation = validatePhone(phone2);
    if (!phone2Validation.valid) {
        showError('phone_2', phone2Validation.message);
        isValid = false;
    } else {
        showSuccess('phone_2');
    }
}
    
    // Optional address line 2 validation
    const addressLine2 = $('#address_line2').val();
    if (addressLine2.trim() !== '') {
        const address2Validation = validateAddressLine(addressLine2, 'Address Line 2');
        if (!address2Validation.valid) {
            showError('address_line2', address2Validation.message);
            isValid = false;
        } else {
            showSuccess('address_line2');
        }
    }
    
    return isValid;

        }
    </script>
</body>
</html>