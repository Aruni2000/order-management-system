<?php
/**
 *   Revenue  = money we received from orders that were COMPLETED or
 *              DELIVERED. Cancelled and still-pending orders are NOT
 *              counted, because that money is not earned yet.
 *
 *   Est. Cost = what those same items cost us to buy from suppliers.
 *              Every product arrives in batches, and each batch has its
 *              own buying price. We add up the batch price for every
 *              piece that was sold.
 *
 *   Profit   = Revenue minus Est. Cost.
 *              Example: sold items worth 1,000 LKR, they cost us 700 LKR
 *              -> profit is 300 LKR.
 *
 *   Qty Sold  = how many pieces of the product were sold (completed orders only).
 *
 *   Success % = out of the orders that were either completed OR cancelled,
 *              how many ended up completed. 10 completed + 2 cancelled
 *              = 83.3% success.
 *
 *   Pending / Dispatched / Completed / Cancelled = how many orders that
 *              include this product are in each status right now.
 *
 * THE TOP CARDS show the same numbers added up for ALL products together.
 *
 * WHO SEES WHAT:
 *   - Admins (role 1) see everything, including Revenue, Est. Cost and Profit.
 *   - Normal users (role 2) do NOT see money figures - only quantities,
 *     order counts and success rate.
 */
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

$current_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$current_user_role = isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : 0;

if ($current_user_id == 0 || $current_user_role == 0) {
    $session_identifier = isset($_SESSION['username']) ? $_SESSION['username'] : 
                         (isset($_SESSION['email']) ? $_SESSION['email'] : '');
    
    if ($session_identifier) {
        $userQuery = "SELECT u.id, u.role_id FROM users u WHERE u.email = ? OR u.name = ? LIMIT 1";
        $stmt = $conn->prepare($userQuery);
        $stmt->bind_param("ss", $session_identifier, $session_identifier);
        $stmt->execute();
        $userResult = $stmt->get_result();
        
        if ($userResult && $userResult->num_rows > 0) {
            $userData = $userResult->fetch_assoc();
            $current_user_id = (int)$userData['id'];
            $current_user_role = (int)$userData['role_id'];
            $_SESSION['user_id'] = $current_user_id;
            $_SESSION['role_id'] = $current_user_role;
        }
        $stmt->close();
    }
}

if ($current_user_id == 0) {
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

// Access control – Admin-role and User-role users may view product analysis:
//  - main admin (is_main_admin=1, role=1): sees all tenants
//  - sub-company admin (is_main_admin=0, role=1): sees their own company only
//  - user (role=2): sees only the orders they placed
$is_main_admin = (int)($_SESSION['is_main_admin'] ?? 0) === 1;
$is_admin = $current_user_role == 1;
$is_user = $current_user_role == 2;
$is_super_admin = ($is_main_admin && $is_admin);
$session_tenant_id = (int)($_SESSION['tenant_id'] ?? 0);
if (!$is_admin && !$is_user) {
    header("Location: /OMS/dist/pages/access_denied.php");
    exit();
}

$date_from = isset($_GET['date_from']) && !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) && !empty($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$product_search = isset($_GET['product_search']) ? trim($_GET['product_search']) : '';
$category_filter = isset($_GET['category_filter']) ? intval($_GET['category_filter']) : 0;
// Tenant filter: only meaningful for the Super Admin (who sees all tenants)
$tenant_filter = $is_super_admin ? (isset($_GET['tenant_filter']) ? intval($_GET['tenant_filter']) : 0) : 0;

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Tenant list for the tenant dropdown (Super Admin only)
$tenants = [];
if ($is_super_admin) {
    $tRes = $conn->query("SELECT tenant_id, company_name FROM tenants WHERE status = 'active' ORDER BY company_name ASC");
    if ($tRes) {
        while ($trow = $tRes->fetch_assoc()) {
            $tenants[] = $trow;
        }
    }
}

// Category filter options scoped to the selected tenant (Super Admin with a
// tenant picked), the accessing user's own tenant, or all tenants for a Super
// Admin with no tenant selected
$categories = [];
if ($is_super_admin) {
    $catSql = "SELECT id, name, tenant_id FROM categories WHERE status = 'active'";
    if ($tenant_filter > 0) {
        $catSql .= " AND tenant_id = $tenant_filter";
    }
    $catRes = $conn->query($catSql . " ORDER BY name ASC");
} else {
    $catRes = $conn->query("SELECT id, name, tenant_id FROM categories WHERE status = 'active' AND tenant_id = $session_tenant_id ORDER BY name ASC");
}
if ($catRes) {
    while ($crow = $catRes->fetch_assoc()) {
        $categories[] = $crow;
    }
}

$roleCondition = "";
if ($is_admin) {
    // Admins: Super Admin sees all tenants, or one tenant when picked in the
    // tenant filter; sub-company admin sees own company
    $roleCondition = $is_super_admin
        ? ($tenant_filter > 0 ? " AND oh.tenant_id = $tenant_filter" : "")
        : " AND oh.tenant_id = $session_tenant_id";
} else {
    // Users (role 2): see only orders they placed in their tenant
    $roleCondition = " AND oh.user_id = $current_user_id AND oh.tenant_id = $session_tenant_id";
}

$safe_from = $conn->real_escape_string($date_from);
$safe_to = $conn->real_escape_string($date_to);

$searchConditions = ["oh.interface IN ('individual', 'leads')", "DATE(oh.created_at) BETWEEN '$safe_from' AND '$safe_to'"];

if (!empty($product_search)) {
    $searchTerm = $conn->real_escape_string($product_search);
    $searchConditions[] = "(p.name LIKE '%$searchTerm%' OR p.product_code LIKE '%$searchTerm%' OR p.id LIKE '%$searchTerm%')";
}

if ($category_filter > 0) {
    $searchConditions[] = "p.category_id = $category_filter";
}

$whereClause = " WHERE " . implode(' AND ', $searchConditions) . $roleCondition;

// Shared SQL fragments: the "done/delivered" status list and the batch-cost join
// are defined once here and reused by every query below.
$doneStatuses = "'done', 'delivered'";
$costJoin = "LEFT JOIN (
            SELECT oib.order_item_id, SUM(oib.quantity * b.buying_price) as item_cost
            FROM order_item_batches oib
            JOIN batches b ON oib.batch_id = b.batch_id
            GROUP BY oib.order_item_id
        ) oic ON oi.item_id = oic.order_item_id";
$baseFrom = "FROM order_items oi
        JOIN order_header oh ON oi.order_id = oh.order_id
        LEFT JOIN products p ON oi.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        $costJoin";

// Count query: category and batch-cost joins are not needed to count distinct products
$countSql = "SELECT COUNT(DISTINCT oi.product_id) as total
             FROM order_items oi
             JOIN order_header oh ON oi.order_id = oh.order_id
             LEFT JOIN products p ON oi.product_id = p.id" . $whereClause;

$sql = "SELECT
            p.id as product_id,
            p.name as product_name,
            p.product_code,
            c.name as category_name,
            SUM(CASE WHEN oh.status IN ($doneStatuses) THEN oi.quantity ELSE 0 END) as total_quantity,
            SUM(CASE WHEN oh.status IN ($doneStatuses) THEN oi.total_amount ELSE 0 END) as total_earn,
            SUM(CASE WHEN oh.status IN ($doneStatuses) THEN COALESCE(oic.item_cost, 0) ELSE 0 END) as total_cost,
            COUNT(DISTINCT oi.order_id) as order_count,
            COUNT(DISTINCT CASE WHEN oh.status = 'pending' THEN oh.order_id END) as pending_count,
            COUNT(DISTINCT CASE WHEN oh.status = 'dispatch' THEN oh.order_id END) as dispatched_count,
            COUNT(DISTINCT CASE WHEN oh.status IN ($doneStatuses) THEN oh.order_id END) as completed_count,
            COUNT(DISTINCT CASE WHEN oh.status = 'cancel' THEN oh.order_id END) as cancelled_count
        $baseFrom" . $whereClause . "
        GROUP BY p.id, p.name, p.product_code, c.name
        ORDER BY total_earn DESC, total_quantity DESC
        LIMIT $limit OFFSET $offset";

$countResult = $conn->query($countSql);
$totalRows = 0;
if ($countResult && $countResult->num_rows > 0) {
    $totalRows = $countResult->fetch_assoc()['total'];
}
$totalPages = ceil($totalRows / $limit);

$result = $conn->query($sql);

$summarySql = "SELECT
                COUNT(DISTINCT CASE WHEN oh.status IN ($doneStatuses) THEN oi.product_id END) as unique_products,
                SUM(CASE WHEN oh.status IN ($doneStatuses) THEN oi.quantity ELSE 0 END) as total_items_sold,
                SUM(CASE WHEN oh.status IN ($doneStatuses) THEN oi.total_amount ELSE 0 END) as total_earn,
                SUM(CASE WHEN oh.status IN ($doneStatuses) THEN COALESCE(oic.item_cost, 0) ELSE 0 END) as total_cost,
                COUNT(DISTINCT oi.order_id) as total_orders,
                COUNT(DISTINCT CASE WHEN oh.status IN ($doneStatuses) THEN oh.order_id END) as completed_orders,
                COUNT(DISTINCT CASE WHEN oh.status = 'cancel' THEN oh.order_id END) as cancelled_orders
            $baseFrom" . $whereClause;

$summaryResult = $conn->query($summarySql);
$summary = [
    'unique_products' => 0,
    'total_items_sold' => 0,
    'total_earn' => 0,
    'total_cost' => 0,
    'total_profit' => 0,
    'total_orders' => 0,
    'completed_orders' => 0,
    'cancelled_orders' => 0,
    'avg_success_rate' => 0
];
if ($summaryResult && $summaryResult->num_rows > 0) {
    $summary = $summaryResult->fetch_assoc();
}

// Derived metrics: profit and success rate are simple arithmetic, so they are
// computed in PHP instead of being repeated as SQL expressions.
$summary['total_profit'] = (float)$summary['total_earn'] - (float)$summary['total_cost'];
$summaryCompleted = (int)$summary['completed_orders'];
$summaryDecided = $summaryCompleted + (int)$summary['cancelled_orders'];
$summary['avg_success_rate'] = $summaryDecided > 0 ? $summaryCompleted * 100 / $summaryDecided : 0;
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Product Analysis | <?= htmlspecialchars($_SESSION['company_name'] ?? '') ?></title>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>
    <link rel="stylesheet" href="../assets/css/tailwind-utilities.css" />
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
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/loader.php'); 
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');?>

    <div class="pc-container">
        <div class="pc-content">
                <div class="page-header">
                    <div class="page-block">
                        <div class="page-header-title">
                            <h5 class="mb-0 font-medium">Product Analysis</h5>
                        </div>
                    </div>
                </div>

            <div class="main-content-wrapper">
                <div class="tracking-container">
                    <form class="tracking-form" method="GET" action="">
                        <?php if ($is_super_admin && !empty($tenants)): ?>
                        <div class="form-group">
                            <label for="tenant_filter">Tenant</label>
                            <select id="tenant_filter" name="tenant_filter">
                                <option value="">All Tenants</option>
                                <?php foreach ($tenants as $t): ?>
                                    <option value="<?php echo $t['tenant_id']; ?>" <?php echo ($tenant_filter == $t['tenant_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($t['company_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label for="date_from">Date From</label>
                            <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="date_to">Date To</label>
                            <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
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

                        <div class="form-group">
                            <label for="product_search">Product Search</label>
                            <input type="text" id="product_search" name="product_search" 
                                   placeholder="Product name or code" 
                                   value="<?php echo htmlspecialchars($product_search); ?>">
                        </div>

                        <div class="form-group">
                            <div class="button-group">
                                <button type="submit" class="search-btn">
                                    <i class="fas fa-search"></i> Search
                                </button>
                                <button type="button" class="search-btn" onclick="clearFilters()" style="background: #6c757d;">
                                    <i class="fas fa-times"></i> Clear
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="col-span-12 mb-4">
                    <h2 class="section-title" style="font-size: 16px; font-weight: 600; color: #1f2937; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 2px solid #e5e7eb;">Analysis Summary <i class="fas fa-info-circle text-primary" style="cursor: pointer; font-size: 16px; color: #3b82f6;" onclick="openInfoModal()" title="Click here to know more about this page"></i></h2>
                </div>

                <div class="flex flex-wrap gap-4 mb-6">
                    <div class="flex-1 min-w-[170px] bg-white rounded-lg p-4 shadow-sm border border-gray-100">
                        <div class="text-sm text-gray-500 mb-1">Products Sold</div>
                        <div class="text-2xl font-semibold text-blue-600"><?php echo number_format($summary['unique_products'] ?? 0); ?></div>
                    </div>
                    <div class="flex-1 min-w-[170px] bg-white rounded-lg p-4 shadow-sm border border-gray-100">
                        <div class="text-sm text-gray-500 mb-1">Items Sold</div>
                        <div class="text-2xl font-semibold text-purple-600"><?php echo number_format($summary['total_items_sold'] ?? 0); ?></div>
                    </div>
                    <?php if ($is_admin): ?>
                    <div class="flex-1 min-w-[170px] bg-white rounded-lg p-4 shadow-sm border border-gray-100">
                        <div class="text-sm text-gray-500 mb-1">Total Revenue</div>
                        <div class="text-2xl font-semibold text-emerald-600">LKR <?php echo number_format($summary['total_earn'] ?? 0, 2); ?></div>
                    </div>
                    <div class="flex-1 min-w-[170px] bg-white rounded-lg p-4 shadow-sm border border-gray-100">
                        <div class="text-sm text-gray-500 mb-1">Total Profit</div>
                        <div class="text-2xl font-semibold text-teal-600">LKR <?php echo number_format($summary['total_profit'] ?? 0, 2); ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="flex-1 min-w-[170px] bg-white rounded-lg p-4 shadow-sm border border-gray-100">
                        <div class="text-sm text-gray-500 mb-1">Success Rate</div>
                        <div class="text-2xl font-semibold text-green-600"><?php echo number_format($summary['avg_success_rate'] ?? 0, 1); ?>%</div>
                    </div>
                    <div class="flex-1 min-w-[170px] bg-white rounded-lg p-4 shadow-sm border border-gray-100">
                        <div class="text-sm text-gray-500 mb-1">Total Orders</div>
                        <div class="text-2xl font-semibold text-amber-600"><?php echo number_format($summary['total_orders'] ?? 0); ?></div>
                    </div>
                </div>

                <div class="table-wrapper">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Product Name</th>
                                <th>Code</th>
                                <th>Category</th>
                                <th>Qty Sold</th>
                                <th>Orders</th>
                                <th>Success %</th>
                                <th>Pending</th>
                                <th>Dispatched</th>
                                <th>Completed</th>
                                <th>Cancelled</th>
                                <?php if ($is_admin): ?>
                                <th>Revenue</th>
                                <th>Est. Cost</th>
                                <th>Profit</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()):
                                    // Profit and success rate derived from the raw sums above
                                    $row['total_profit'] = (float)$row['total_earn'] - (float)$row['total_cost'];
                                    $rowDecided = (int)$row['completed_count'] + (int)$row['cancelled_count'];
                                    $row['success_rate'] = $rowDecided > 0 ? (int)$row['completed_count'] * 100 / $rowDecided : 0;
                                ?>
                                    <tr>
                                        <td class="order-id"><?php echo htmlspecialchars($row['product_id']); ?></td>
                                        <td>
                                            <div class="product-info">
                                                <h6 style="margin: 0; font-size: 14px; font-weight: 600;"><?php echo htmlspecialchars($row['product_name'] ?? 'Unknown Product'); ?></h6>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="product-code" style="font-family: monospace; font-size: 13px; color: #495057; background: #f8f9fa; padding: 4px 8px; border-radius: 4px; display: inline-block;">
                                                <?php echo htmlspecialchars($row['product_code'] ?? 'N/A'); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="product-category">
                                                <?php echo htmlspecialchars($row['category_name'] ?? 'Uncategorized'); ?>
                                            </span>
                                        </td>
                                        <td style="font-weight: 600;">
                                            <?php echo number_format($row['total_quantity'] ?? 0); ?>
                                        </td>
                                        <td>
                                            <?php echo number_format($row['order_count'] ?? 0); ?>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <span class="mr-2"><?php echo number_format($row['success_rate'] ?? 0, 1); ?>%</span>
                                                <div class="w-full bg-gray-200 rounded-full h-1.5 dark:bg-gray-700" style="width: 50px;">
                                                    <div class="bg-success h-1.5 rounded-full" style="width: <?php echo min(100, max(0, $row['success_rate'])); ?>%; background-color: #28a745;"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span style="color: #f59e0b; font-weight: 600;">
                                                <?php echo number_format($row['pending_count'] ?? 0); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo number_format($row['dispatched_count'] ?? 0); ?>
                                        </td>
                                        <td>
                                            <span style="color: #28a745; font-weight: 600;">
                                                <?php echo number_format($row['completed_count'] ?? 0); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span style="color: <?php echo ($row['cancelled_count'] ?? 0) > 0 ? '#dc3545' : '#6c757d'; ?>; font-weight: <?php echo ($row['cancelled_count'] ?? 0) > 0 ? '600' : '400'; ?>;">
                                                <?php echo number_format($row['cancelled_count'] ?? 0); ?>
                                            </span>
                                        </td>
                                        <?php if ($is_admin): ?>
                                        <td style="font-weight: 600; color: #28a745;">
                                            LKR <?php echo number_format($row['total_earn'] ?? 0, 2); ?>
                                        </td>
                                        <td style="font-weight: 500; color: #64748b;">
                                            LKR <?php echo number_format($row['total_cost'] ?? 0, 2); ?>
                                        </td>
                                        <td style="font-weight: 600; color: #059669;">
                                            LKR <?php echo number_format($row['total_profit'] ?? 0, 2); ?>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="<?php echo $is_admin ? 14 : 11; ?>" class="text-center" style="padding: 40px; text-align: center; color: #666;">
                                        <i class="fas fa-chart-bar" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                                        No product analysis data found for the selected filters
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalRows > 0): ?>
                <div class="pagination">
                    <div class="pagination-info">
                        Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $totalRows); ?> of <?php echo $totalRows; ?> products
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
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php
    include_once($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/info_modal.php');
    renderInfoModal(
        'How Product Analysis Works',
        'fas fa-chart-bar',
        '<div style="font-family: system-ui, -apple-system, sans-serif;">

        <div style="margin-bottom: 16px;">
            <h6 style="margin: 0 0 10px; font-size: 14px;">📊 Summary Cards (Top Section)</h6>
            <div style="display: grid; grid-template-columns: auto 1fr; gap: 4px 10px; font-size: 13px; color: #374151;">
                <span style="color: #3b82f6;">●</span>
                <span><strong>Products Sold</strong> — count of unique products sold</span>
                <span style="color: #8b5cf6;">●</span>
                <span><strong>Items Sold</strong> — total quantity of all items sold</span>'
                . ($is_admin ? '
                <span style="color: #10b981;">●</span>
                <span><strong>Total Revenue</strong> — total sales earnings from completed/delivered orders</span>
                <span style="color: #0d9488;">●</span>
                <span><strong>Total Profit</strong> — gross profit (Revenue minus Batch Buying Cost)</span>' : '') . '
                <span style="color: #16a34a;">●</span>
                <span><strong>Success Rate</strong> — orders completed without cancellation</span>
                <span style="color: #f59e0b;">●</span>
                <span><strong>Total Orders</strong> — all orders in the selected period</span>
            </div>
        </div>

        <div style="margin-bottom: 16px;">
            <h6 style="margin: 0 0 10px; font-size: 14px;">📋 Product Table Columns</h6>
            <ul style="margin: 0; padding-left: 20px; color: #374151; font-size: 13px; line-height: 1.7;">
                <li><strong>' . ($is_admin ? 'Qty Sold & Revenue' : 'Qty Sold') . '</strong> — from completed/delivered orders</li>'
                . ($is_admin ? '
                <li><strong>Est. Cost & Profit</strong> — calculated from GRN batch buying prices</li>' : '') . '
                <li><strong>Success %</strong> — completion percentage for this product</li>
                <li><strong>Status columns</strong> — pending, dispatched, completed, cancelled</li>
            </ul>
        </div>

        <div style="margin-bottom: 16px;">
            <h6 style="margin: 0 0 10px; font-size: 14px;">🔍 Using Filters</h6>
            <ul style="margin: 0; padding-left: 20px; color: #374151; font-size: 13px; line-height: 1.7;">
                <li><strong>Date range</strong> — pick From / Date Tos</li>
                <li><strong>Category</strong> — filter by product category</li>
                <li><strong>Search</strong> — type product name or code</li>
                <li><strong>Clear</strong> — resets all filters</li>
            </ul>
        </div>

        <div style="background: #fef3c7; border-radius: 6px; padding: 10px 12px; font-size: 13px; color: #92400e;">
            💡 Products sorted by highest earnings first. Use filters to narrow down.
        </div>

        </div>'
    );
    ?>

    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php'); ?>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php'); ?>

    <script>
        function clearFilters() {
            window.location.href = 'product_analysis.php';
        }

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
</body>
</html>
