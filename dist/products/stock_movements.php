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

$is_main_admin = (int)($_SESSION['is_main_admin'] ?? 0) === 1;
$session_tenant_id = (int)($_SESSION['tenant_id'] ?? 0);
$canManageAllTenants = $is_main_admin;

// Access control: only main admin users may view stock movements
if (!$is_main_admin) {
    header("Location: /OMS/dist/pages/access_denied.php");
    exit();
}

// Fetch tenants for main admin (company filter) - active only
$tenants = [];
if ($canManageAllTenants) {
    $tRes = $conn->query("SELECT tenant_id, company_name FROM tenants WHERE status = 'active' ORDER BY company_name ASC");
    if ($tRes) {
        while ($row = $tRes->fetch_assoc()) {
            $tenants[] = $row;
        }
    }
}

// ---------- Filters ----------
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$type_filter = isset($_GET['type']) ? trim($_GET['type']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$tenant_filter = isset($_GET['tenant_filter']) ? intval($_GET['tenant_filter']) : 0;

$validTypes = ['grn_in', 'order_out', 'order_cancel_return', 'return_in', 'manual_adjustment'];
if (!in_array($type_filter, $validTypes, true)) {
    $type_filter = '';
}

// Validate date range format (Y-m-d) before using in query
$dateFromSql = '';
$dateToSql = '';
$df = DateTime::createFromFormat('Y-m-d', $date_from);
if ($df && $df->format('Y-m-d') === $date_from) {
    $dateFromSql = $date_from . ' 00:00:00';
} else {
    $date_from = '';
}
$dt = DateTime::createFromFormat('Y-m-d', $date_to);
if ($dt && $dt->format('Y-m-d') === $date_to) {
    $dateToSql = $date_to . ' 23:59:59';
} else {
    $date_to = '';
}

// ---------- Build WHERE ----------
$where = [];

// Tenant scoping: sub-company users see only their own tenant; main admin sees all (or a selected one)
if (!$canManageAllTenants) {
    $where[] = "sm.tenant_id = $session_tenant_id";
} elseif ($tenant_filter > 0) {
    $where[] = "sm.tenant_id = $tenant_filter";
}

if (!empty($search)) {
    $searchTerm = $conn->real_escape_string($search);
    $where[] = "(p.name LIKE '%$searchTerm%' OR p.product_code LIKE '%$searchTerm%' OR b.batch_number LIKE '%$searchTerm%' OR sm.note LIKE '%$searchTerm%')";
}

if (!empty($type_filter)) {
    $where[] = "sm.movement_type = '" . $conn->real_escape_string($type_filter) . "'";
}

if (!empty($dateFromSql)) {
    $where[] = "sm.created_at >= '$dateFromSql'";
}
if (!empty($dateToSql)) {
    $where[] = "sm.created_at <= '$dateToSql'";
}

$whereSql = $where ? " WHERE " . implode(' AND ', $where) : '';

// ---------- Pagination ----------
$limit = 15;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Base FROM clause (shared by count + main query)
$fromSql = " FROM stock_movements sm
            LEFT JOIN products p ON p.id = sm.product_id
            LEFT JOIN batches b ON b.batch_id = sm.batch_id
            LEFT JOIN tenants t ON t.tenant_id = sm.tenant_id
            LEFT JOIN users u ON u.id = sm.user_id" . $whereSql;

// Count total records
$countSql = "SELECT COUNT(*) as total" . $fromSql;
$totalRows = 0;
$countResult = $conn->query($countSql);
if ($countResult && $countResult->num_rows > 0) {
    $totalRows = (int)$countResult->fetch_assoc()['total'];
}
$totalPages = $totalRows > 0 ? (int)ceil($totalRows / $limit) : 1;
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

// Main query
$sql = "SELECT sm.*, p.name AS product_name, p.product_code, b.batch_number, t.company_name, u.name AS user_name" . $fromSql . "
        ORDER BY sm.movement_id DESC
        LIMIT $limit OFFSET $offset";
$result = $conn->query($sql);

// ---------- Movement type labels ----------
$typeLabels = [
    'grn_in'              => ['label' => 'Stock In (GRN)',   'class' => 'mv-grn'],
    'order_out'           => ['label' => 'Stock Out (Order)', 'class' => 'mv-order'],
    'order_cancel_return' => ['label' => 'Cancel Restore',   'class' => 'mv-cancel'],
    'return_in'           => ['label' => 'Return In',        'class' => 'mv-return'],
    'manual_adjustment'   => ['label' => 'Manual Adjustment', 'class' => 'mv-manual'],
];
function movementTypeLabel($type, $typeLabels) {
    return $typeLabels[$type]['label'] ?? ucfirst(str_replace('_', ' ', $type));
}
function movementTypeClass($type, $typeLabels) {
    return $typeLabels[$type]['class'] ?? 'mv-manual';
}
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Stock Movements | <?= htmlspecialchars($_SESSION['company_name'] ?? '') ?></title>

    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>

    <!-- Stylesheets -->
    <link rel="stylesheet" href="../assets/css/orders.css" />
    <link rel="stylesheet" href="../assets/css/customers.css" />
    <style>
        .product-name {
            font-weight: 600;
            color: #2c3e50;
        }
        .qty-pos {
            color: #0f5132;
            background-color: #d1e7dd;
            padding: 4px 10px;
            border-radius: 6px;
            font-weight: 600;
            display: inline-block;
        }
        .qty-neg {
            color: #842029;
            background-color: #f8d7da;
            padding: 4px 10px;
            border-radius: 6px;
            font-weight: 600;
            display: inline-block;
        }
        .mv-badge {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
            white-space: nowrap;
        }
        .mv-grn    { background-color: #d1e7dd; color: #0f5132; }
        .mv-order  { background-color: #f8d7da; color: #842029; }
        .mv-cancel { background-color: #cff4fc; color: #055160; }
        .mv-return { background-color: #ffe8cc; color: #854a0e; }
        .mv-manual { background-color: #e2e3e5; color: #41464b; }
        .batch-number {
            font-family: monospace;
            font-size: 12px;
            color: #555;
        }
        .note-cell {
            max-width: 260px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: #6c757d;
            font-size: 12px;
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
                        <h5 class="mb-0 font-medium">Stock Movements</h5>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="main-content-wrapper">

                <!-- Filter Section -->
                <div class="tracking-container">
                    <form class="tracking-form" method="GET" action="">
                        <div class="form-group" style="flex: 1;">
                            <label for="search">Search</label>
                            <input type="text" id="search" name="search"
                                   placeholder="Product name, code, batch number or note"
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>

                        <div class="form-group">
                            <label for="type">Movement Type</label>
                            <select id="type" name="type">
                                <option value="">All Types</option>
                                <?php foreach ($typeLabels as $key => $meta): ?>
                                    <option value="<?php echo $key; ?>" <?php echo ($type_filter === $key) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($meta['label']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if ($canManageAllTenants): ?>
                        <div class="form-group">
                            <label for="tenant_filter">Tenant Company</label>
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

                        <div class="form-group">
                            <label for="date_from">Date From</label>
                            <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>

                        <div class="form-group">
                            <label for="date_to">Date To</label>
                            <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
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

                <!-- Count Display -->
                <div class="order-count-container">
                    <div class="order-count-number"><?php echo number_format($totalRows); ?></div>
                    <div class="order-count-dash">-</div>
                    <div class="order-count-subtitle">Total Movements</div>
                </div>

                <!-- Movements Table -->
                <div class="table-wrapper">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>Date / Time</th>
                                <th>Product</th>
                                <th>Batch</th>
                                <th>Type</th>
                                <th>Qty</th>
                                <?php if ($canManageAllTenants): ?>
                                <th>Company</th>
                                <?php endif; ?>
                                <th>Reference</th>
                                <th>User</th>
                                <th>Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <?php
                                        $qty = (int)$row['qty_change'];
                                        $qtyClass = $qty >= 0 ? 'qty-pos' : 'qty-neg';
                                        $qtyText = ($qty >= 0 ? '+' : '') . $qty;
                                        $refText = '-';
                                        if (!empty($row['reference_type'])) {
                                            $refText = ucfirst($row['reference_type']) . ' #' . $row['reference_id'];
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <div style="font-size: 13px;">
                                                <?php echo date('Y-m-d', strtotime($row['created_at'])); ?>
                                                <br>
                                                <small style="color: #6c757d;"><?php echo date('h:i:s A', strtotime($row['created_at'])); ?></small>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="product-name"><?php echo htmlspecialchars($row['product_name'] ?? 'Deleted product #' . $row['product_id']); ?></div>
                                            <small style="color: #6c757d;"><?php echo htmlspecialchars($row['product_code'] ?? ''); ?></small>
                                        </td>
                                        <td>
                                            <?php if (!empty($row['batch_number'])): ?>
                                                <span class="batch-number"><?php echo htmlspecialchars($row['batch_number']); ?></span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="mv-badge <?php echo movementTypeClass($row['movement_type'], $typeLabels); ?>">
                                                <?php echo htmlspecialchars(movementTypeLabel($row['movement_type'], $typeLabels)); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="<?php echo $qtyClass; ?>"><?php echo $qtyText; ?></span>
                                        </td>
                                        <?php if ($canManageAllTenants): ?>
                                        <td><?php echo htmlspecialchars($row['company_name'] ?? '-'); ?></td>
                                        <?php endif; ?>
                                        <td style="font-size: 13px;"><?php echo htmlspecialchars($refText); ?></td>
                                        <td style="font-size: 13px;"><?php echo htmlspecialchars($row['user_name'] ?? ($row['user_id'] ? '#' . $row['user_id'] : '-')); ?></td>
                                        <td class="note-cell" title="<?php echo htmlspecialchars($row['note'] ?? ''); ?>">
                                            <?php echo htmlspecialchars($row['note'] ?? '-'); ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="<?php echo $canManageAllTenants ? 9 : 8; ?>" style="padding: 40px; text-align: center; color: #666;">
                                        <i class="fas fa-exchange-alt" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                                        No stock movements found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Controls -->
                <?php if ($totalRows > 0): ?>
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
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php'); ?>

    <!-- Scripts -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php'); ?>

    <script>
        function clearFilters() {
            window.location.href = 'stock_movements.php';
        }
    </script>

</body>
</html>
