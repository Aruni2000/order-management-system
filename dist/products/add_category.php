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

// Include the database connection file
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Fetch all categories for parent selection
$categories = [];
try {
    $res = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $categories[] = $row;
        }
    }
} catch (Exception $e) {
    error_log("Error fetching categories: " . $e->getMessage());
}

// Function to generate CSRF token
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Order Management Admin Portal - Add Category</title>

    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>
    
    <!-- [Template CSS Files] -->
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/products.css" id="main-style-link" />
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
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/loader.php'); ?>


    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <div class="spinner"></div>
            <h5>Processing...</h5>
            <p>Please wait while we add the product</p>
        </div>
    </div>
    
    <div class="pc-container">
        <div class="pc-content">
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">Add New Category</h5>
                    </div>
                </div>
            </div>

            <div class="main-container">
                <form method="POST" id="addCategoryForm" class="product-form" novalidate>
                    <!-- CSRF Token -->
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <div class="form-section">
                        <div class="section-content">
                            <div class="form-row">
                                <div class="product-form-group">
                                    <label for="name" class="form-label">
                                        <i class="fas fa-tag"></i> Category Name<span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="name" name="name"
                                        placeholder="Enter category name" required maxlength="255">
                                    <div class="error-feedback" id="name-error"></div>
                                </div>

                                <div class="product-form-group">
                                    <label for="parent_id" class="form-label">
                                        <i class="fas fa-level-up-alt"></i> Parent Category
                                    </label>
                                    <select class="form-select" id="parent_id" name="parent_id" data-placeholder="Search Parent Category...">
                                        <option value=""></option>
                                        <option value="0">None (Top Level)</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="error-feedback" id="parent_id-error"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="submit-container">
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-plus"></i> Add Category
                        </button>
                        <button type="button" class="btn btn-secondary ms-2" onclick="window.location.href='category_list.php'">
                            <i class="fas fa-arrow-left"></i> Back to List
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php'); ?>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php'); ?>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
        $(document).ready(function() {
            // Initialize Select2 for Parent Category with placeholder refinement
            $('#parent_id').select2({
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

            $('#addCategoryForm').on('submit', function(e) {
                e.preventDefault();
                
                const name = $('#name').val().trim();
                if (name === '') {
                    showError('name', 'Category name is required');
                    return;
                }
                
                const $submitBtn = $('#submitBtn');
                $submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Processing...');
                
                $.ajax({
                    url: 'save_category.php',
                    type: 'POST',
                    data: $(this).serialize(),
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            showNotification(response.message, 'success');
                            // Auto-refresh after a short delay so user can add another and see latest parent categories
                            setTimeout(() => {
                                location.reload();
                            }, 1500);
                        } else {
                            showNotification(response.message, 'danger');
                        }
                    },
                    error: function() {
                        showNotification('An error occurred. Please try again.', 'danger');
                    },
                    complete: function() {
                        $submitBtn.prop('disabled', false).html('<i class="fas fa-plus"></i> Add Category');
                    }
                });
            });
        });

        function showError(fieldId, message) {
            $('#' + fieldId).addClass('is-invalid');
            $('#' + fieldId + '-error').text(message).show();
        }

        function showNotification(message, type) {
            const id = 'notif_' + Date.now();
            const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
            const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
            
            const html = `
                <div class="alert ${alertClass} alert-dismissible fade show ajax-notification" id="${id}" role="alert">
                    <div class="d-flex align-items-center">
                        <i class="fas ${icon} me-2"></i>
                        <div>${message}</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            $('body').append(html);
            setTimeout(() => $('#' + id).fadeOut(() => $('#' + id).remove()), 5000);
        }
    </script>
</body>
</html>