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

// Function to generate CSRF token
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
$csrf_token = generateCSRFToken();

// Fetch only top-level categories for parent dropdown (used in add/edit modals)
$all_categories = [];
try {
    $res = $conn->query("SELECT id, name FROM categories WHERE parent_id IS NULL OR parent_id = 0 ORDER BY name ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $all_categories[] = $row;
        }
    }
} catch (Exception $e) {
    error_log("Error fetching categories: " . $e->getMessage());
}

// Handle search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Base SQL for counting total records
$countSql = "SELECT COUNT(*) as total FROM categories";

// Main query - Updated to include parent category name and status
$sql = "SELECT c.id, c.name, c.parent_id, c.created_at, c.status, p.name as parent_name 
        FROM categories c 
        LEFT JOIN categories p ON c.parent_id = p.id";

// Apply search condition
if (!empty($search)) {
    $searchTerm = $conn->real_escape_string($search);
    $searchCondition = " WHERE c.id LIKE '%$searchTerm%' OR c.name LIKE '%$searchTerm%' OR p.name LIKE '%$searchTerm%'";
    $countSql .= " c LEFT JOIN categories p ON c.parent_id = p.id " . $searchCondition;
    $sql .= $searchCondition;
}

// Add ordering
$sql .= " ORDER BY id DESC";

// Execute queries
$countResult = $conn->query($countSql);
$totalRows = 0;
if ($countResult && $countResult->num_rows > 0) {
    $totalRows = $countResult->fetch_assoc()['total'];
}
$result = $conn->query($sql);
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Category Management | <?= htmlspecialchars($_SESSION['company_name'] ?? '') ?></title>
    
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="../assets/css/orders.css" />
    <link rel="stylesheet" href="../assets/css/customers.css" />
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        .category-name {
            font-weight: 600;
            color: #2c3e50;
        }
        .status-active {
            background-color: #d1e7dd;
            color: #0f5132;
        }
        .status-inactive {
            background-color: #f8d7da;
            color: #842029;
        }

        /* Category Form Modals */
        .category-modal .modal-content {
            max-width: 520px;
            border-radius: 12px;
            border: none;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: visible;
        }
        .category-modal .modal-header {
            background: linear-gradient(135deg, #1565c0, #1976d2);
            color: white;
            padding: 18px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: none;
        }
        .category-modal .modal-header h4 {
            margin: 0;
            font-size: 17px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .category-modal .modal-header h4 i {
            font-size: 18px;
        }
        .category-modal .modal-header .close {
            color: rgba(255,255,255,0.8);
            font-size: 26px;
            line-height: 1;
            transition: color 0.2s;
            cursor: pointer;
        }
        .category-modal .modal-header .close:hover {
            color: #fff;
        }
        .category-modal .modal-body {
            padding: 28px 24px 12px;
        }
        .category-modal .modal-footer {
            padding: 12px 24px 20px;
            border-top: 1px solid #f0f0f0;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        .category-modal .form-label {
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .category-modal .form-label .required {
            color: #e53e3e;
        }
        .category-modal .form-control {
            height: 44px;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            padding: 8px 14px;
            font-size: 14px;
            transition: border-color 0.2s, box-shadow 0.2s;
            width: 100%;
            box-sizing: border-box;
        }
        .category-modal .form-control:focus {
            border-color: #1565c0;
            box-shadow: 0 0 0 3px rgba(21, 101, 192, 0.15);
            outline: none;
        }
        .category-modal .form-control.is-invalid {
            border-color: #e53e3e;
        }
        .category-modal .error-feedback {
            color: #e53e3e;
            font-size: 12px;
            margin-top: 4px;
            display: none;
        }
        .category-modal .modal-btn-primary {
            background: linear-gradient(135deg, #1565c0, #1976d2);
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .category-modal .modal-btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(21, 101, 192, 0.35);
        }
        .category-modal .modal-btn-primary:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }
        .category-modal .modal-btn-secondary {
            background: #f3f4f6;
            color: #374151;
            border: 1px solid #d1d5db;
            padding: 10px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
        }
        .category-modal .modal-btn-secondary:hover {
            background: #e5e7eb;
        }

        /* Form group spacing */
        .category-modal .form-group-modal {
            margin-bottom: 18px;
        }

        /* Select2 overrides */
        .category-modal .select2-container--default .select2-selection--single {
            height: 44px !important;
            border: 1px solid #d1d5db !important;
            border-radius: 8px !important;
            padding: 8px 12px !important;
            display: flex;
            align-items: center;
        }
        .category-modal .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: inherit !important;
            color: #374151 !important;
            padding-left: 0 !important;
        }
        .category-modal .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 42px !important;
            top: 1px !important;
            right: 10px !important;
        }
        /* Dropdown rendered at body level — high z-index to appear above modal */
        .select2-container--open .select2-dropdown {
            border: 1px solid #d1d5db !important;
            border-radius: 8px !important;
            box-shadow: 0 8px 30px rgba(0,0,0,0.12) !important;
            z-index: 99999 !important;
        }
        .select2-container--open {
            z-index: 99999 !important;
        }
        .select2-container--open .select2-dropdown--below {
            border-top: 1px solid #d1d5db !important;
        }
        .select2-search--dropdown .select2-search__field {
            height: 42px !important;
            padding: 8px 12px !important;
            border: none !important;
            border-bottom: 1px solid #d1d5db !important;
            border-radius: 8px 8px 0 0 !important;
            outline: none !important;
        }
        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background-color: #1565c0 !important;
        }

        /* Animations */
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
    </style>
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
                    <div class="page-header-title" style="display: flex; justify-content: space-between; align-items: center;">
                        <h5 class="mb-0 font-medium">Category Management</h5>
                        <button type="button" class="btn btn-primary" onclick="openAddCategoryModal()">
                            <i class="fas fa-plus"></i> Add New Category
                        </button>
                    </div>
                </div>
            </div>

            <!-- Main Content -->

            <div class="main-content-wrapper">
                
                <!-- Category Filter Section -->
                <div class="tracking-container">
                    <form class="tracking-form" method="GET" action="">
                        <div class="form-group" style="flex: 1;">
                            <label for="search">Search Categories</label>
                            <input type="text" id="search" name="search" 
                                   placeholder="Search by ID or Name" 
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        
                        <div class="form-group">
                            <div class="button-group">
                                <button type="submit" class="search-btn">
                                    <i class="fas fa-search"></i>
                                    Search
                                </button>
                                <button type="button" class="search-btn" onclick="clearFilters()" style="background: #6c757d;">
                                    <i class="fas fa-times"></i>
                                    Clear
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Category Count Display -->
                <div class="order-count-container">
                    <div class="order-count-number"><?php echo number_format($totalRows); ?></div>
                    <div class="order-count-dash">-</div>
                    <div class="order-count-subtitle">Total Categories</div>
                </div>

                <!-- Categories Table -->
                <div class="table-wrapper">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Main Category</th>
                                <th>Created Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['id']); ?></td>
                                        <td class="category-name"><?php echo htmlspecialchars($row['name']); ?></td>
                                        <td>
                                            <?php if ($row['parent_name']): ?>
                                                <span class="badge" style="background-color: #e2e8f0; color: #475569;">
                                                    <i class="fas fa-level-up-alt"></i> <?php echo htmlspecialchars($row['parent_name']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge" style="background-color: #dbeafe; color: #1e40af;">
                                                    <i class="fas fa-star"></i> Top Level
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="font-size: 13px;">
                                                <?php echo date('Y-m-d', strtotime($row['created_at'])); ?>
                                                <br>
                                                <small style="color: #6c757d;"><?php echo date('h:i:s A', strtotime($row['created_at'])); ?></small>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($row['status'] === 'active'): ?>
                                                <span class="status-badge pay-status-paid">Active</span>
                                            <?php else: ?>
                                                <span class="status-badge pay-status-unpaid">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="actions">
                                            <div class="action-buttons-group">
                                                <button type="button" class="action-btn view-btn view-category-btn"
                                                        data-category-id="<?= $row['id'] ?>"
                                                        data-category-name="<?= htmlspecialchars($row['name']) ?>"
                                                        data-parent-name="<?= htmlspecialchars($row['parent_name'] ?? 'Top Level') ?>"
                                                        data-category-status="<?= htmlspecialchars($row['status']) ?>"
                                                        data-category-created="<?= htmlspecialchars($row['created_at']) ?>"
                                                        title="View Category Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                
                                                <button class="action-btn dispatch-btn edit-category-btn" title="Edit Category"
                                                        data-category-id="<?= $row['id'] ?>"
                                                        data-category-name="<?= htmlspecialchars($row['name']) ?>"
                                                        data-parent-id="<?= $row['parent_id'] ?? '0' ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                
                                                <button type="button" class="action-btn <?= $row['status'] == 'active' ? 'deactivate-btn' : 'activate-btn' ?> toggle-status-btn"
                                                        data-category-id="<?= $row['id'] ?>"
                                                        data-current-status="<?= $row['status'] ?>"
                                                        data-category-name="<?= htmlspecialchars($row['name']) ?>"
                                                        title="<?= $row['status'] == 'active' ? 'Deactivate Category' : 'Activate Category' ?>">
                                                    <i class="fas <?= $row['status'] == 'active' ? 'fa-toggle-off' : 'fa-toggle-on' ?>"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center" style="padding: 40px; text-align: center; color: #666;">
                                        <i class="fas fa-tags" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                                        No categories found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php'); ?>

    <!-- Scripts -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php'); ?>

    <!-- jQuery (required for Select2) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
        function clearFilters() {
            window.location.href = 'category_list.php';
        }

        // Category Details Modal
        function openCategoryModal(button) {
            const modal = document.getElementById('categoryDetailsModal');
            
            const categoryId = button.getAttribute('data-category-id');
            const categoryName = button.getAttribute('data-category-name');
            const parentName = button.getAttribute('data-parent-name');
            const categoryStatus = button.getAttribute('data-category-status');
            const categoryCreated = button.getAttribute('data-category-created');

            document.getElementById('modal-category-id').textContent = categoryId;
            document.getElementById('modal-category-name').textContent = categoryName;
            document.getElementById('modal-parent-name').textContent = parentName;
            
            const statusElement = document.getElementById('modal-category-status');
            statusElement.textContent = categoryStatus.charAt(0).toUpperCase() + categoryStatus.slice(1);
            statusElement.className = 'badge ' + (categoryStatus === 'active' ? 'status-active' : 'status-inactive');
            
            document.getElementById('modal-category-created').textContent = formatDateTime(categoryCreated);

            modal.style.display = 'block';
            document.body.style.overflow = 'clip';
        }

        function formatDateTime(dateString) {
            if (!dateString) return '-';
            const date = new Date(dateString);
            if (isNaN(date.getTime())) return dateString;
            const yyyy = date.getFullYear();
            const mm = String(date.getMonth() + 1).padStart(2, '0');
            const dd = String(date.getDate()).padStart(2, '0');
            let hours = date.getHours();
            const minutes = String(date.getMinutes()).padStart(2, '0');
            const seconds = String(date.getSeconds()).padStart(2, '0');
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12;
            hours = hours ? hours : 12;
            const hh = String(hours).padStart(2, '0');
            return `${yyyy}-${mm}-${dd} ${hh}:${minutes}:${seconds} ${ampm}`;
        }

        function closeCategoryModal() {
            document.getElementById('categoryDetailsModal').style.display = 'none';
            document.body.style.overflow = '';
        }

        function toggleCategoryStatus(button) {
            const categoryId = button.getAttribute('data-category-id');
            const categoryName = button.getAttribute('data-category-name');
            const currentStatus = button.getAttribute('data-current-status');
            
            const isActive = currentStatus.toLowerCase() === 'active';
            const newStatus = isActive ? 'inactive' : 'active';
            
            Swal.fire({
                title: 'Are you sure?',
                text: `You are about to ${isActive ? 'Deactivate' : 'Activate'} Category: ${categoryName}`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: isActive ? '#dc3545' : '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: isActive ? 'Yes, deactivate it!' : 'Yes, activate it!',
                cancelButtonText: 'Cancel',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch('toggle_category_status.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            category_id: categoryId,
                            new_status: newStatus
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                title: 'Updated!',
                                text: 'Category status updated successfully!',
                                icon: 'success',
                                timer: 1500,
                                showConfirmButton: false
                            }).then(() => location.reload());
                        } else {
                            toastManager.error(data.message || 'Failed to update category status');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        toastManager.error('An error occurred while updating the category status.');
                    });
                }
            });
        }

        // ========== ADD CATEGORY MODAL ==========
        function openAddCategoryModal() {
            const modal = document.getElementById('addCategoryModal');
            modal.style.display = 'block';
            document.body.style.overflow = 'clip';
            
            // Reset form fields
            $('#addCategoryForm')[0].reset();
            $('#addCategoryForm #name').removeClass('is-invalid');
            $('#addCategoryForm .error-feedback').hide();
            
            // Reset and initialize Select2 (dropdown renders at body level for clean event handling)
            const $select = $('#addCategoryModal .parent-select');
            if ($select.data('select2')) {
                $select.val('').trigger('change');
            } else {
                $select.select2({
                    placeholder: 'Search Parent Category...',
                    allowClear: true,
                    width: '100%'
                }).on('select2:open', function() {
                    const searchField = document.querySelector('.select2-search__field');
                    if (searchField) {
                        searchField.placeholder = 'Search Parent Category...';
                        setTimeout(() => searchField.focus(), 100);
                    }
                });
            }
        }

        function closeAddCategoryModal() {
            document.getElementById('addCategoryModal').style.display = 'none';
            document.body.style.overflow = '';
        }

        // ========== EDIT CATEGORY MODAL ==========
        // Store original values for change detection
        let editCategoryOriginal = {};

        function openEditCategoryModal(categoryId, categoryName, parentId) {
            const modal = document.getElementById('editCategoryModal');
            modal.style.display = 'block';
            document.body.style.overflow = 'clip';
            
            // Populate form fields
            $('#editCategoryId').val(categoryId);
            $('#editName').val(categoryName).removeClass('is-invalid');
            $('#editCategoryForm .error-feedback').hide();
            
            // Exclude current category from parent options (prevent self-reference)
            $('.edit-parent-option').each(function() {
                const optionCatId = $(this).data('cat-id');
                if (String(optionCatId) === String(categoryId)) {
                    $(this).hide();
                } else {
                    $(this).show();
                }
            });
            
            // Initialize or update Select2 (dropdown renders at body level for clean event handling)
            const $select = $('#editCategoryModal .parent-select-edit');
            if ($select.data('select2')) {
                $select.val(null).trigger('change');
                $select.val(parentId).trigger('change');
            } else {
                $select.select2({
                    placeholder: 'Search Parent Category...',
                    allowClear: true,
                    width: '100%'
                }).on('select2:open', function() {
                    const searchField = document.querySelector('.select2-search__field');
                    if (searchField) {
                        searchField.placeholder = 'Search Parent Category...';
                        setTimeout(() => searchField.focus(), 100);
                    }
                });
                $select.val(parentId).trigger('change');
            }
            
            // Store original values for change detection
            editCategoryOriginal = {
                name: categoryName,
                parent_id: parentId
            };
        }

        function closeEditCategoryModal() {
            document.getElementById('editCategoryModal').style.display = 'none';
            document.body.style.overflow = '';
            // Restore all parent options for next time
            $('.edit-parent-option').show();
        }

        // ========== FORM SUBMISSIONS ==========
        $(document).ready(function() {
            // Add Category Form
            $('#addCategoryForm').on('submit', function(e) {
                e.preventDefault();
                
                const name = $('#addCategoryForm #name').val().trim();
                if (name === '') {
                    $('#addCategoryForm #name').addClass('is-invalid');
                    $('#addCategoryForm #name-error').text('Category name is required').show();
                    return;
                }
                
                const $btn = $('#addCategoryForm .modal-btn-primary');
                $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Adding...');
                
                $.ajax({
                    url: 'save_category.php',
                    type: 'POST',
                    data: $(this).serialize(),
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            closeAddCategoryModal();
                            toastManager.success(response.message);
                            setTimeout(function() {
                                location.reload();
                            }, 1200);
                        } else {
                            toastManager.error(response.message);
                        }
                    },
                    error: function() {
                        toastManager.error('An error occurred. Please try again.');
                    },
                    complete: function() {
                        $btn.prop('disabled', false).html('<i class="fas fa-plus"></i> Add Category');
                    }
                });
            });

            // Edit Category Form
            $('#editCategoryForm').on('submit', function(e) {
                e.preventDefault();
                
                const name = $('#editName').val().trim();
                if (name === '') {
                    $('#editName').addClass('is-invalid');
                    $('#editCategoryForm #editName-error').text('Category name is required').show();
                    return;
                }
                
                // Check for changes first
                const currentParentId = $('#editCategoryModal .parent-select-edit').val() || '0';
                if (name === editCategoryOriginal.name && currentParentId == editCategoryOriginal.parent_id) {
                    toastManager.warning('No changes were made to the category.');
                    return;
                }
                
                const $btn = $('#editCategoryForm .modal-btn-primary');
                $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Updating...');
                
                $.ajax({
                    url: 'update_category.php',
                    type: 'POST',
                    data: $(this).serialize(),
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            closeEditCategoryModal();
                            toastManager.success(response.message);
                            setTimeout(function() {
                                location.reload();
                            }, 1200);
                        } else {
                            toastManager.error(response.message);
                        }
                    },
                    error: function() {
                        toastManager.error('An error occurred. Please try again.');
                    },
                    complete: function() {
                        $btn.prop('disabled', false).html('<i class="fas fa-save"></i> Update Category');
                    }
                });
            });

            // Bind edit buttons
            $(document).on('click', '.edit-category-btn', function() {
                const id = $(this).data('category-id');
                const name = $(this).data('category-name');
                const parentId = $(this).data('parent-id') || '0';
                openEditCategoryModal(id, name, parentId);
            });

            // View buttons
            const viewButtons = document.querySelectorAll('.view-category-btn');
            viewButtons.forEach(function(button) {
                button.addEventListener('click', function() {
                    openCategoryModal(this);
                });
            });

            // Toggle status buttons
            const toggleButtons = document.querySelectorAll('.toggle-status-btn');
            toggleButtons.forEach(function(button) {
                button.addEventListener('click', function() {
                    toggleCategoryStatus(this);
                });
            });

            // Close modals ONLY when clicking directly on the backdrop (not on modal content or Select2)
            document.getElementById('addCategoryModal').addEventListener('click', function(e) {
                if (e.target === this) {
                    closeAddCategoryModal();
                }
            });
            document.getElementById('editCategoryModal').addEventListener('click', function(e) {
                if (e.target === this) {
                    closeEditCategoryModal();
                }
            });
            // Stop propagation on modal content so clicks on Select2/form don't close the modal
            document.querySelectorAll('.modal-content').forEach(function(el) {
                el.addEventListener('click', function(e) {
                    e.stopPropagation();
                });
            });
            // Existing modals close on backdrop click
            window.onclick = function(event) {
                if (event.target.classList.contains('modal') && 
                    event.target.id !== 'addCategoryModal' && event.target.id !== 'editCategoryModal') {
                    closeCategoryModal();
                }
            };
        });

        // ========== TOAST NOTIFICATION (using system toastManager) ==========
        // toastManager is loaded from assets/js/toast.js via scripts.php
        // Usage: toastManager.success(msg), toastManager.error(msg), toastManager.warning(msg)
    </script>

    <!-- Details Modal -->
    <div id="categoryDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h4>Category Details</h4>
                <span class="close" onclick="closeCategoryModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="customer-detail-row">
                    <span class="detail-label">Category ID:</span>
                    <span class="detail-value" id="modal-category-id"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Category Name:</span>
                    <span class="detail-value" id="modal-category-name"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Parent Category:</span>
                    <span class="detail-value" id="modal-parent-name"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Status:</span>
                    <span class="detail-value"><span id="modal-category-status"></span></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Created At:</span>
                    <span class="detail-value" id="modal-category-created"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- ========== ADD CATEGORY MODAL ========== -->
    <div id="addCategoryModal" class="modal category-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h4><i class="fas fa-plus-circle"></i> Add New Category</h4>
                <span class="close" onclick="closeAddCategoryModal()">&times;</span>
            </div>
            <form method="POST" id="addCategoryForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <div class="modal-body">
                    <div class="form-group-modal">
                        <label class="form-label">
                            <i class="fas fa-tag"></i> Category Name<span class="required">*</span>
                        </label>
                        <input type="text" class="form-control" id="name" name="name"
                               placeholder="Enter category name" required maxlength="255">
                        <div class="error-feedback" id="name-error"></div>
                    </div>
                    <div class="form-group-modal">
                        <label class="form-label">
                            <i class="fas fa-level-up-alt"></i> Parent Category
                        </label>
                        <select class="form-select parent-select" name="parent_id" data-placeholder="Search Parent Category...">
                            <option value=""></option>
                            <option value="0">None (Top Level)</option>
                            <?php foreach ($all_categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="error-feedback" id="parent_id-error"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="modal-btn-secondary" onclick="closeAddCategoryModal()">Cancel</button>
                    <button type="submit" class="modal-btn-primary">
                        <i class="fas fa-plus"></i> Add Category
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========== EDIT CATEGORY MODAL ========== -->
    <div id="editCategoryModal" class="modal category-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h4><i class="fas fa-edit"></i> Edit Category</h4>
                <span class="close" onclick="closeEditCategoryModal()">&times;</span>
            </div>
            <form method="POST" id="editCategoryForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="category_id" id="editCategoryId" value="">
                <div class="modal-body">
                    <div class="form-group-modal">
                        <label class="form-label">
                            <i class="fas fa-tag"></i> Category Name<span class="required">*</span>
                        </label>
                        <input type="text" class="form-control" id="editName" name="name"
                               placeholder="Enter category name" required maxlength="255">
                        <div class="error-feedback" id="editName-error"></div>
                    </div>
                    <div class="form-group-modal">
                        <label class="form-label">
                            <i class="fas fa-level-up-alt"></i> Parent Category
                        </label>
                        <!-- Exclude current category from parent options to prevent self-reference -->
                        <select class="form-select parent-select-edit" name="parent_id" data-placeholder="Search Parent Category...">
                            <option value=""></option>
                            <option value="0">None (Top Level)</option>
                            <?php foreach ($all_categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" class="edit-parent-option" data-cat-id="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="error-feedback" id="parent_id-error"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="modal-btn-secondary" onclick="closeEditCategoryModal()">Cancel</button>
                    <button type="submit" class="modal-btn-primary">
                        <i class="fas fa-save"></i> Update Category
                    </button>
                </div>
            </form>
        </div>
    </div>

</body>
</html>