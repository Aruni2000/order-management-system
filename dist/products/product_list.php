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


// Multi-tenant permissions
$is_main_admin = isset($_SESSION['is_main_admin']) && $_SESSION['is_main_admin'] == 1;
$is_user = isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] == 2;
$session_tenant_id = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
$tenant_filter = isset($_GET['tenant_filter']) ? intval($_GET['tenant_filter']) : 0;

// Fetch tenants for main admin filter
$tenants = [];
if ($is_main_admin && $_SESSION['role_id'] == 1) {
    $tRes = $conn->query("SELECT tenant_id, company_name FROM tenants WHERE status = 'active' ORDER BY is_main_admin DESC, company_name ASC");
    if ($tRes) {
        while ($row = $tRes->fetch_assoc()) {
            $tenants[] = $row;
        }
    }
}

// Handle search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$product_id_filter = isset($_GET['product_id_filter']) ? trim($_GET['product_id_filter']) : '';
$product_name_filter = isset($_GET['product_name_filter']) ? trim($_GET['product_name_filter']) : '';
$product_code_filter = isset($_GET['product_code_filter']) ? trim($_GET['product_code_filter']) : '';
$status_filter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$category_filter = isset($_GET['category_filter']) ? trim($_GET['category_filter']) : '';
$low_stock_filter = isset($_GET['low_stock_filter']) ? trim($_GET['low_stock_filter']) : '';

// Fetch categories for filter (scoped to effective tenant)
$categories = [];
if ($is_main_admin && $_SESSION['role_id'] == 1) {
    if ($tenant_filter > 0) {
        $catRes = $conn->query("SELECT id, name, tenant_id FROM categories WHERE tenant_id = $tenant_filter ORDER BY name ASC");
    } else {
        $catRes = $conn->query("SELECT id, name, tenant_id FROM categories ORDER BY name ASC");
    }
} else {
    $catRes = $conn->query("SELECT id, name, tenant_id FROM categories WHERE tenant_id = $session_tenant_id ORDER BY name ASC");
}
if ($catRes) {
    while ($crow = $catRes->fetch_assoc()) {
        $categories[] = $crow;
    }
}

// Pagination settings
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Base SQL for counting total records
$countSql = "SELECT COUNT(*) as total FROM products p LEFT JOIN categories c ON p.category_id = c.id LEFT JOIN tenants t ON p.tenant_id = t.tenant_id";

// Main query - Updated to include product_code, category name, and tenant Tenant Name
$sql = "SELECT p.*, c.name as category_name, t.company_name
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN tenants t ON p.tenant_id = t.tenant_id";

// Build search conditions
$searchConditions = [];

// Enforce tenant isolation for sub-tenants vs main admin filter
if ($is_main_admin && $_SESSION['role_id'] == 1) {
    if ($tenant_filter > 0) {
        $searchConditions[] = "p.tenant_id = $tenant_filter";
    }
} else {
    $searchConditions[] = "p.tenant_id = $session_tenant_id";
}

// General search condition - Updated to include product_code, id, and tenant Tenant Name
if (!empty($search)) {
    $searchTerm = $conn->real_escape_string($search);
    $searchConditions[] = "(
                        p.id LIKE '%$searchTerm%' OR
                        p.name LIKE '%$searchTerm%' OR 
                        p.product_code LIKE '%$searchTerm%' OR 
                        p.description LIKE '%$searchTerm%' OR
                        t.company_name LIKE '%$searchTerm%' OR
                        c.name LIKE '%$searchTerm%')";
}

// Specific Product ID filter
if (!empty($product_id_filter)) {
    $productIdTerm = $conn->real_escape_string($product_id_filter);
    $searchConditions[] = "p.id = '$productIdTerm'";
}

// Specific Product Name filter
if (!empty($product_name_filter)) {
    $productNameTerm = $conn->real_escape_string($product_name_filter);
    $searchConditions[] = "p.name LIKE '%$productNameTerm%'";
}

// Specific Product Code filter
if (!empty($product_code_filter)) {
    $productCodeTerm = $conn->real_escape_string($product_code_filter);
    $searchConditions[] = "p.product_code LIKE '%$productCodeTerm%'";
}

// Status filter
if (!empty($status_filter)) {
    $statusTerm = $conn->real_escape_string($status_filter);
    $searchConditions[] = "p.status = '$statusTerm'";
}

// Date range filter
if (!empty($date_from)) {
    $dateFromTerm = $conn->real_escape_string($date_from);
    $searchConditions[] = "DATE(p.created_at) >= '$dateFromTerm'";
}

if (!empty($date_to)) {
    $dateToTerm = $conn->real_escape_string($date_to);
    $searchConditions[] = "DATE(p.created_at) <= '$dateToTerm'";
}

// Category filter
if (!empty($category_filter)) {
    $catTerm = $conn->real_escape_string($category_filter);
    $searchConditions[] = "p.category_id = '$catTerm'";
}

// Low stock filter
if ($low_stock_filter === '1' && isset($_SESSION['allow_inventory']) && $_SESSION['allow_inventory'] == 1) {
    $searchConditions[] = "p.stock_quantity <= p.low_stock_threshold";
}

// Apply all search conditions
if (!empty($searchConditions)) {
    $finalSearchCondition = " WHERE " . implode(' AND ', $searchConditions);
    $countSql .= $finalSearchCondition;
    $sql .= $finalSearchCondition;
}

// Add ordering and pagination
$sql .= " ORDER BY p.id DESC LIMIT $limit OFFSET $offset";

// Execute queries
$countResult = $conn->query($countSql);
$totalRows = 0;
if ($countResult && $countResult->num_rows > 0) {
    $totalRows = $countResult->fetch_assoc()['total'];
}
$totalPages = ceil($totalRows / $limit);
$result = $conn->query($sql);
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Product Management | <?= htmlspecialchars($_SESSION['company_name'] ?? '') ?></title>
    
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="../assets/css/orders.css" />
    <link rel="stylesheet" href="../assets/css/customers.css" />

    <style>
        .product-category {
            font-family: monospace; 
            font-size: 13px; 
            color: #495057; 
            background: #f8f9fa; 
            display: inline-block;
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
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">Product Management</h5>
                    </div>
                </div>
            </div>

            <div class="main-content-wrapper">
                
                <!-- Product Filter Section -->
                <div class="tracking-container">
                    <form class="tracking-form" method="GET" action="">
                        <div class="form-group">
                            <label for="product_id_filter">Product ID</label>
                            <input type="number" id="product_id_filter" name="product_id_filter" 
                                   placeholder="Enter product ID" min="1"
                                   value="<?php echo htmlspecialchars($product_id_filter); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="product_name_filter">Product Name</label>
                            <input type="text" id="product_name_filter" name="product_name_filter" 
                                   placeholder="Enter product name" 
                                   value="<?php echo htmlspecialchars($product_name_filter); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="product_code_filter">Product Code</label>
                            <input type="text" id="product_code_filter" name="product_code_filter" 
                                   placeholder="Enter product code" 
                                   value="<?php echo htmlspecialchars($product_code_filter); ?>">
                        </div>
                        
                        <!-- <div class="form-group">
                            <label for="description_filter">Description</label>
                            <input type="text" id="description_filter" name="description_filter" 
                                   placeholder="Enter description" 
                                   value="<?php echo htmlspecialchars($description_filter); ?>">
                        </div> -->
                        
                        <!-- <div class="form-group">
                            <label for="price_from">Price From (LKR)</label>
                            <input type="number" id="price_from" name="price_from" 
                                   placeholder="Min price" step="0.01" min="0"
                                   value="<?php echo htmlspecialchars($price_from); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="price_to">Price To (LKR)</label>
                            <input type="number" id="price_to" name="price_to" 
                                   placeholder="Max price" step="0.01" min="0"
                                   value="<?php echo htmlspecialchars($price_to); ?>">
                        </div> -->
                        
                        <div class="form-group">
                            <label for="status_filter">Status</label>
                            <select id="status_filter" name="status_filter">
                                <option value="">All Status</option>
                                <option value="active" <?php echo ($status_filter == 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo ($status_filter == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="category_filter">Category</label>
                            <select id="category_filter" name="category_filter">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>" <?php echo ($category_filter == $cat['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($is_main_admin && $_SESSION['role_id'] == 1): ?>
                        <div class="form-group">
                            <label for="tenant_filter">Tenant</label>
                            <select id="tenant_filter" name="tenant_filter">
                                <option value="">All Companies</option>
                                <?php foreach ($tenants as $t): ?>
                                    <option value="<?php echo $t['tenant_id']; ?>" <?php echo ($tenant_filter == $t['tenant_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($t['company_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <?php if (isset($_SESSION['allow_inventory']) && $_SESSION['allow_inventory'] == 1): ?>
                        <div class="form-group">
                            <label for="low_stock_filter">Stock Status</label>
                            <select id="low_stock_filter" name="low_stock_filter">
                                <option value="">All Products</option>
                                <option value="1" <?php echo ($low_stock_filter === '1') ? 'selected' : ''; ?>>Low Stock Only</option>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label for="date_from">Date From</label>
                            <input type="date" id="date_from" name="date_from" 
                                   value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="date_to">Date To</label>
                            <input type="date" id="date_to" name="date_to" 
                                   value="<?php echo htmlspecialchars($date_to); ?>">
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

                <!-- Product Count Display -->
                <div class="order-count-container">
                    <div class="order-count-number"><?php echo number_format($totalRows); ?></div>
                    <div class="order-count-dash">-</div>
                    <div class="order-count-subtitle">Total Products</div>
                </div>

                <!-- Products Table -->
                <div class="table-wrapper">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Product Name</th>
                                <th>Product Code</th>
                                <th>Category</th>
                                <?php if (isset($_SESSION['allow_inventory']) && $_SESSION['allow_inventory'] == 1): ?>
                                <th>Stock</th>
                                <?php endif; ?>
                                <th>Status</th>
                                <?php if ($is_main_admin && $_SESSION['role_id'] == 1): ?>
                                <th>Tenant</th>
                                <?php endif; ?>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="productsTableBody">
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <!-- Product ID -->
                                        <td class="order-id"><?php echo htmlspecialchars($row['id']); ?></td>

                                        <!-- Product Name -->
                                        <td class="product-name">
                                            <div class="product-info">
                                                <h6 style="margin: 0; font-size: 14px; font-weight: 600;"><?php echo htmlspecialchars($row['name']); ?></h6>
                                            </div>
                                        </td>
                                        
                                        <!-- Product Code -->
                                        <td>
                                            <div class="product-code" style="font-family: monospace; font-size: 13px; color: #495057; background: #f8f9fa; padding: 4px 8px; border-radius: 4px; display: inline-block;">
                                                <?php echo htmlspecialchars($row['product_code'] ?? 'N/A'); ?>
                                            </div>
                                        </td>

                                        <!-- Category -->
                                        <td>
                                            <span class="product-category">
                                                <?php echo htmlspecialchars($row['category_name'] ?? 'Uncategorized'); ?>
                                            </span>
                                        </td>
                                        
                                        <!-- Stock -->
                                        <?php if (isset($_SESSION['allow_inventory']) && $_SESSION['allow_inventory'] == 1): ?>
                                        <td>
                                            <div class="stock-display">
                                                <?php 
                                                $stock = (int)$row['stock_quantity'];
                                                $threshold = (int)$row['low_stock_threshold'];
                                                $is_low = $stock <= $threshold;
                                                ?>
                                                <span style="font-weight: 600; color: <?php echo $is_low ? '#dc3545' : '#28a745'; ?>;">
                                                    <?php echo $stock; ?>
                                                </span>
                                                <?php if ($is_low): ?>
                                                    <i class="fas fa-exclamation-triangle" style="color: #dc3545; font-size: 12px;" title="Low Stock"></i>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <?php endif; ?>
                                        
                                        <!-- Status Badge -->
                                        <td>
                                            <?php if ($row['status'] === 'active'): ?>
                                                <span class="status-badge pay-status-paid">Active</span>
                                            <?php else: ?>
                                                <span class="status-badge pay-status-unpaid">Inactive</span>
                                            <?php endif; ?>
                                        </td>

                                        <?php if ($is_main_admin && $_SESSION['role_id'] == 1): ?>
                                        <!-- Tenant -->
                                        <td class="customer-name">
                                            <div class="customer-info">
                                                <h6 style="margin: 0; font-size: 14px;">
                                                    <?php echo htmlspecialchars($row['company_name'] ?? 'N/A'); ?>
                                                </h6>
                                            </div>
                                        </td>
                                        <?php endif; ?>

                                        <!-- Created Date -->
                                        <td>
                                            <div style="font-size: 13px;">
                                                <?php echo date('Y-m-d', strtotime($row['created_at'])); ?>
                                                <br>
                                                <small style="color: #6c757d;"><?php echo date('h:i:s A', strtotime($row['created_at'])); ?></small>
                                            </div>
                                        </td>
                                        
                                        <!-- Action Buttons -->
                                        <td class="actions">
                                            <div class="action-buttons-group">
                                                <button type="button" class="action-btn view-btn view-product-btn"
                                                        data-product-id="<?= $row['id'] ?>"
                                                        data-product-name="<?= htmlspecialchars($row['name']) ?>"
                                                        data-product-category="<?= htmlspecialchars($row['category_name'] ?? 'Uncategorized') ?>"
                                                        data-product-code="<?= htmlspecialchars($row['product_code'] ?? '') ?>"
                                                        data-product-description="<?= htmlspecialchars($row['description'] ?? '') ?>"
                                                        <?php if (isset($_SESSION['allow_inventory']) && $_SESSION['allow_inventory'] == 1): ?>
                                                        data-product-stock="<?= htmlspecialchars($row['stock_quantity']) ?>"
                                                        data-product-threshold="<?= htmlspecialchars($row['low_stock_threshold']) ?>"
                                                        <?php endif; ?>
                                                        data-product-status="<?= htmlspecialchars($row['status']) ?>"
                                                        data-product-created="<?= htmlspecialchars($row['created_at']) ?>"
                                                        title="View Product Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                
                                                <?php if (in_array((int)$_SESSION['role_id'], [1, 3]) && isset($_SESSION['allow_inventory']) && $_SESSION['allow_inventory'] == 1): ?>
                                                <button type="button" class="action-btn stock-update-btn" 
                                                        style="background: #17a2b8; color: white;"
                                                        title="Update Stock (Admin & Store)"
                                                        data-product-id="<?= $row['id'] ?>"
                                                        data-product-name="<?= htmlspecialchars($row['name']) ?>"
                                                        data-product-stock="<?= htmlspecialchars($row['stock_quantity']) ?>"
                                                        onclick="openStockUpdateModal(this)">
                                                    <i class="fas fa-boxes"></i>
                                                </button>
                                                <?php endif; ?>
                                                
                                                <?php if (in_array((int)$_SESSION['role_id'], [1, 3])): ?>
                                                <button type="button" class="action-btn" 
                                                        style="background: #6f42c1; color: white;"
                                                        title="Update Selling Price (Admin & Store)"
                                                        data-product-id="<?= $row['id'] ?>"
                                                        data-product-name="<?= htmlspecialchars($row['name']) ?>"
                                                        onclick="openPriceUpdateModal(this)">
                                                    <i class="fas fa-tag"></i>
                                                </button>
                                                <?php endif; ?>
                                                
<?php if (!$is_user): ?>
                                                <button class="action-btn dispatch-btn" title="Edit Product" 
                                                        onclick="editProduct(<?php echo $row['id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                
                                                <!-- Status Toggle Button -->
                                                <button type="button" class="action-btn <?= $row['status'] == 'active' ? 'deactivate-btn' : 'activate-btn' ?> toggle-status-btn"
                                                  data-product-id="<?= $row['id'] ?>"
                                                  data-current-status="<?= $row['status'] ?>"
                                                  data-product-name="<?= htmlspecialchars($row['name']) ?>"
                                                   title="<?= $row['status'] == 'active' ? 'Deactivate Product' : 'Activate Product' ?>"
                                                   data-action="<?= $row['status'] == 'active' ? 'deactivate' : 'activate' ?>">
                                                        <i class="fas <?= $row['status'] == 'active' ? 'fa-toggle-off' : 'fa-toggle-on' ?>"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="<?= (isset($_SESSION['allow_inventory']) && $_SESSION['allow_inventory'] == 1 ? 8 : 7) + ($is_main_admin && $_SESSION['role_id'] == 1 ? 1 : 0) ?>" class="text-center" style="padding: 40px; text-align: center; color: #666;">
                                        <i class="fas fa-box" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                                        No products found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Controls -->
                <div class="pagination">
                    <div class="pagination-info">
                        Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $totalRows); ?> of <?php echo $totalRows; ?> entries
                    </div>
                    <div class="pagination-controls">
                        <?php 
                        $queryParams = $_GET;
                        unset($queryParams['page']);
                        $queryString = http_build_query($queryParams);
                        $baseLink = '?' . ($queryString ? $queryString . '&' : '');
                        ?>

                        <?php if ($page > 1): ?>
                            <button class="page-btn" onclick="window.location.href='<?php echo $baseLink; ?>page=<?php echo $page - 1; ?>'">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <button class="page-btn <?php echo ($i == $page) ? 'active' : ''; ?>" 
                                     onclick="window.location.href='<?php echo $baseLink; ?>page=<?php echo $i; ?>'">
                                <?php echo $i; ?>
                            </button>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <button class="page-btn" onclick="window.location.href='<?php echo $baseLink; ?>page=<?php echo $page + 1; ?>'">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Product Details Modal -->
    <div id="productDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h4>Product Details</h4>
                <span class="close" onclick="closeProductModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="customer-detail-row">
                    <span class="detail-label">Product ID:</span>
                    <span class="detail-value" id="modal-product-id"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Product Name:</span>
                    <span class="detail-value" id="modal-product-name"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Category:</span>
                    <span class="detail-value" id="modal-product-category"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Product Code:</span>
                    <span class="detail-value" id="modal-product-code" style="font-family: monospace; background: #f8f9fa; padding: 4px 8px; border-radius: 4px; display: inline-block;"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Description:</span>
                    <span class="detail-value" id="modal-product-description"></span>
                </div>
                <?php if (isset($_SESSION['allow_inventory']) && $_SESSION['allow_inventory'] == 1): ?>
                <div class="customer-detail-row">
                    <span class="detail-label">Stock:</span>
                    <span class="detail-value" id="modal-product-stock"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Low Stock Threshold:</span>
                    <span class="detail-value" id="modal-product-threshold"></span>
                </div>
                <?php endif; ?>
                <div class="customer-detail-row">
                    <span class="detail-label">Status:</span>
                    <span class="detail-value">
                        <span id="modal-product-status" class="status-badge"></span>
                    </span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Created:</span>
                    <span class="detail-value" id="modal-product-created"></span>
                </div>

                <!-- Active Batches & Pricing Breakdown -->
                <div style="margin-top: 20px; border-top: 1px solid #e2e8f0; padding-top: 15px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <h6 style="margin: 0; font-size: 14px; font-weight: 600; color: #1e293b; display: flex; align-items: center; gap: 6px;">
                            <i class="fas fa-layer-group" style="color: #1565C0;"></i> Active Batches & Pricing
                        </h6>
                        <span id="modal-batch-count" class="badge" style="background: #f1f5f9; color: #475569; font-size: 11px; padding: 4px 8px; border-radius: 12px; border: 1px solid #cbd5e1;">0 batches</span>
                    </div>
                    <div id="modal-batches-container" style="overflow-x: auto;">
                        <div style="text-align: center; padding: 15px; color: #64748b; font-size: 13px;">
                            <i class="fas fa-spinner fa-spin"></i> Loading batch details...
                        </div>
                    </div>
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
            window.location.href = 'product_list.php';
        }

        // Product Details Modal Functions
        function openProductModal(button) {
            const modal = document.getElementById('productDetailsModal');
            
            // Extract data from button attributes
            const productId = button.getAttribute('data-product-id');
            const productName = button.getAttribute('data-product-name');
            const productCategory = button.getAttribute('data-product-category');
            const productCode = button.getAttribute('data-product-code');
            const productDescription = button.getAttribute('data-product-description');
            const productStock = button.getAttribute('data-product-stock');
            const productThreshold = button.getAttribute('data-product-threshold');
            const productStatus = button.getAttribute('data-product-status');
            const productCreated = button.getAttribute('data-product-created');

            // Populate modal fields
            document.getElementById('modal-product-id').textContent = productId;
            document.getElementById('modal-product-name').textContent = productName;
            document.getElementById('modal-product-category').textContent = productCategory;
            document.getElementById('modal-product-code').textContent = productCode || 'N/A';
            document.getElementById('modal-product-description').textContent = productDescription || 'N/A';
            
            const stockEl = document.getElementById('modal-product-stock');
            if (stockEl) stockEl.textContent = (productStock !== null && productStock !== undefined) ? productStock : 'N/A';

            const thresholdEl = document.getElementById('modal-product-threshold');
            if (thresholdEl) thresholdEl.textContent = (productThreshold !== null && productThreshold !== undefined) ? productThreshold : 'N/A';
            
            // Set status badge
            const statusElement = document.getElementById('modal-product-status');
            statusElement.textContent = productStatus ? (productStatus.charAt(0).toUpperCase() + productStatus.slice(1)) : '';
            statusElement.className = 'status-badge ' + (productStatus === 'active' ? 'status-active' : 'status-inactive');
            
            // Format dates
            document.getElementById('modal-product-created').textContent = formatDateTime(productCreated);

            // Fetch and render Active Batches & Prices
            const batchesContainer = document.getElementById('modal-batches-container');
            const batchCountEl = document.getElementById('modal-batch-count');
            batchesContainer.innerHTML = '<div style="text-align: center; padding: 15px; color: #64748b; font-size: 13px;"><i class="fas fa-spinner fa-spin"></i> Loading batch details...</div>';
            batchCountEl.textContent = 'Loading...';

            fetch('/OMS/dist/products/get_stock_batches.php?product_id=' + encodeURIComponent(productId))
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.batches && data.batches.length > 0) {
                        batchCountEl.textContent = data.batches.length + (data.batches.length === 1 ? ' batch' : ' batches');
                        let rows = '';
                        data.batches.forEach(b => {
                            const sellingPrice = Number(b.selling_price).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                            rows += `
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <td style="padding: 8px 10px; font-family: monospace; font-weight: 600; color: #1e293b; font-size: 12px;">${b.batch_number}</td>
                                    <td style="padding: 8px 10px; color: #64748b; font-size: 12px;">${b.received_date || 'N/A'}</td>
                                    <td style="padding: 8px 10px; text-align: right; font-weight: 600; color: #059669; font-size: 12px;">Rs. ${sellingPrice}</td>
                                    <td style="padding: 8px 10px; text-align: center;">
                                        <span style="background: #dcfce7; color: #15803d; font-weight: 600; padding: 2px 8px; border-radius: 10px; font-size: 11px;">
                                            ${b.remaining_qty}
                                        </span>
                                    </td>
                                </tr>
                            `;
                        });
                        batchesContainer.innerHTML = `
                            <table style="width: 100%; border-collapse: collapse; font-size: 12px; margin-top: 4px; background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden;">
                                <thead>
                                    <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; text-align: left;">
                                        <th style="padding: 8px 10px; color: #475569; font-weight: 600;">Batch #</th>
                                        <th style="padding: 8px 10px; color: #475569; font-weight: 600;">Received Date</th>
                                        <th style="padding: 8px 10px; color: #475569; font-weight: 600; text-align: right;">Selling Price</th>
                                        <th style="padding: 8px 10px; color: #475569; font-weight: 600; text-align: center;">Stock</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${rows}
                                </tbody>
                            </table>
                        `;
                    } else {
                        batchCountEl.textContent = '0 batches';
                        batchesContainer.innerHTML = `
                            <div style="background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 6px; padding: 12px; text-align: center; color: #64748b; font-size: 13px;">
                                <i class="fas fa-info-circle" style="margin-right: 4px; color: #94a3b8;"></i> No active stock batches found for this product.
                            </div>
                        `;
                    }
                })
                .catch(err => {
                    batchCountEl.textContent = 'Error';
                    batchesContainer.innerHTML = `
                        <div style="color: #dc3545; padding: 10px; font-size: 12px; text-align: center;">
                            <i class="fas fa-exclamation-triangle"></i> Failed to load batch details.
                        </div>
                    `;
                });

            // Show modal
            modal.style.display = 'block';
        }

        function closeProductModal() {
            document.getElementById('productDetailsModal').style.display = 'none';
        }

        function formatDateTime(dateString) {
            if (!dateString) return 'N/A';
            try {
                const date = new Date(dateString);
                if (isNaN(date.getTime())) return dateString;
                const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                const yyyy = date.getFullYear();
                const mmm = months[date.getMonth()];
                const dd = String(date.getDate()).padStart(2, '0');
                let hours = date.getHours();
                const minutes = String(date.getMinutes()).padStart(2, '0');
                const ampm = hours >= 12 ? 'PM' : 'AM';
                hours = hours % 12;
                hours = hours ? hours : 12;
                return `${mmm} ${dd}, ${yyyy} ${hours}:${minutes} ${ampm}`;
            } catch (e) {
                return dateString;
            }
        }

        // Event listeners for view buttons
        document.addEventListener('DOMContentLoaded', function() {
            const viewButtons = document.querySelectorAll('.view-product-btn');
            viewButtons.forEach(button => {
                button.addEventListener('click', function() {
                    openProductModal(this);
                });
            });

            // Close modal when clicking outside
            window.onclick = function(event) {
                const modal = document.getElementById('productDetailsModal');
                if (event.target === modal) {
                    closeProductModal();
                }
            }
        });

        function editProduct(productId) {
            window.location.href = 'edit_product.php?id=' + productId;
        }

        // Toggle Product Status Functionality
        document.addEventListener('DOMContentLoaded', function() {
            const toggleButtons = document.querySelectorAll('.toggle-status-btn');
            toggleButtons.forEach(button => {
                button.addEventListener('click', function() {
                    toggleProductStatus(this);
                });
            });
        });

        function toggleProductStatus(button) {
            const productId = button.getAttribute('data-product-id');
            const productName = button.getAttribute('data-product-name');
            const currentStatus = button.getAttribute('data-current-status');
            
            const isActive = currentStatus.toLowerCase() === 'active';
            const newStatus = isActive ? 'inactive' : 'active';
            
            Swal.fire({
                title: 'Are you sure?',
                text: `You are about to ${isActive ? 'Deactivate' : 'Activate'} Product: ${productName}`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: isActive ? '#dc3545' : '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: isActive ? 'Yes, deactivate it!' : 'Yes, activate it!',
                cancelButtonText: 'Cancel',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch('toggle_product_status.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            product_id: productId,
                            new_status: newStatus
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                title: 'Updated!',
                                text: 'Product status updated successfully!',
                                icon: 'success',
                                timer: 1500,
                                showConfirmButton: false
                            }).then(() => location.reload());
                        } else {
                            toastManager.error(data.message || 'Failed to update product status');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        toastManager.error('An error occurred while updating the product status.');
                    });
                }
            });
        }

        // Price range filter validation
        document.addEventListener('DOMContentLoaded', function() {
            const priceFromInput = document.getElementById('price_from');
            const priceToInput = document.getElementById('price_to');
            
            if (priceFromInput && priceToInput) {
                priceFromInput.addEventListener('change', function() {
                    if (this.value && priceToInput.value && parseFloat(this.value) > parseFloat(priceToInput.value)) {
                        alert('From price cannot be greater than To price');
                        this.value = '';
                    }
                });
                
                priceToInput.addEventListener('change', function() {
                    if (this.value && priceFromInput.value && parseFloat(this.value) < parseFloat(priceFromInput.value)) {
                        alert('To price cannot be less than From price');
                        this.value = '';
                    }
                });
            }
        });

        // Date range filter validation
        document.addEventListener('DOMContentLoaded', function() {
            const dateFromInput = document.getElementById('date_from');
            const dateToInput = document.getElementById('date_to');
            
            if (dateFromInput && dateToInput) {
                dateFromInput.addEventListener('change', function() {
                    if (this.value && dateToInput.value && new Date(this.value) > new Date(dateToInput.value)) {
                        alert('Date From cannot be later than Date To');
                        this.value = '';
                    }
                });
                
                dateToInput.addEventListener('change', function() {
                    if (this.value && dateFromInput.value && new Date(this.value) < new Date(dateFromInput.value)) {
                        alert('Date To cannot be earlier than Date From');
                        this.value = '';
                    }
                });
            }
        });

    </script>

    <!-- Stock Update Modal -->
    <div id="stockUpdateModal" class="modal">
        <div class="modal-content" style="max-width: 560px;">
            <div class="modal-header">
                <h4>Quick Stock Update - <span id="stock-modal-title-name"></span></h4>
                <span class="close" onclick="closeStockUpdateModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">Current Stock</label>
                    <strong id="stock-modal-current-stock"></strong>
                </div>
                
                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">Action Type</label>
                    <div style="display: flex; gap: 20px;">
                        <label style="cursor: pointer; display: flex; align-items: center; gap: 5px;">
                            <input type="radio" name="stock_operation" value="increase" checked> Increase
                        </label>
                        <label style="cursor: pointer; display: flex; align-items: center; gap: 5px;">
                            <input type="radio" name="stock_operation" value="decrease"> Decrease
                        </label>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label for="stock_batch_id" style="display: block; margin-bottom: 8px; font-weight: 500;">Batch</label>
                    <select id="stock_batch_id" class="form-control" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;"></select>
                    <div id="stock-batch-error" style="color: #e74c3c; font-size: 13px; margin-top: 4px; display: none;"></div>
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label for="adjustment_value" style="display: block; margin-bottom: 8px; font-weight: 500;">Quantity</label>
                    <input type="number" id="adjustment_value" class="form-control" min="1" step="1" value="1" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label for="stock_reason" style="display: block; margin-bottom: 8px; font-weight: 500;">Reason <span style="color: #e74c3c;">*</span></label>
                    <textarea id="stock_reason" class="form-control" rows="2" maxlength="100" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;" placeholder="Enter reason for the stock adjustment (max 100 characters)"></textarea>
                    <div id="stock-reason-error" style="color: #e74c3c; font-size: 13px; margin-top: 4px; display: none;"></div>
                </div>

                <div class="modal-buttons" style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeStockUpdateModal()">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmStockUpdateBtn">Update Stock</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Selling Price Update Modal -->
    <div id="priceUpdateModal" class="modal">
        <div class="modal-content" style="max-width: 560px;">
            <div class="modal-header">
                <h4>Update Selling Price - <span id="price-modal-title-name"></span></h4>
                <span class="close" onclick="closePriceUpdateModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="form-group" style="margin-bottom: 15px;">
                    <label for="price_batch_id" style="display: block; margin-bottom: 8px; font-weight: 500;">Select Batch</label>
                    <select id="price_batch_id" class="form-control" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;"></select>
                    <div id="price-batch-error" style="color: #e74c3c; font-size: 13px; margin-top: 4px; display: none;"></div>
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">Current Batch Selling Prices</label>
                    <div id="price-modal-current-prices" style="max-height: 180px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; background: #f8fafc;">
                        <div style="text-align: center; color: #64748b; font-size: 13px;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">New Selling Price (LKR) <span style="color: #e74c3c;">*</span></label>
                    <input type="number" id="new_selling_price" class="form-control" min="0.01" step="0.01" placeholder="Enter new selling price" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                    <div id="price-error" style="color: #e74c3c; font-size: 13px; margin-top: 4px; display: none;"></div>
                    <div style="font-size: 12px; color: #6c757d; margin-top: 5px;">
                        <i class="fas fa-info-circle"></i> This will update the selling price for the selected batch.
                    </div>
                </div>

                <div class="modal-buttons" style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closePriceUpdateModal()">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmPriceUpdateBtn">Update Price</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Stock Update Functionality
        let currentStockValue = 0;
        let currentBatches = [];
        let currentSelectedProductId = 0;

        function openStockUpdateModal(button) {
            const productId = button.getAttribute('data-product-id');
            const productName = button.getAttribute('data-product-name');
            const productStock = button.getAttribute('data-product-stock');
            
            currentStockValue = parseInt(productStock) || 0;
            currentSelectedProductId = productId;
            currentBatches = [];
            
            document.getElementById('stock-modal-title-name').textContent = productName;
            document.getElementById('stock-modal-current-stock').textContent = productStock;
            
            const adjustmentInput = document.getElementById('adjustment_value');
            adjustmentInput.value = 1;
            
            const reasonInput = document.getElementById('stock_reason');
            if (reasonInput) reasonInput.value = '';
            document.getElementById('stock-reason-error').style.display = 'none';
            document.getElementById('stock-batch-error').style.display = 'none';

            // Explicitly set default operation to increase
            document.querySelector('input[name="stock_operation"][value="increase"]').checked = true;

            const confirmBtn = document.getElementById('confirmStockUpdateBtn');
            confirmBtn.onclick = function() {
                const operation = document.querySelector('input[name="stock_operation"]:checked').value;
                updateProductStock(productId, operation, adjustmentInput.value);
            };

            loadStockBatches(productId);

            document.getElementById('stockUpdateModal').style.display = 'block';
            adjustmentInput.focus();
            adjustmentInput.select();
        }

        function loadStockBatches(productId) {
            const batchSelect = document.getElementById('stock_batch_id');
            batchSelect.innerHTML = '<option value="">Loading batches...</option>';

            fetch('/OMS/dist/products/get_stock_batches.php?product_id=' + encodeURIComponent(productId))
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        batchSelect.innerHTML = '<option value="">No batches available</option>';
                        return;
                    }
                    currentBatches = data.batches || [];

                    if (currentBatches.length === 0) {
                        batchSelect.innerHTML = '<option value="">No confirmed batches available</option>';
                        // Reset quantity validation
                        adjustmentValueChange();
                        return;
                    }

                    batchSelect.innerHTML = '';
                    currentBatches.forEach(batch => {
                        const opt = document.createElement('option');
                        opt.value = batch.batch_id;
                        let label = 'Batch #' + (batch.batch_number || batch.batch_id) +
                            ' (Stock: ' + batch.remaining_qty + ', Rs. ' +
                            Number(batch.selling_price).toFixed(2) + ')';
                        opt.textContent = label;
                        if (batch.remaining_qty <= 0) {
                            opt.disabled = true;
                        }
                        batchSelect.appendChild(opt);
                    });

                    adjustmentValueChange();
                })
                .catch(() => {
                    batchSelect.innerHTML = '<option value="">Error loading batches</option>';
                });
        }

        function getSelectedBatch() {
            const batchSelect = document.getElementById('stock_batch_id');
            const batchId = parseInt(batchSelect.value) || 0;
            return currentBatches.find(b => b.batch_id === batchId) || null;
        }

        function adjustmentValueChange() {
            const adjustmentInput = document.getElementById('adjustment_value');
            const selectedOperation = document.querySelector('input[name="stock_operation"]:checked');
            if (selectedOperation && selectedOperation.value === 'decrease') {
                if (currentStockValue <= 0) {
                    toastManager.warning('Cannot decrease stock. Current stock is already 0.');
                    adjustmentInput.value = 1;
                    document.querySelector('input[name="stock_operation"][value="increase"]').checked = true;
                    return;
                }
                const batch = getSelectedBatch();
                let maxAvailable = currentStockValue;
                if (batch) {
                    maxAvailable = Math.min(maxAvailable, batch.remaining_qty);
                }
                const enteredValue = parseInt(adjustmentInput.value) || 0;
                if (enteredValue > maxAvailable) {
                    adjustmentInput.value = maxAvailable;
                }
            }
        }

        // Validate adjustment value when decreasing stock
        document.addEventListener('DOMContentLoaded', function() {
            const adjustmentInput = document.getElementById('adjustment_value');
            const stockOperationRadios = document.querySelectorAll('input[name="stock_operation"]');

            if (adjustmentInput) {
                adjustmentInput.addEventListener('input', adjustmentValueChange);
            }

            const batchSelect = document.getElementById('stock_batch_id');
            if (batchSelect) {
                batchSelect.addEventListener('change', adjustmentValueChange);
            }

            // Re-validate when switching to decrease operation
            stockOperationRadios.forEach(radio => {
                radio.addEventListener('change', adjustmentValueChange);
            });
        });

        function closeStockUpdateModal() {
            const stockModal = document.getElementById('stockUpdateModal');
            if (stockModal) stockModal.style.display = 'none';
        }

        function updateProductStock(productId, operation, adjustmentValue) {
            const adjustmentNum = parseInt(adjustmentValue) || 0;
            if (adjustmentValue === '' || isNaN(adjustmentValue) || adjustmentNum <= 0) {
                toastManager.warning('Please enter a valid quantity greater than 0.');
                return;
            }

            const reason = (document.getElementById('stock_reason').value || '').trim();
            if (reason === '') {
                document.getElementById('stock-reason-error').textContent = 'Please enter a reason for the stock adjustment.';
                document.getElementById('stock-reason-error').style.display = 'block';
                toastManager.warning('Please enter a reason for the stock adjustment.');
                return;
            }

            const batchSelect = document.getElementById('stock_batch_id');
            const batchId = parseInt(batchSelect.value) || 0;
            if (batchId <= 0) {
                document.getElementById('stock-batch-error').textContent = 'Please select a batch.';
                document.getElementById('stock-batch-error').style.display = 'block';
                toastManager.warning('Please select a batch.');
                return;
            }

            // Check if trying to decrease when stock is 0
            if (operation === 'decrease' && currentStockValue <= 0) {
                toastManager.warning('Cannot decrease stock. Current stock is already 0.');
                return;
            }

            // For decrease, ensure quantity does not exceed selected batch remaining stock
            if (operation === 'decrease') {
                const batch = getSelectedBatch();
                if (batch && adjustmentNum > batch.remaining_qty) {
                    toastManager.warning('Cannot decrease more than the selected batch stock (' + batch.remaining_qty + ').');
                    return;
                }
            }

            const btn = document.getElementById('confirmStockUpdateBtn');
            const originalText = btn.textContent;
            btn.textContent = 'Updating...';
            btn.disabled = true;

            fetch('/OMS/dist/products/update_stock_action.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    product_id: productId,
                    operation: operation,
                    adjustment_value: adjustmentValue,
                    batch_id: batchId,
                    reason: reason
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeStockUpdateModal();
                    toastManager.success('Stock updated successfully!');
                    setTimeout(() => { location.reload(); }, 1500);
                } else {
                    toastManager.error('Error updating stock: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                toastManager.error('An error occurred while updating the stock.');
            })
            .finally(() => {
                btn.textContent = originalText;
                btn.disabled = false;
            });
        }
    </script>

    <script>
        // Selling Price Update Functionality
        let currentPriceUpdateProductId = 0;
        let currentPriceBatches = [];

        function openPriceUpdateModal(button) {
            const productId = button.getAttribute('data-product-id');
            const productName = button.getAttribute('data-product-name');

            currentPriceUpdateProductId = productId;
            currentPriceBatches = [];

            document.getElementById('price-modal-title-name').textContent = productName;

            const priceInput = document.getElementById('new_selling_price');
            priceInput.value = '';
            document.getElementById('price-error').style.display = 'none';
            document.getElementById('price-batch-error').style.display = 'none';

            const confirmBtn = document.getElementById('confirmPriceUpdateBtn');
            confirmBtn.onclick = function() {
                updateSellingPrice(productId, priceInput.value);
            };

            loadPriceBatches(productId);

            document.getElementById('priceUpdateModal').style.display = 'block';
            setTimeout(() => { priceInput.focus(); }, 100);
        }

        function loadPriceBatches(productId) {
            const batchSelect = document.getElementById('price_batch_id');
            const pricesContainer = document.getElementById('price-modal-current-prices');
            batchSelect.innerHTML = '<option value="">Loading batches...</option>';
            pricesContainer.innerHTML = '<div style="text-align: center; color: #64748b; font-size: 13px;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';

            fetch('/OMS/dist/products/get_stock_batches.php?product_id=' + encodeURIComponent(productId))
                .then(response => response.json())
                .then(data => {
                    if (!data.success || !data.batches || data.batches.length === 0) {
                        batchSelect.innerHTML = '<option value="">No confirmed batches available</option>';
                        pricesContainer.innerHTML = '<div style="text-align: center; color: #94a3b8; font-size: 13px; padding: 10px;">No confirmed batches found for this product.</div>';
                        return;
                    }

                    currentPriceBatches = data.batches;

                    // Populate batch dropdown
                    batchSelect.innerHTML = '';
                    currentPriceBatches.forEach(batch => {
                        const opt = document.createElement('option');
                        opt.value = batch.batch_id;
                        let label = 'Batch #' + (batch.batch_number || batch.batch_id) +
                            ' (Stock: ' + batch.remaining_qty + ', Rs. ' +
                            Number(batch.selling_price).toFixed(2) + ')';
                        opt.textContent = label;
                        batchSelect.appendChild(opt);
                    });

                    // Populate current prices list
                    let rows = '';
                    currentPriceBatches.forEach(b => {
                        rows += `
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #eef2f7; font-size: 13px;">
                                <span style="font-family: monospace; font-weight: 600; color: #1e293b;">Batch #${b.batch_number || b.batch_id}</span>
                                <span style="font-weight: 600; color: #059669;">Rs. ${Number(b.selling_price).toFixed(2)}</span>
                            </div>
                        `;
                    });
                    pricesContainer.innerHTML = rows;
                })
                .catch(() => {
                    batchSelect.innerHTML = '<option value="">Error loading batches</option>';
                    pricesContainer.innerHTML = '<div style="color: #dc3545; padding: 10px; font-size: 12px; text-align: center;"><i class="fas fa-exclamation-triangle"></i> Failed to load batch prices.</div>';
                });
        }

        function closePriceUpdateModal() {
            const modal = document.getElementById('priceUpdateModal');
            if (modal) modal.style.display = 'none';
        }

        function updateSellingPrice(productId, newPrice) {
            if (newPrice === '' || isNaN(newPrice) || parseFloat(newPrice) <= 0) {
                document.getElementById('price-error').textContent = 'Please enter a valid selling price greater than 0.';
                document.getElementById('price-error').style.display = 'block';
                toastManager.warning('Please enter a valid selling price.');
                return;
            }

            const batchSelect = document.getElementById('price_batch_id');
            const batchId = parseInt(batchSelect.value) || 0;
            if (batchId <= 0) {
                document.getElementById('price-batch-error').textContent = 'Please select a batch.';
                document.getElementById('price-batch-error').style.display = 'block';
                toastManager.warning('Please select a batch.');
                return;
            }

            const btn = document.getElementById('confirmPriceUpdateBtn');
            const originalText = btn.textContent;
            btn.textContent = 'Updating...';
            btn.disabled = true;

            fetch('/OMS/dist/products/update_selling_price.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    product_id: productId,
                    batch_id: batchId,
                    new_selling_price: parseFloat(newPrice)
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closePriceUpdateModal();
                    toastManager.success('Selling price updated successfully!');
                    setTimeout(() => { location.reload(); }, 1500);
                } else {
                    toastManager.error('Error updating price: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                toastManager.error('An error occurred while updating the selling price.');
            })
            .finally(() => {
                btn.textContent = originalText;
                btn.disabled = false;
            });
        }

        // Close price modal when clicking outside
        document.addEventListener('DOMContentLoaded', function() {
            window.addEventListener('click', function(event) {
                const modal = document.getElementById('priceUpdateModal');
                if (event.target === modal) {
                    closePriceUpdateModal();
                }
            });
        });
    </script>

</body>
</html>