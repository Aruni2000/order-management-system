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
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');

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

$date_from = isset($_GET['date_from']) && !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) && !empty($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$category_filter = isset($_GET['category_filter']) ? trim($_GET['category_filter']) : '';
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

if (!empty($category_filter)) {
    $catTerm = $conn->real_escape_string($category_filter);
    $searchConditions[] = "(p.category_id = '$catTerm' OR c.parent_id = '$catTerm')";
}

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
            p.lkr_price,
            c.name as category_name,
            pc.name as parent_category_name,
            SUM(oi.quantity) as total_quantity,
            SUM(oi.total_amount) as total_revenue,
            COUNT(DISTINCT oi.order_id) as order_count,
            COUNT(DISTINCT CASE WHEN oh.status = 'dispatch' THEN oh.order_id END) as dispatched_count,
            COUNT(DISTINCT CASE WHEN oh.status IN ('Done', 'Delivered') THEN oh.order_id END) as completed_count,
            COUNT(DISTINCT CASE WHEN oh.status = 'cancel' THEN oh.order_id END) as cancelled_count
        FROM order_items oi 
        JOIN order_header oh ON oi.order_id = oh.order_id 
        LEFT JOIN products p ON oi.product_id = p.id 
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN categories pc ON c.parent_id = pc.id" . $whereClause . "
        GROUP BY p.id, p.name, p.product_code, p.lkr_price, c.name, pc.name
        ORDER BY total_revenue DESC, total_quantity DESC
        LIMIT $limit OFFSET $offset";

$countResult = $conn->query($countSql);
$totalRows = 0;
if ($countResult && $countResult->num_rows > 0) {
    $totalRows = $countResult->fetch_assoc()['total'];
}
$totalPages = ceil($totalRows / $limit);

$result = $conn->query($sql);

$summarySql = "SELECT 
                COUNT(DISTINCT oi.product_id) as unique_products,
                SUM(oi.quantity) as total_items_sold,
                SUM(oi.total_amount) as total_revenue,
                COUNT(DISTINCT oi.order_id) as total_orders
            FROM order_items oi 
            JOIN order_header oh ON oi.order_id = oh.order_id 
            LEFT JOIN products p ON oi.product_id = p.id 
            LEFT JOIN categories c ON p.category_id = c.id" . $whereClause;

$summaryResult = $conn->query($summarySql);
$summary = [
    'unique_products' => 0,
    'total_items_sold' => 0,
    'total_revenue' => 0,
    'total_orders' => 0
];
if ($summaryResult && $summaryResult->num_rows > 0) {
    $summary = $summaryResult->fetch_assoc();
}
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Product Analysis - Order Management</title>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css">
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/orders.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/customers.css" id="main-style-link" />
</head>

<body>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/loader.php'); ?>

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
                <div class="col-span-12 mb-4">
                    <h2 class="section-title" style="font-size: 16px; font-weight: 600; color: #1f2937; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 2px solid #e5e7eb;">Filters</h2>
                </div>
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
                            <label for="category_filter">Category</label>
                            <select id="category_filter" name="category_filter">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>" <?php echo ($category_filter == $cat['id']) ? 'selected' : ''; ?>>
                                        <?php 
                                        echo $cat['parent_name'] 
                                            ? htmlspecialchars($cat['parent_name'] . ' > ' . $cat['name']) 
                                            : htmlspecialchars($cat['name']); 
                                        ?>
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
                    <h2 class="section-title" style="font-size: 16px; font-weight: 600; color: #1f2937; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 2px solid #e5e7eb;">Analysis Summary</h2>
                </div>

                <div class="grid grid-cols-12 gap-x-6 mb-6">
                    <!-- Unique Products -->
                    <div class="col-span-12 xl:col-span-3 md:col-span-6">
                        <div class="card">
                            <div class="card-header !pb-0 !border-b-0">
                                <h5>Products Sold</h5>
                                <i class="fas fa-box text-blue-500 text-xl"></i>
                            </div>
                            <div class="card-body">
                                <div class="flex items-center justify-between gap-3 flex-wrap">
                                    <h3 class="font-light flex items-center mb-0">
                                        <span class="status-indicator" style="background-color: #3b82f6;"></span>
                                        <?php echo number_format($summary['unique_products'] ?? 0); ?>
                                    </h3>
                                    <p class="mb-0 text-sm text-blue-600">Products</p>
                                </div>
                                <div class="w-full bg-theme-bodybg rounded-lg h-1.5 mt-6 dark:bg-themedark-bodybg">
                                    <div class="bg-blue-500 h-full rounded-lg shadow-[0_10px_20px_0_rgba(0,0,0,0.3)]" role="progressbar" style="width: 100%"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Items Sold -->
                    <div class="col-span-12 xl:col-span-3 md:col-span-6">
                        <div class="card">
                            <div class="card-header !pb-0 !border-b-0">
                                <h5>Total Items Sold</h5>
                                <i class="fas fa-shopping-cart text-purple-500 text-xl"></i>
                            </div>
                            <div class="card-body">
                                <div class="flex items-center justify-between gap-3 flex-wrap">
                                    <h3 class="font-light flex items-center mb-0">
                                        <span class="status-indicator" style="background-color: #8b5cf6;"></span>
                                        <?php echo number_format($summary['total_items_sold'] ?? 0); ?>
                                    </h3>
                                    <p class="mb-0 text-sm text-purple-600">Items</p>
                                </div>
                                <div class="w-full bg-theme-bodybg rounded-lg h-1.5 mt-6 dark:bg-themedark-bodybg">
                                    <div class="bg-purple-500 h-full rounded-lg shadow-[0_10px_20px_0_rgba(0,0,0,0.3)]" role="progressbar" style="width: 100%"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Revenue -->
                    <div class="col-span-12 xl:col-span-3 md:col-span-6">
                        <div class="card">
                            <div class="card-header !pb-0 !border-b-0">
                                <h5>Total Revenue</h5>
                                <i class="fas fa-coins text-green-500 text-xl"></i>
                            </div>
                            <div class="card-body">
                                <div class="flex items-center justify-between gap-3 flex-wrap">
                                    <h3 class="font-light flex items-center mb-0">
                                        <span class="status-indicator" style="background-color: #10b981;"></span>
                                        LKR <?php echo number_format($summary['total_revenue'] ?? 0, 0); ?>
                                    </h3>
                                    <p class="mb-0 text-sm text-green-600">Revenue</p>
                                </div>
                                <div class="w-full bg-theme-bodybg rounded-lg h-1.5 mt-6 dark:bg-themedark-bodybg">
                                    <div class="bg-green-500 h-full rounded-lg shadow-[0_10px_20px_0_rgba(0,0,0,0.3)]" role="progressbar" style="width: 100%"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Total Orders -->
                    <div class="col-span-12 xl:col-span-3 md:col-span-6">
                        <div class="card">
                            <div class="card-header !pb-0 !border-b-0">
                                <h5>Total Orders</h5>
                                <i class="fas fa-file-invoice text-amber-500 text-xl"></i>
                            </div>
                            <div class="card-body">
                                <div class="flex items-center justify-between gap-3 flex-wrap">
                                    <h3 class="font-light flex items-center mb-0">
                                        <span class="status-indicator" style="background-color: #f59e0b;"></span>
                                        <?php echo number_format($summary['total_orders'] ?? 0); ?>
                                    </h3>
                                    <p class="mb-0 text-sm text-amber-600">Orders</p>
                                </div>
                                <div class="w-full bg-theme-bodybg rounded-lg h-1.5 mt-6 dark:bg-themedark-bodybg">
                                    <div class="bg-amber-500 h-full rounded-lg shadow-[0_10px_20px_0_rgba(0,0,0,0.3)]" role="progressbar" style="width: 100%"></div>
                                </div>
                            </div>
                        </div>
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
                                <th>Unit Price</th>
                                <th>Qty Sold</th>
                                <th>Revenue</th>
                                <th>Orders</th>
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
                                        <td style="font-weight: 600; color: #28a745;">
                                            LKR <?php echo number_format($row['lkr_price'] ?? 0, 2); ?>
                                        </td>
                                        <td style="font-weight: 600;">
                                            <?php echo number_format($row['total_quantity'] ?? 0); ?>
                                        </td>
                                        <td style="font-weight: 600; color: #28a745;">
                                            LKR <?php echo number_format($row['total_revenue'] ?? 0, 2); ?>
                                        </td>
                                        <td>
                                            <?php echo number_format($row['order_count'] ?? 0); ?>
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
                                    <td colspan="11" class="text-center" style="padding: 40px; text-align: center; color: #666;">
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