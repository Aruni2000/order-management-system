<?php
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

$is_admin = ($current_user_role == 1);

// Access control – Only main admin (is_main_admin == 1) can access this page
$is_main_admin = isset($_SESSION['is_main_admin']) && $_SESSION['is_main_admin'] == 1;
if (!($is_admin && $is_main_admin)) {
    header("Location: /OMS/dist/pages/access_denied.php");
    exit();
}

$date_from = isset($_GET['date_from']) && !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) && !empty($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$product_search = isset($_GET['product_search']) ? trim($_GET['product_search']) : '';

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$categories = [];
$catRes = $conn->query("SELECT c1.id, c1.name, c1.parent_id, c2.name as parent_name FROM categories c1 LEFT JOIN categories c2 ON c1.parent_id = c2.id ORDER BY COALESCE(c2.name, c1.name), c1.name ASC");
if ($catRes) {
    while ($crow = $catRes->fetch_assoc()) {
        $categories[] = $crow;
    }
}

$roleCondition = "";
if (!$is_admin) {
    $roleCondition = " AND oh.user_id = $current_user_id";
}

$searchConditions = ["oh.interface IN ('individual', 'leads')", "DATE(oh.created_at) BETWEEN '$date_from' AND '$date_to'"];

if (!empty($product_search)) {
    $searchTerm = $conn->real_escape_string($product_search);
    $searchConditions[] = "(p.name LIKE '%$searchTerm%' OR p.product_code LIKE '%$searchTerm%' OR p.id LIKE '%$searchTerm%')";
}

$whereClause = " WHERE " . implode(' AND ', $searchConditions) . $roleCondition;

$countSql = "SELECT COUNT(DISTINCT oi.product_id) as total 
             FROM order_items oi 
             JOIN order_header oh ON oi.order_id = oh.order_id 
             LEFT JOIN products p ON oi.product_id = p.id 
             LEFT JOIN categories c ON p.category_id = c.id" . $whereClause;

$sql = "SELECT 
            p.id as product_id,
            p.name as product_name,
            p.product_code,
            c.name as category_name,
            pc.name as parent_category_name,
            SUM(CASE WHEN oh.status IN ('done', 'delivered') THEN oi.quantity ELSE 0 END) as total_quantity,
            SUM(CASE WHEN oh.status IN ('done', 'delivered') THEN oi.total_amount ELSE 0 END) as total_earn,
            COUNT(DISTINCT oi.order_id) as order_count,
            COUNT(DISTINCT CASE WHEN oh.status = 'pending' THEN oh.order_id END) as pending_count,
            COUNT(DISTINCT CASE WHEN oh.status = 'dispatch' THEN oh.order_id END) as dispatched_count,
            COUNT(DISTINCT CASE WHEN oh.status IN ('done', 'delivered') THEN oh.order_id END) as completed_count,
            COUNT(DISTINCT CASE WHEN oh.status = 'cancel' THEN oh.order_id END) as cancelled_count,
            -- New Metrics
            (COUNT(DISTINCT CASE WHEN oh.status IN ('done', 'delivered') THEN oh.order_id END) * 100.0 / NULLIF(COUNT(DISTINCT CASE WHEN oh.status IN ('done', 'delivered', 'cancel') THEN oh.order_id END), 0)) as success_rate
        FROM order_items oi 
        JOIN order_header oh ON oi.order_id = oh.order_id 
        LEFT JOIN products p ON oi.product_id = p.id 
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN categories pc ON c.parent_id = pc.id" . $whereClause . "
        GROUP BY p.id, p.name, p.product_code, c.name, pc.name
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
                COUNT(DISTINCT CASE WHEN oh.status IN ('done', 'delivered') THEN oi.product_id END) as unique_products,
                SUM(CASE WHEN oh.status IN ('done', 'delivered') THEN oi.quantity ELSE 0 END) as total_items_sold,
                SUM(CASE WHEN oh.status IN ('done', 'delivered') THEN oi.total_amount ELSE 0 END) as total_earn,
                COUNT(DISTINCT oi.order_id) as total_orders,
                -- Average Metrics
                (COUNT(DISTINCT CASE WHEN oh.status IN ('done', 'delivered') THEN oh.order_id END) * 100.0 / NULLIF(COUNT(DISTINCT CASE WHEN oh.status IN ('done', 'delivered', 'cancel') THEN oh.order_id END), 0)) as avg_success_rate
            FROM order_items oi 
            JOIN order_header oh ON oi.order_id = oh.order_id 
            LEFT JOIN products p ON oi.product_id = p.id 
            LEFT JOIN categories c ON p.category_id = c.id" . $whereClause;

$summaryResult = $conn->query($summarySql);
$summary = [
    'unique_products' => 0,
    'total_items_sold' => 0,
    'total_earn' => 0,
    'total_orders' => 0,
    'avg_success_rate' => 0
];
if ($summaryResult && $summaryResult->num_rows > 0) {
    $summary = $summaryResult->fetch_assoc();
}
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Product Analysis | <?= htmlspecialchars($_SESSION['company_name'] ?? '') ?></title>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>
    <link rel="stylesheet" href="../assets/css/tailwind-utilities.css" />
    <link rel="stylesheet" href="../assets/css/orders.css" />
    <link rel="stylesheet" href="../assets/css/customers.css" />
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
                        <div class="form-group">
                            <label for="date_from">Date From</label>
                            <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="date_to">Date To</label>
                            <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
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
    <div class="flex-1 min-w-[180px] bg-white rounded-lg p-4 shadow-sm border border-gray-100">
        <div class="text-sm text-gray-500 mb-1">Products Sold</div>
        <div class="text-2xl font-semibold text-blue-600"><?php echo number_format($summary['unique_products'] ?? 0); ?></div>
    </div>
    <div class="flex-1 min-w-[180px] bg-white rounded-lg p-4 shadow-sm border border-gray-100">
        <div class="text-sm text-gray-500 mb-1">Items Sold</div>
        <div class="text-2xl font-semibold text-purple-600"><?php echo number_format($summary['total_items_sold'] ?? 0); ?></div>
    </div>
    <div class="flex-1 min-w-[180px] bg-white rounded-lg p-4 shadow-sm border border-gray-100">
        <div class="text-sm text-gray-500 mb-1">Success Rate</div>
        <div class="text-2xl font-semibold text-green-600"><?php echo number_format($summary['avg_success_rate'] ?? 0, 1); ?>%</div>
    </div>
    <div class="flex-1 min-w-[180px] bg-white rounded-lg p-4 shadow-sm border border-gray-100">
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
                                <th>Category</th>
                                <th>Code</th>
                                <th>Qty Sold</th>
                                <th>Earn</th>
                                <th>Orders</th>
                                <th>Success %</th>
                                <th>Pending</th>
                                <th>Dispatched</th>
                                <th>Completed</th>
                                <th>Cancelled</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['product_id']); ?></td>
                                        <td>
                                            <div class="product-info">
                                                <h6 style="margin: 0; font-size: 14px; font-weight: 600;"><?php echo htmlspecialchars($row['product_name'] ?? 'Unknown Product'); ?></h6>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="product-category">
                                                <?php 
                                                if ($row['parent_category_name']) {
                                                    echo htmlspecialchars($row['parent_category_name'] . ' ( ' . $row['category_name'] . ' )');
                                                } else {
                                                    echo htmlspecialchars($row['category_name'] ?? 'Uncategorized');
                                                }
                                                ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="product-code" style="font-family: monospace; font-size: 13px; color: #495057; background: #f8f9fa; padding: 4px 8px; border-radius: 4px; display: inline-block;">
                                                <?php echo htmlspecialchars($row['product_code'] ?? 'N/A'); ?>
                                            </div>
                                        </td>
                                        <td style="font-weight: 600;">
                                            <?php echo number_format($row['total_quantity'] ?? 0); ?>
                                        </td>
                                        <td style="font-weight: 600; color: #28a745;">
                                            LKR <?php echo number_format($row['total_earn'] ?? 0, 2); ?>
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
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="12" class="text-center" style="padding: 40px; text-align: center; color: #666;">
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
                <span><strong>Products Sold</strong> — count of different products sold</span>
                <span style="color: #8b5cf6;">●</span>
                <span><strong>Items Sold</strong> — total quantity of all items</span>
                <span style="color: #10b981;">●</span>
                <span><strong>Success Rate</strong> — orders completed without cancellation</span>
                <span style="color: #f59e0b;">●</span>
                <span><strong>Total Orders</strong> — all orders in selected period</span>
            </div>
        </div>

        <div style="margin-bottom: 16px;">
            <h6 style="margin: 0 0 10px; font-size: 14px;">📋 Product Table Columns</h6>
            <ul style="margin: 0; padding-left: 20px; color: #374151; font-size: 13px; line-height: 1.7;">
                <li><strong>Qty Sold & Earn</strong> — only completed/delivered orders count</li>
                <li><strong>Success %</strong> — how often this product completes</li>
                <li><strong>Status columns</strong> — pending, dispatched, completed, cancelled</li>
            </ul>
        </div>

        <div style="margin-bottom: 16px;">
            <h6 style="margin: 0 0 10px; font-size: 14px;">🔍 Using Filters</h6>
            <ul style="margin: 0; padding-left: 20px; color: #374151; font-size: 13px; line-height: 1.7;">
                <li><strong>Date range</strong> — pick From / To dates</li>
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
                        alert('From date cannot be later than To date');
                        this.value = '';
                    }
                });
                
                dateToInput.addEventListener('change', function() {
                    if (this.value && dateFromInput.value && new Date(this.value) < new Date(dateFromInput.value)) {
                        alert('To date cannot be earlier than From date');
                        this.value = '';
                    }
                });
            }
        });
    </script>
</body>
</html>
