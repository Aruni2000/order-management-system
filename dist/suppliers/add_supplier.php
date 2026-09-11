<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) ob_end_clean();
    header("Location: /OMS/dist/pages/login.php");
    exit();
}
// Purchasing Management (Suppliers) is accessible only to Main Admin Tenant (Admin & Store roles)
if ((int)($_SESSION['is_main_admin'] ?? 0) !== 1 || !in_array((int)($_SESSION['role_id'] ?? 0), [1, 3], true)) {
    if (ob_get_level()) ob_end_clean();
    header("Location: /OMS/dist/pages/access_denied.php");
    exit();
}

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
?>
<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">
<head>
    <title>Add New Supplier | <?= htmlspecialchars($_SESSION['company_name'] ?? 'OMS') ?></title>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>
    <link rel="stylesheet" href="../assets/css/customers.css" />
    <style>
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
    </style>
</head>
<body>
    <?php
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/loader.php');
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');
    ?>

    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <div class="spinner"></div>
            <h5>Processing...</h5>
            <p>Please wait while we add the supplier</p>
        </div>
    </div>

    <div class="pc-container">
        <div class="pc-content">
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">Add New Supplier</h5>
                    </div>
                </div>
            </div>

            <div class="main-container">
                <form method="POST" id="addSupplierForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <div class="form-section">
                        <div class="section-content">
                            <div class="form-row">
                                <div class="customer-form-group">
                                    <label for="name" class="form-label">
                                        <i class="fas fa-truck"></i> Supplier Name <span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="name" name="name" placeholder="Enter supplier name" required maxlength="255">
                                    <div class="error-feedback" id="name-error"></div>
                                </div>

                                <div class="customer-form-group">
                                    <label for="contact_person" class="form-label">
                                        <i class="fas fa-user"></i> Contact Person
                                    </label>
                                    <input type="text" class="form-control" id="contact_person" name="contact_person" placeholder="Enter contact person name" maxlength="255">
                                    <div class="error-feedback" id="contact_person-error"></div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="customer-form-group">
                                    <label for="phone" class="form-label">
                                        <i class="fas fa-phone"></i> Phone
                                    </label>
                                    <input type="text" class="form-control" id="phone" name="phone" placeholder="Enter phone number" maxlength="20">
                                    <div class="error-feedback" id="phone-error"></div>
                                </div>

                                <div class="customer-form-group">
                                    <label for="email" class="form-label">
                                        <i class="fas fa-envelope"></i> Email
                                    </label>
                                    <input type="email" class="form-control" id="email" name="email" placeholder="Enter email address" maxlength="255">
                                    <div class="error-feedback" id="email-error"></div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="customer-form-group full-width">
                                    <label for="address" class="form-label">
                                        <i class="fas fa-home"></i> Address
                                    </label>
                                    <textarea class="form-control" id="address" name="address" rows="3" placeholder="Enter supplier address"></textarea>
                                    <div class="error-feedback" id="address-error"></div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="customer-form-group">
                                    <label for="status" class="form-label">
                                        <i class="fas fa-toggle-on"></i> Status <span class="required">*</span>
                                    </label>
                                    <select class="form-select" id="status" name="status" required>
                                        <option value="active" selected>Active</option>
                                        <option value="inactive">Inactive</option>
                                    </select>
                                    <div class="error-feedback" id="status-error"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="submit-container">
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-plus"></i> Add Supplier
                        </button>
                        <button type="button" class="btn btn-secondary" id="resetBtn">
                            <i class="fas fa-undo"></i> Reset
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
    $(document).ready(function() {
        $('#addSupplierForm').on('submit', function(e) {
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

    function setupRealTimeValidation() {
        $('#name').on('blur', function() {
            if ($(this).val().trim() === '') showError('name', 'Supplier name is required');
            else showSuccess('name');
        });
        $('#email').on('blur', function() {
            const val = $(this).val().trim();
            if (val !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) showError('email', 'Invalid email format');
            else if (val !== '') showSuccess('email');
        });
    }

    function validateForm() {
        let valid = true;
        if ($('#name').val().trim() === '') { showError('name', 'Supplier name is required'); valid = false; }
        return valid;
    }

    function submitFormAjax() {
        showLoading();
        const $btn = $('#submitBtn');
        const orig = $btn.html();
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');

        $.ajax({
            url: 'save_supplier.php',
            type: 'POST',
            data: new FormData($('#addSupplierForm')[0]),
            processData: false, contentType: false, dataType: 'json',
            timeout: 30000,
            success: function(resp) {
                hideLoading();
                $btn.prop('disabled', false).html(orig);
                if (resp.success) {
                    Swal.fire({ icon: 'success', title: 'Success!', text: resp.message, timer: 2000, showConfirmButton: false })
                        .then(() => { window.location.href = 'supplier_list.php'; });
                } else {
                    if (resp.errors) $.each(resp.errors, function(f, m) { showError(f, m); });
                    Swal.fire({ icon: 'error', title: 'Error', text: resp.message || 'Failed to add supplier.' });
                }
            },
            error: function() {
                hideLoading();
                $btn.prop('disabled', false).html(orig);
                Swal.fire({ icon: 'error', title: 'Error', text: 'An error occurred. Please try again.' });
            }
        });
    }

    function showLoading() {
        $('#loadingOverlay').css('display', 'flex');
        $('body').css('overflow', 'clip');
    }

    function hideLoading() {
        $('#loadingOverlay').hide();
        $('body').css('overflow', 'auto');
    }

    function scrollToFirstError() {
        const $firstError = $('.is-invalid').first();
        if ($firstError.length) {
            $('html, body').animate({ scrollTop: $firstError.offset().top - 100 }, 500);
            $firstError.focus();
        }
    }

    function showError(f, m) { $('#' + f).addClass('is-invalid').removeClass('is-valid'); $('#' + f + '-error').text(m).show(); }
    function showSuccess(f) { $('#' + f).addClass('is-valid').removeClass('is-invalid'); $('#' + f + '-error').hide(); }
    function clearAllValidations() { $('.form-control, .form-select').removeClass('is-invalid is-valid'); $('.error-feedback').hide().text(''); }
    function resetForm() { $('#addSupplierForm')[0].reset(); clearAllValidations(); $('#name').focus(); }
    </script>
</body>
</html>
<?php $conn->close(); ?>