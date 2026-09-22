<?php
/**
 * Sales View - Order Management System
 * Displays status summary stat cards (Total, Pending, Dispatched, Returned, Delivered)
 * with courier and date filters.
 */

// Start session management
session_start();

// Authentication check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Check if user is main admin
$is_main_admin = $_SESSION['is_main_admin'] ?? 0;
$tenant_id = $_SESSION['tenant_id'] ?? 0;

// Get current user's role information
$current_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$current_user_role = isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : 0;

// If user_id or role_id is not in session, fetch from database
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

// If still no user data, redirect to login
if ($current_user_id == 0) {
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

// Role-based tenant scoping
$roleBasedCondition = "";
if (!($is_main_admin == 1 && $current_user_role == 1)) {
    $roleBasedCondition = " AND i.tenant_id = $tenant_id";
}

// Filter parameters
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$tenant_id_filter = isset($_GET['tenant_id_filter']) ? trim($_GET['tenant_id_filter']) : '';

$searchConditions = [];

// Date range filters
if (!empty($date_from)) {
    $dateFromTerm = $conn->real_escape_string($date_from);
    $searchConditions[] = "DATE(i.issue_date) >= '$dateFromTerm'";
}
if (!empty($date_to)) {
    $dateToTerm = $conn->real_escape_string($date_to);
    $searchConditions[] = "DATE(i.issue_date) <= '$dateToTerm'";
}

// Tenant filter for main admin
if ($is_main_admin == 1 && $current_user_role == 1 && !empty($tenant_id_filter)) {
    $tenantFilterTerm = (int)$tenant_id_filter;
    $searchConditions[] = "i.tenant_id = $tenantFilterTerm";
}

$filterSql = !empty($searchConditions) ? " AND " . implode(' AND ', $searchConditions) : "";

// Fetch tenants for filter dropdown (main admin only)
$tenants = [];
if ($is_main_admin == 1 && $current_user_role == 1) {
    $tenantsQuery = "SELECT tenant_id, tenant_name FROM tenants WHERE status = 'active' ORDER BY tenant_name ASC";
    $tenantsResult = $conn->query($tenantsQuery);
    if ($tenantsResult && $tenantsResult->num_rows > 0) {
        while ($t = $tenantsResult->fetch_assoc()) {
            $tenants[] = $t;
        }
    }
}

// Status summary query
$summarySql = "SELECT 
               COUNT(*) AS total_orders,
               COALESCE(SUM(i.total_amount), 0) AS total_value,
               COUNT(CASE WHEN i.status = 'pending' THEN 1 END) AS pending_count,
               COALESCE(SUM(CASE WHEN i.status = 'pending' THEN i.total_amount END), 0) AS pending_value,
               COUNT(CASE WHEN i.status = 'dispatch' THEN 1 END) AS dispatch_count,
               COALESCE(SUM(CASE WHEN i.status = 'dispatch' THEN i.total_amount END), 0) AS dispatch_value,
               COUNT(CASE WHEN i.status IN ('return_handover', 'return', 'return pending', 'return transfer', 'return complete') THEN 1 END) AS return_count,
               COALESCE(SUM(CASE WHEN i.status IN ('return_handover', 'return', 'return pending', 'return transfer', 'return complete') THEN i.total_amount END), 0) AS return_value,
               COUNT(CASE WHEN i.status IN ('delivered', 'done') THEN 1 END) AS delivered_count,
               COALESCE(SUM(CASE WHEN i.status IN ('delivered', 'done') THEN i.total_amount END), 0) AS delivered_value
               FROM order_header i
               LEFT JOIN customers c ON i.customer_id = c.customer_id
               WHERE i.status != 'cancel' $roleBasedCondition $filterSql";

$summaryResult = $conn->query($summarySql);
$summary = $summaryResult ? $summaryResult->fetch_assoc() : [
    'total_orders' => 0, 'total_value' => 0,
    'pending_count' => 0, 'pending_value' => 0,
    'dispatch_count' => 0, 'dispatch_value' => 0,
    'return_count' => 0, 'return_value' => 0,
    'delivered_count' => 0, 'delivered_value' => 0,
];
?>
<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Sales View | <?= htmlspecialchars($_SESSION['tenant_name'] ?? '') ?></title>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>
    <link rel="stylesheet" href="../assets/css/orders.css" />
    <style>
        /* Status indicators (match dashboard stat cards) */
        .status-indicator {
            display: inline-block;
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 50%;
            margin-right: 0.375rem;
        }
        .status-total { background-color: #3b82f6; }
        .status-pending { background-color: #f59e0b; }
        .status-dispatch { background-color: #3b82f6; }
        .status-return { background-color: #8b5cf6; }
        .status-delivered { background-color: #10b981; }
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
        <div class="page-header">
            <div class="page-block">
                <div class="page-header-title">
                    <h5 class="mb-0 font-medium">Sales View</h5>
                </div>
            </div>
        </div>

        <div class="main-content-wrapper">

            <!-- Filters -->
            <div class="tracking-container">
                <form class="tracking-form" method="GET">
                    <?php if ($is_main_admin == 1 && $current_user_role == 1): ?>
                    <div class="form-group">
                        <label for="tenant_id_filter">Tenant</label>
                        <select id="tenant_id_filter" name="tenant_id_filter">
                            <option value="">All Tenants</option>
                            <?php foreach ($tenants as $t): ?>
                                <option value="<?= $t['tenant_id'] ?>" <?= ($tenant_id_filter == $t['tenant_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($t['tenant_name'] ?? 'Tenant ' . $t['tenant_id']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="date_from">Date From</label>
                        <input type="date" id="date_from" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                    </div>
                    <div class="form-group">
                        <label for="date_to">Date To</label>
                        <input type="date" id="date_to" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                    </div>
                    <div class="form-group">
                        <div class="button-group">
                            <button type="submit" class="search-btn">
                                <i class="fas fa-search"></i>
                                Search
                            </button>
                            <button type="button" class="search-btn" onclick="window.location.href='sales_view.php'" style="background: #6c757d;">
                                <i class="fas fa-times"></i>
                                Clear
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Status Summary Stat Cards -->
            <div class="grid grid-cols-12 gap-x-6">

                <!-- Total -->
                <div class="col-span-12 xl:col-span-4 md:col-span-6">
                    <div class="card">
                        <div class="card-header flex items-center justify-between !pb-0 !border-b-0">
                            <h5>Total</h5>
                            <i class="fas fa-boxes text-blue-500 text-xl"></i>
                        </div>
                        <div class="card-body">
                            <div class="flex items-center justify-between gap-3 flex-wrap">
                                <h3 class="font-light flex items-center mb-0">
                                    <span class="status-indicator status-total"></span>
                                    <?= number_format($summary['total_orders']) ?>
                                </h3>
                                <p class="mb-0 text-sm text-blue-600\"><?= number_format($summary['total_value'], 2) ?></p>
                            </div>
                            <div class="w-full bg-theme-bodybg rounded-lg h-1.5 mt-6 dark:bg-themedark-bodybg">
                                <div class="bg-blue-500 h-full rounded-lg shadow-[0_10px_20px_0_rgba(0,0,0,0.3)]" role="progressbar"
                                    style="width: 100%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pending -->
                <div class="col-span-12 xl:col-span-4 md:col-span-6">
                    <div class="card">
                        <div class="card-header flex items-center justify-between !pb-0 !border-b-0">
                            <h5>Pending</h5>
                            <i class="fas fa-clock text-yellow-500 text-xl"></i>
                        </div>
                        <div class="card-body">
                            <div class="flex items-center justify-between gap-3 flex-wrap">
                                <h3 class="font-light flex items-center mb-0">
                                    <span class="status-indicator status-pending"></span>
                                    <?= number_format($summary['pending_count']) ?>
                                </h3>
                                <p class="mb-0 text-sm text-yellow-600\"><?= number_format($summary['pending_value'], 2) ?></p>
                            </div>
                            <div class="w-full bg-theme-bodybg rounded-lg h-1.5 mt-6 dark:bg-themedark-bodybg">
                                <div class="bg-yellow-500 h-full rounded-lg shadow-[0_10px_20px_0_rgba(0,0,0,0.3)]" role="progressbar"
                                    style="width: <?= $summary['total_orders'] > 0 ? ($summary['pending_count'] / $summary['total_orders']) * 100 : 0 ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Dispatched -->
                <div class="col-span-12 xl:col-span-4 md:col-span-6">
                    <div class="card">
                        <div class="card-header flex items-center justify-between !pb-0 !border-b-0">
                            <h5>Dispatched</h5>
                            <i class="fas fa-truck text-blue-500 text-xl"></i>
                        </div>
                        <div class="card-body">
                            <div class="flex items-center justify-between gap-3 flex-wrap">
                                <h3 class="font-light flex items-center mb-0">
                                    <span class="status-indicator status-dispatch"></span>
                                    <?= number_format($summary['dispatch_count']) ?>
                                </h3>
                                <p class="mb-0 text-sm text-blue-600\"><?= number_format($summary['dispatch_value'], 2) ?></p>
                            </div>
                            <div class="w-full bg-theme-bodybg rounded-lg h-1.5 mt-6 dark:bg-themedark-bodybg">
                                <div class="bg-blue-500 h-full rounded-lg shadow-[0_10px_20px_0_rgba(0,0,0,0.3)]" role="progressbar"
                                    style="width: <?= $summary['total_orders'] > 0 ? ($summary['dispatch_count'] / $summary['total_orders']) * 100 : 0 ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Returned -->
                <div class="col-span-12 xl:col-span-4 md:col-span-6">
                    <div class="card">
                        <div class="card-header flex items-center justify-between !pb-0 !border-b-0">
                            <h5>Returned</h5>
                            <i class="fas fa-undo text-purple-500 text-xl"></i>
                        </div>
                        <div class="card-body">
                            <div class="flex items-center justify-between gap-3 flex-wrap">
                                <h3 class="font-light flex items-center mb-0">
                                    <span class="status-indicator status-return"></span>
                                    <?= number_format($summary['return_count']) ?>
                                </h3>
                                <p class="mb-0 text-sm text-purple-600\"><?= number_format($summary['return_value'], 2) ?></p>
                            </div>
                            <div class="w-full bg-theme-bodybg rounded-lg h-1.5 mt-6 dark:bg-themedark-bodybg">
                                <div class="bg-purple-500 h-full rounded-lg shadow-[0_10px_20px_0_rgba(0,0,0,0.3)]" role="progressbar"
                                    style="width: <?= $summary['total_orders'] > 0 ? ($summary['return_count'] / $summary['total_orders']) * 100 : 0 ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Delivered -->
                <div class="col-span-12 xl:col-span-4 md:col-span-6">
                    <div class="card">
                        <div class="card-header flex items-center justify-between !pb-0 !border-b-0">
                            <h5>Delivered</h5>
                            <i class="fas fa-check-circle text-teal-500 text-xl"></i>
                        </div>
                        <div class="card-body">
                            <div class="flex items-center justify-between gap-3 flex-wrap">
                                <h3 class="font-light flex items-center mb-0">
                                    <span class="status-indicator status-delivered"></span>
                                    <?= number_format($summary['delivered_count']) ?>
                                </h3>
                                <p class="mb-0 text-sm text-teal-600\"><?= number_format($summary['delivered_value'], 2) ?></p>
                            </div>
                            <div class="w-full bg-theme-bodybg rounded-lg h-1.5 mt-6 dark:bg-themedark-bodybg">
                                <div class="bg-teal-500 h-full rounded-lg shadow-[0_10px_20px_0_rgba(0,0,0,0.3)]" role="progressbar"
                                    style="width: <?= $summary['total_orders'] > 0 ? ($summary['delivered_count'] / $summary['total_orders']) * 100 : 0 ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

        </div>
    </div>
</div>

<!-- Footer -->
<?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php'); ?>
<?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php'); ?>
</body>
</html>
