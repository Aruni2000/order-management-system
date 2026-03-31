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
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');

// Handle search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Base SQL for counting total records
$countSql = "SELECT COUNT(*) as total FROM categories";

// Main query - Updated to include parent category name and status
$sql = "SELECT c.id, c.name, c.created_at, c.status, p.name as parent_name 
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
    <title>Order Management Admin Portal - Category Management</title>
    
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/orders.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/customers.css" id="main-style-link" />
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
    </style>
</head>

<body>
    <!-- Page Loader -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/loader.php'); ?>

    <div class="pc-container">
        <div class="pc-content">
            
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title" style="display: flex; justify-content: space-between; align-items: center;">
                        <h5 class="mb-0 font-medium">Category Management</h5>
                        <a href="add_category.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Add New Category
                        </a>
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
                                                <small style="color: #6c757d;"><?php echo date('H:i:s', strtotime($row['created_at'])); ?></small>
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
                                                
                                                <button class="action-btn dispatch-btn" title="Edit Category" 
                                                        onclick="editCategory(<?php echo $row['id']; ?>)">
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

    <script>
        function clearFilters() {
            window.location.href = 'category_list.php';
        }

        function editCategory(categoryId) {
            window.location.href = 'edit_category.php?id=' + categoryId;
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
            
            document.getElementById('modal-category-created').textContent = categoryCreated;

            modal.style.display = 'block';
        }

        function closeCategoryModal() {
            document.getElementById('categoryDetailsModal').style.display = 'none';
        }

        // Status Confirmation Modal
        function openStatusConfirmationModal(button) {
            const categoryId = button.getAttribute('data-category-id');
            const categoryName = button.getAttribute('data-category-name');
            const currentStatus = button.getAttribute('data-current-status');
            
            const isActive = currentStatus.toLowerCase() === 'active';
            const actionText = isActive ? 'deactivate' : 'activate';
            const buttonText = isActive ? 'Yes, deactivate category!' : 'Yes, activate category!';
            
            document.getElementById('action-text').textContent = actionText;
            document.getElementById('confirm-category-name').textContent = categoryName;
            document.getElementById('confirm-button-text').textContent = buttonText;
            
            const confirmBtn = document.getElementById('confirmActionBtn');
            confirmBtn.onclick = function() {
                toggleCategoryStatus(categoryId, isActive ? 'inactive' : 'active');
            };
            
            document.getElementById('statusConfirmationModal').style.display = 'block';
        }

        function closeConfirmationModal() {
            document.getElementById('statusConfirmationModal').style.display = 'none';
        }

        function toggleCategoryStatus(categoryId, newStatus) {
            fetch('toggle_category_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    category_id: categoryId,
                    new_status: newStatus
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error updating category status: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while updating the category status.');
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            const viewButtons = document.querySelectorAll('.view-category-btn');
            viewButtons.forEach(button => {
                button.addEventListener('click', function() {
                    openCategoryModal(this);
                });
            });

            const toggleButtons = document.querySelectorAll('.toggle-status-btn');
            toggleButtons.forEach(button => {
                button.addEventListener('click', function() {
                    openStatusConfirmationModal(this);
                });
            });

            window.onclick = function(event) {
                if (event.target.classList.contains('modal')) {
                    closeCategoryModal();
                    closeConfirmationModal();
                }
            }
        });
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

    <!-- Status Confirmation Modal -->
    <div id="statusConfirmationModal" class="modal confirmation-modal">
        <div class="modal-content confirmation-modal-content">
            <div class="modal-header">
                <h4>Are you sure?</h4>
                <span class="close" onclick="closeConfirmationModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="confirmation-icon">
                    <i class="ti ti-alert-triangle"></i>
                </div>
                <div class="confirmation-text">
                    You are about to <span class="action-highlight" id="action-text"></span> category:
                </div>
                <div class="confirmation-text">
                    <span class="user-name-highlight" id="confirm-category-name"></span>
                </div>
                <div class="modal-buttons">
                    <button class="btn-confirm" id="confirmActionBtn">
                        <span id="confirm-button-text">Confirm</span>
                    </button>
                    <button class="btn-cancel" onclick="closeConfirmationModal()">Cancel</button>
                </div>
            </div>
        </div>
    </div>

</body>
</html>