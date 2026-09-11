<?php
/**
 * Pending Orders Management System
 * This page displays orders with status 'pending' for individual interface
 * Includes search, pagination, and modal view functionality
 */

// Start session management
session_start();

// Authentication check - redirect if not logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // Clear output buffers before redirect
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /orderhub_nextwave/dist/pages/login.php");
    exit();
}

// Check if user is main admin
$is_main_admin = $_SESSION['is_main_admin'] ?? 0;
$tenant_id = $_SESSION['tenant_id'] ?? 0;
$is_admin = $_SESSION['role_id'] ?? 0;

// NEW: Get current user's role information
$current_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$current_user_role = isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : 0;

// If user_id or role_id is not in session, fetch from database
if ($current_user_id == 0 || $current_user_role == 0) {
    // Try to get user info from session username or email
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
            
            // Update session with missing data
            $_SESSION['user_id'] = $current_user_id;
            $_SESSION['role_id'] = $current_user_role;
        }
        $stmt->close();
    }
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/connection/db_connection.php');

/**
 * SEARCH AND PAGINATION PARAMETERS
 */
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$order_id_filter = isset($_GET['order_id_filter']) ? trim($_GET['order_id_filter']) : '';
$customer_name_filter = isset($_GET['customer_name_filter']) ? trim($_GET['customer_name_filter']) : '';
$phone_filter = isset($_GET['phone_filter']) ? trim($_GET['phone_filter']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$pay_status_filter = isset($_GET['pay_status_filter']) ? trim($_GET['pay_status_filter']) : '';
$call_status_filter = isset($_GET['call_status_filter']) ? trim($_GET['call_status_filter']) : '';
$tenant_id_filter = isset($_GET['tenant_id_filter']) ? trim($_GET['tenant_id_filter']) : '';
$condition_filter = isset($_GET['condition_filter']) ? $_GET['condition_filter'] : '';
$user_id_filter = isset($_GET['user_id_filter']) ? trim($_GET['user_id_filter']) : '';

// AFTER (Fixed Code):
$tenant_id_filter = isset($_GET['tenant_id_filter']) ? trim($_GET['tenant_id_filter']) : '';
// Determine if tenant filter is active
$show_checkboxes = (!(($is_admin == 1) && $is_main_admin)) || !empty($tenant_id_filter);



$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Role-based access control condition: Only Main Admin with role 1 can see all tenants
$roleBasedCondition = "";
if (!($is_main_admin == 1 && $current_user_role == 1)) {
    // Users who are not (is_main_admin = 1 AND role_id = 1) can only see their tenant's orders
    $roleBasedCondition = " AND i.tenant_id = $tenant_id";
}

/**
 * DATABASE QUERIES
 * Main query to fetch orders with customer and payment information
 * Filtered for individual interface and pending status only
 */

// Base SQL for counting total records
$countSql = "SELECT COUNT(*) as total FROM order_header i 
             WHERE i.interface IN ('individual', 'leads') 
             AND i.status = 'pending'$roleBasedCondition";

// Main query with all required joins
// Main query with all required joins - UPDATED to fetch customer name from customers table as fallback
$sql = "SELECT i.*,
                -- Count duplicates based on mobile and product_code
                (SELECT COUNT(*) FROM order_header o2 WHERE o2.mobile = i.mobile AND o2.product_code = i.product_code AND  o2.status = 'pending') as duplicate_count,
               -- Customer info: Use order_header full_name, fallback to customers table
               COALESCE(NULLIF(i.full_name, ''), c.name) as customer_name,
               i.customer_id,
               
               -- Payment information
               p.payment_id, 
               p.amount_paid, 
               p.payment_method, 
               p.payment_date, 
               p.pay_by,
               u1.name as paid_by_name,
               t.company_name,
               
               -- User who created the order
               u2.name as creator_name,
               
               -- Order details
               i.slip as payment_slip,
               i.pay_status,
               i.created_at,
               i.call_log,
               i.upload_error
        FROM order_header i 
        LEFT JOIN payments p ON i.order_id = p.order_id
        LEFT JOIN users u1 ON p.pay_by = u1.id
        LEFT JOIN users u2 ON i.created_by = u2.id
        LEFT JOIN customers c ON i.customer_id = c.customer_id
        LEFT JOIN tenants t ON i.tenant_id = t.tenant_id
        WHERE i.interface IN ('individual', 'leads') 
        AND i.status = 'pending'$roleBasedCondition";


// Add tenant filter for non-main admin users
if ($is_main_admin == 1){
// Add ordering and pagination

} else {
    $sql .= " AND i.tenant_id = $tenant_id ";
}


// Build search conditions
$searchConditions = [];

// General search condition - UPDATED to use order_header fields
if (!empty($search)) {
    $searchTerm = $conn->real_escape_string($search);
    $searchConditions[] = "(
                        i.order_id LIKE '%$searchTerm%' OR 
                        i.full_name LIKE '%$searchTerm%' OR 
                        i.issue_date LIKE '%$searchTerm%' OR 
                        i.due_date LIKE '%$searchTerm%' OR 
                        i.total_amount LIKE '%$searchTerm%' OR
                        i.pay_status LIKE '%$searchTerm%' OR
                        t.company_name LIKE '%$searchTerm%' OR
                        u2.name LIKE '%$searchTerm%')";
}

// Specific Order ID filter
if (!empty($order_id_filter)) {
    $orderIdTerm = $conn->real_escape_string($order_id_filter);
    $searchConditions[] = "i.order_id = '$orderIdTerm'";
}

// Specific Customer Name filter - UPDATED
if (!empty($customer_name_filter)) {
    $customerNameTerm = $conn->real_escape_string($customer_name_filter);
    $searchConditions[] = "i.full_name LIKE '%$customerNameTerm%'";
}

// Customer Phone filter
if (!empty($phone_filter)) {
    $phoneTerm = $conn->real_escape_string($phone_filter);
    $searchConditions[] = "i.mobile = '$phoneTerm'";
}

// Date range filter
if (!empty($date_from)) {
    $dateFromTerm = $conn->real_escape_string($date_from);
    $searchConditions[] = "DATE(i.issue_date) >= '$dateFromTerm'";
}

if (!empty($date_to)) {
    $dateToTerm = $conn->real_escape_string($date_to);
    $searchConditions[] = "DATE(i.issue_date) <= '$dateToTerm'";
}

// Payment Status filter
if (!empty($pay_status_filter)) {
    $payStatusTerm = $conn->real_escape_string($pay_status_filter);
    $searchConditions[] = "i.pay_status = '$payStatusTerm'";
}

// Call Answer filter
if (!empty($call_status_filter !== '')) {
    $callStatusTerm = $conn->real_escape_string($call_status_filter);
    $searchConditions[] = "i.call_log = '$callStatusTerm'";
}

// Success Rate filter
if ($condition_filter !== '') {
    $conditionTerm = (int)$condition_filter;
    $searchConditions[] = "i.condition = $conditionTerm";
}

// Specific tenant ID filter
if (!empty($tenant_id_filter)) {
    $tenantIdTerm = (int)$tenant_id_filter;
    $searchConditions[] = "i.tenant_id = $tenantIdTerm";
}

//Specific User ID filter - MODIFIED: Apply role-based restrictions
if (!empty($user_id_filter)) {
    $userIdTerm = $conn->real_escape_string($user_id_filter);
    if ($current_user_role == 1) {
        // Admin can filter by any user
        $searchConditions[] = "i.user_id = '$userIdTerm'";
    } else {
        // Non-admin can only filter by their own user ID
        if ($userIdTerm == $current_user_id) {
            $searchConditions[] = "i.user_id = '$userIdTerm'";
        }
    }
}

// Apply search conditions
if (!empty($searchConditions)) {
    $finalSearchCondition = " AND (" . implode(' AND ', $searchConditions) . ")";
    $countSql .= $finalSearchCondition;
    $sql .= $finalSearchCondition;
}

// Add ordering and pagination
$sql .= " ORDER BY i.order_id DESC LIMIT $limit OFFSET $offset";

// Execute queries
$countResult = $conn->query($countSql);
$totalRows = 0;
if ($countResult && $countResult->num_rows > 0) {
    $totalRows = $countResult->fetch_assoc()['total'];
}
$totalPages = ceil($totalRows / $limit);
$result = $conn->query($sql);




// Fetch all users for the User ID dropdown based on permissions
if ($is_main_admin == 1 && $current_user_role == 1) {
    $usersQuery = "SELECT id, name FROM users ORDER BY name ASC";
} else {
    $usersQuery = "SELECT id, name FROM users WHERE tenant_id = " . (int)$tenant_id . " ORDER BY name ASC";
}
$usersResult = $conn->query($usersQuery);


// Get unique tenants for filter dropdown
$tenant_sql = "SELECT DISTINCT tenant_id, company_name 
               FROM tenants
               WHERE status = 'active'";
$tenant_result = $conn->query($tenant_sql);
$tenants = $tenant_result->fetch_all(MYSQLI_ASSOC);

?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr"
    data-pc-theme="light">

<head>
    <title>Pending Orders | <?= htmlspecialchars($_SESSION['company_name'] ?? '') ?></title>

    <?php include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/include/head.php'); ?>

    <!-- Stylesheets -->
    <link rel="stylesheet" href="../assets/css/orders.css" />
    <style>
        /* NEW: Issue Date Styling */
.issued-time {
    font-size: 0.9em;
    color: #333;
    line-height: 1.2;
}

.issued-date {
    display: block;
    font-weight: 600;
}

.issued-time-only {
    display: block;
    color: #666;
    font-size: 0.85em;
}
    .updated-time {
        font-size: 0.9em;
        color: #333;
        line-height: 1.2;
    }
    .updated-date {
        display: block;
        font-weight: 600;
    }
    .updated-time-only {
        display: block;
        color: #666;
        font-size: 0.85em;
    }
    .print-btn {
        background-color: #28a745;
        color: white;
        border: none;
        padding: 8px 10px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
        margin-left: 5px;
        transition: background-color 0.3s;
    }

    .print-btn:hover {
        background-color: #218838;
    }

    .print-btn:active {
        transform: scale(0.95);
    }

    .actions {
        white-space: nowrap;
    }
    @media (max-width: 480px) {
        .actions {
            white-space: normal;
        }
    }

/* Info Box - Clean minimal alert matching system design */
.info-box {
    background: #e8f4fd;
    border: 1px solid #bee5eb;
    border-radius: 8px;
    padding: 14px 18px;
    margin-bottom: 18px;
    color: #0c5460;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 10px;
    line-height: 1.5;
}

.info-box i {
    font-size: 16px;
    flex-shrink: 0;
}

.info-box--warning {
    background: #fff3cd;
    border-color: #ffeaa7;
    color: #856404;
}
    </style>
</head>

<body>
    <!-- Page Loader -->
    <?php 
    include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/include/loader.php');
    include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/include/navbar.php');
    include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/include/sidebar.php');
    ?>

    <div class="pc-container">
        <div class="pc-content">

            <!-- Page Header -->
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">Pending Orders</h5>
                    </div>
                </div>
            </div>

            <!-- Session Alerts Container (toasts shown via JS after toastManager initializes) -->
            <?php
            // Store session alerts in JS variables for toast display
            $sessionAlerts = [];
            if (isset($_SESSION['order_success'])) {
                $sessionAlerts[] = ['type' => 'success', 'message' => $_SESSION['order_success']];
                unset($_SESSION['order_success']);
            }
            if (isset($_SESSION['order_error'])) {
                $sessionAlerts[] = ['type' => 'error', 'message' => $_SESSION['order_error']];
                unset($_SESSION['order_error']);
            }
            if (isset($_SESSION['order_warning'])) {
                $sessionAlerts[] = ['type' => 'warning', 'message' => $_SESSION['order_warning']];
                unset($_SESSION['order_warning']);
            }
            if (isset($_SESSION['order_info'])) {
                $sessionAlerts[] = ['type' => 'info', 'message' => $_SESSION['order_info']];
                unset($_SESSION['order_info']);
            }
            ?>
            <script>
                // Show session alerts as toasts after page loads
                document.addEventListener('DOMContentLoaded', function() {
                    var sessionAlerts = <?php echo json_encode($sessionAlerts); ?>;
                    sessionAlerts.forEach(function(alert) {
                        if (typeof toastManager !== 'undefined') {
                            toastManager[alert.type](alert.message);
                        }
                    });
                });
            </script>

            <div class="main-content-wrapper">

                <!-- Order Tracking and Filter Section -->
                <div class="tracking-container">
                    <form class="tracking-form" method="GET" action="">
                        <div class="form-group">
                            <label for="order_id_filter">Order ID</label>
                            <input type="text" id="order_id_filter" name="order_id_filter" placeholder="Enter order ID"
                                value="<?php echo htmlspecialchars($order_id_filter); ?>">
                        </div>

                        <div class="form-group">
                            <label for="customer_name_filter">Customer Name</label>
                            <input type="text" id="customer_name_filter" name="customer_name_filter"
                                placeholder="Enter customer name"
                                value="<?php echo htmlspecialchars($customer_name_filter); ?>">
                        </div>

                        <div class="form-group">
                            <label for="phone_filter">Customer Phone</label>
                            <input type="text" id="phone_filter" name="phone_filter"
                                placeholder="Enter phone number"
                                value="<?php echo htmlspecialchars($phone_filter); ?>">
                        </div>

                        <div class="form-group">
                            <label for="pay_status_filter">Payment Status</label>
                            <select id="pay_status_filter" name="pay_status_filter">
                                <option value="">All Payment Status</option>
                                <option value="paid" <?php echo ($pay_status_filter == 'paid') ? 'selected' : ''; ?>>
                                    Paid</option>
                                <option value="unpaid"
                                    <?php echo ($pay_status_filter == 'unpaid') ? 'selected' : ''; ?>>Unpaid</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="call_status_filter">Call Answer</label>
                            <select id="call_status_filter" name="call_status_filter">
                                <option value="">Call Answer Status</option>
                                <option value="0" <?php echo ($call_status_filter == '0') ? 'selected' : ''; ?>>No Answer</option>
                                <option value="1" <?php echo ($call_status_filter == '1') ? 'selected' : ''; ?>>Answer</option>
                                
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="condition_filter">Success Rate</label>
                            <select id="condition_filter" name="condition_filter">
                                <option value="">All Success Rates</option>
                                <option value="0" <?php echo ($condition_filter === '0') ? 'selected' : ''; ?>>Excellent</option>
                                <option value="1" <?php echo ($condition_filter === '1') ? 'selected' : ''; ?>>Good</option>
                                <option value="2" <?php echo ($condition_filter === '2') ? 'selected' : ''; ?>>Average</option>
                                <option value="3" <?php echo ($condition_filter === '3') ? 'selected' : ''; ?>>Bad</option>
                                <option value="4" <?php echo ($condition_filter === '4') ? 'selected' : ''; ?>>New</option>
                            </select>
                        </div>

                        <?php if (($is_admin == 1) && $is_main_admin) { ?>
                        <div class="form-group">
                            <label for="tenant_id_filter">Tenant</label>
                            <select id="tenant_id_filter" name="tenant_id_filter">
                                <option value="">All Companies</option>
                                <?php foreach ($tenants as $tenant): ?>
                                <option value="<?php echo htmlspecialchars($tenant['tenant_id']); ?>"
                                    <?php echo $tenant_id_filter == $tenant['tenant_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($tenant['company_name'] ? $tenant['company_name'] : 'Company ' . $tenant['tenant_id']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php } ?>

                        <!-- User ID Filter - Only show for admin users -->
                        <?php if ($current_user_role == 1): ?>
                        <div class="form-group">
                            <label for="user_id_filter">User</label>
                            <select id="user_id_filter" name="user_id_filter">
                                <option value="">All Users</option>
                                <?php if ($usersResult && $usersResult->num_rows > 0): ?>
                                <?php while ($userRow = $usersResult->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($userRow['id']); ?>"
                                    <?php echo ($user_id_filter == $userRow['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($userRow['name']) . ' (ID: ' . $userRow['id'] . ')'; ?>
                                </option>
                                <?php endwhile; ?>
                                <?php endif; ?>
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
                                <button type="button" class="search-btn" onclick="clearFilters()"
                                    style="background: #6c757d;">
                                    <i class="fas fa-times"></i>
                                    Clear
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Order Count Display -->
                <div class="order-count-container">
                    <div class="order-count-number"><?php echo number_format($totalRows); ?></div>
                    <div class="order-count-dash">-</div>
                    <div class="order-count-subtitle">Total Orders</div>
                </div>

                <!-- Orders Table with Bulk Selection -->
                <div class="table-wrapper">
                  <!-- Bulk Actions Bar -->
                    <?php if ($show_checkboxes): ?>
                    <div class="bulk-actions-bar" id="bulkActionsBar" style="display: none;">
                        <div class="bulk-actions-left">
                            <span id="selectedCount">0</span> orders selected
                        </div>
                        <div class="bulk-actions-right">
                            <button class="bulk-btn bulk-dispatch-btn" onclick="bulkMarkAsDispatched()">
                                <i class="fas fa-truck"></i> Mark as Dispatched
                            </button>
                            <button class="bulk-btn bulk-api-dispatch-btn" onclick="openApiDispatchModal()">
                                <i class="fas fa-cloud"></i> API Dispatch
                            </button>
                            <button class="bulk-btn bulk-clear-btn" onclick="clearBulkSelection()">
                                <i class="fas fa-times"></i> Clear Selection
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>

                    <table class="orders-table">
                        <thead>
                            <tr>
                                <?php if ($show_checkboxes): ?>
                                <th>
                                    <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                                </th>
                                <?php endif; ?>
                                <th>Order ID</th>
                                <th>Issue Date</th>
                                <th>Updated Time</th>
                                <th>Customer Name</th>
                                <th>Total Amount</th>
                                <th>Success Rate</th>
                                <th>Call note</th>
                                <?php if ($is_main_admin == 1 && $_SESSION['role_id'] == 1) { ?>
                                <th>Tenant</th>
                                <?php } ?>
                                <th>Processed By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="ordersTableBody">
                            <?php if ($result && $result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr data-tenant-id="<?php echo isset($row['tenant_id']) ? (int)$row['tenant_id'] : 0; ?>">
                                <!-- Bulk Selection Checkbox -->
                              <?php if ($show_checkboxes): ?>
                                <td>
                                    <input type="checkbox" class="order-checkbox"
                                        value="<?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>"
                                        onchange="updateBulkSelection()"
                                        <?php echo !empty($row['upload_error']) ? 'disabled' : ''; ?>>
                                </td>
                                <?php endif; ?>

                                <!-- Order ID -->
                                <td class="order-id">
                                    <?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>
                                    <?php include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/include/leads_badge.php'); ?>
                                    <?php if (!empty($row['upload_error'])): ?>
                                        <br>
                                        <span class="badge bg-warning text-dark" style="font-size: 10px; cursor: help;" title="<?php echo htmlspecialchars($row['upload_error']); ?>">
                                            <i class="fas fa-exclamation-triangle"></i> Error
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- NEW: Issue Date Column -->
                                        <td class="issued-time">
                                            <?php
                                            if (isset($row['created_at']) && !empty($row['created_at'])) {
                                                $createdAt = new DateTime($row['created_at']);
                                                echo '<span class="issued-date">' . $createdAt->format('Y-m-d') . '</span>';
                                                echo '<span class="issued-time-only">' . $createdAt->format('h:i:s A') . '</span>';
                                            } else {
                                                echo '<span style="color: #999; font-style: italic;">N/A</span>';
                                            }
                                            ?>
                                        </td>
                                        
                                <!-- Updated Time -->
                                <td class="updated-time">
                                    <?php
                                        if (isset($row['updated_at']) && !empty($row['updated_at'])) {
                                            $updatedAt = new DateTime($row['updated_at']);
                                            echo '<span class="updated-date">' . $updatedAt->format('Y-m-d') . '</span>';
                                            echo '<span class="updated-time-only">' . $updatedAt->format('h:i:s A') . '</span>';
                                        } else {
                                            echo '<span style="color: #999; font-style: italic;">N/A</span>';
                                        }
                                        ?>
                                </td>

                                <!-- Customer Name with ID -->
                                <td class="customer-name">
                                    <?php
    $customerName = isset($row['customer_name']) ? htmlspecialchars($row['customer_name']) : 'N/A';
    $customerId = isset($row['customer_id']) ? htmlspecialchars($row['customer_id']) : '';
    echo $customerName . ($customerId ? " ($customerId)" : "");

    if (isset($row['duplicate_count']) && $row['duplicate_count'] > 1) {
        echo '<br><span class="badge badge-danger" style="background-color: #dc3545; color: white; padding: 2px 6px; border-radius: 4px; font-size: 11px; margin-top: 4px; display: inline-block;">Duplicate (' . $row['duplicate_count'] . ')</span>';
    }
    ?>


                                <!-- Total Amount with Currency -->
                                <td class="amount">
                            <?php
                            $amount = isset($row['total_amount']) ? (float)$row['total_amount'] : 0;
                            $currency = isset($row['currency']) ? $row['currency'] : 'lkr';
                            $currencySymbol = ($currency == 'usd') ? '$' : 'Rs';
                            echo $currencySymbol . number_format($amount, 2);
                            
                            $payStatus = isset($row['pay_status']) ? $row['pay_status'] : 'unpaid';
                            if ($payStatus == 'paid'): ?>
                                <br><span class="status-badge pay-status-paid">Paid</span>
                            <?php else: ?>
                                <br><span class="status-badge pay-status-unpaid">Unpaid</span>
                            <?php endif; ?>
                        </td>

                                <!-- Success Rate Badge -->
                                <td>
                                    <?php
                                    $condition = isset($row['condition']) ? (int)$row['condition'] : 0;
                                    switch ($condition) {
                                        case 0:
                                            echo '<span class="status-badge rate-excellent">Excellent</span>';
                                            break;
                                        case 1:
                                            echo '<span class="status-badge rate-good">Good</span>';
                                            break;
                                        case 2:
                                            echo '<span class="status-badge rate-average">Average</span>';
                                            break;
                                        case 3:
                                            echo '<span class="status-badge rate-bad">Bad</span>';
                                            break;
                                        case 4:
                                            echo '<span class="status-badge rate-new">New</span>';
                                            break;
                                        default:
                                            echo '<span class="status-badge rate-new">New</span>';
                                    }
                                    ?>
                                </td>
                                <td>
    <?php
    $callLog = isset($row['call_log']) ? $row['call_log'] : null;
    $note = '';
    
    if ($callLog == 1) { // Answered
        // Check answer_reason
        $note = isset($row['answer_reason']) ? htmlspecialchars($row['answer_reason']) : '';
    } elseif ($callLog === '0' || $callLog === 0) { // No Answer
        // Check no_answer_reason
        $note = isset($row['no_answer_reason']) ? htmlspecialchars($row['no_answer_reason']) : '';
    }
    
    if (!empty($note)) {
        // Truncate long notes if necessary
        $displayNote = (strlen($note) > 30) ? substr($note, 0, 30) . '...' : $note;
        echo "<span title='" . $note . "'>" . $displayNote . "</span>";
    } else {
        echo "-";
    }
    ?>
</td>
                                <!-- Tenant Name -->
                                <?php if ($is_main_admin == 1 && $_SESSION['role_id'] == 1) { ?>
                                <td class="customer-name">
                                    <div class="customer-info">
                                        <h6 style="margin: 0; font-size: 14px;">
                                            <?php echo htmlspecialchars($row['company_name']); ?></h6>
                                    </div>
                                </td>
                                <?php } ?>


                                <!-- Processed By (who marked paid + payment method) -->
                                <td>
                                    <?php
                                    $paidByName = isset($row['paid_by_name']) ? htmlspecialchars($row['paid_by_name']) : '';
                                    $paidById = isset($row['pay_by']) ? htmlspecialchars($row['pay_by']) : '';
                                    $paymentMethod = isset($row['payment_method']) ? htmlspecialchars($row['payment_method']) : '';
                                    
                                    if ($payStatus == 'paid' && !empty($paidByName)) {
                                        $processedBy = $paidByName . (!empty($paidById) ? ' (' . $paidById . ')' : '');
                                        echo '<span style="font-weight: 600; color: #28a745;">' . $processedBy . '</span>';
                                        if (!empty($paymentMethod)) {
                                            $methodDisplay = ucwords(str_replace('_', ' ', $paymentMethod));
                                            echo '<br><span style="font-size: 11px; color: #6c757d;">' . $methodDisplay . '</span>';
                                        }
                                    } else {
                                        echo '<span style="color: #adb5bd;">-</span>';
                                    }
                                    ?>
                                </td>
                                <!-- Action Buttons - Updated to pass interface parameter -->
                                <td class="actions">
                                    <div class="action-buttons-group">
                                        <button class="action-btn view-btn" title="View Order Details"
                                            onclick="openOrderModal('<?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>', '<?php echo isset($row['interface']) ? htmlspecialchars($row['interface']) : ''; ?>')">
                                            <i class="fas fa-eye"></i>
                                        </button>

                                        <button class="action-btn edit-btn" title="Edit Order"
                                            onclick="editOrder('<?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>')">
                                            <i class="fas fa-edit"></i>
                                        </button>

                                        <?php if ($payStatus == 'unpaid'): ?>
                                        <button class="action-btn paid-btn" title="Mark as Paid"
                                            onclick="markAsPaid('<?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>')">
                                            <i class="fas fa-dollar-sign"></i>
                                        </button>
                                        <?php elseif ($payStatus == 'paid'): ?>
                                        <button class="action-btn cancel-btn" title="Unmark as Paid"
                                            onclick="unmarkPaid('<?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>')">
                                            <i class="fas fa-undo"></i>
                                        </button>
                                        <?php endif; ?>


                                        <!-- <button class="action-btn dispatch-btn" title="Mark as Dispatched"
                                            onclick="openDispatchModal('<?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>')">
                                            <i class="fas fa-truck"></i>
                                        </button> -->

                                        <button
                                            class="action-btn <?php echo ($row['call_log'] == 0) ? 'answer-btn' : 'no-answer-btn'; ?>"
                                            title="<?php echo ($row['call_log'] == 0) ? 'Mark as Answered' : 'Mark as No Answer'; ?>"
                                            onclick="openAnswerModal('<?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>', <?php echo $row['call_log']; ?>, '<?php echo isset($row['answer_reason']) ? addslashes(htmlspecialchars($row['answer_reason'])) : ''; ?>', '<?php echo isset($row['no_answer_reason']) ? addslashes(htmlspecialchars($row['no_answer_reason'])) : ''; ?>')">
                                            <i
                                                class="fas <?php echo ($row['call_log'] == 0) ? 'fas fa-phone-slash' : 'fas fa-phone'; ?>"></i>
                                        </button>

                                        <button class="action-btn cancel-btn" title="Cancel Order"
                                            onclick="cancelOrder('<?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>')">
                                            <i class="fas fa-times-circle"></i>
                                        </button>
                                        <button class="action-btn print-btn" title="Print Order"
                                            onclick="printOrder('<?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>')">
                                            <i class="fas fa-print"></i>
                                        </button>
                                        <button class="action-btn condition-btn" title="Update Success Rate"
                                            onclick="openConditionModal('<?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>', <?php echo $condition; ?>)">
                                            <i class="fas fa-user-shield"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                            <?php else: ?>
                            <tr>
                              <td colspan="<?php echo ($show_checkboxes ? 10 : 9) + 1; ?>" class="text-center"
                                style="padding: 40px; text-align: center; color: #666;">
                                No pending orders found
                            </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>


                <!-- Pagination Controls -->
                <div class="pagination">
                    <div class="pagination-info">
                        Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $totalRows); ?> of
                        <?php echo $totalRows; ?> entries
                    </div>
                    <div class="pagination-controls">
                        <?php if ($page > 1): ?>
                        <button class="page-btn"
                            onclick="window.location.href='?page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>&order_id_filter=<?php echo urlencode($order_id_filter); ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&phone_filter=<?php echo urlencode($phone_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&pay_status_filter=<?php echo urlencode($pay_status_filter); ?>&call_status_filter=<?php echo urlencode($call_status_filter); ?>&condition_filter=<?php echo urlencode($condition_filter); ?>&tenant_id_filter=<?php echo urlencode($tenant_id_filter); ?>&user_id_filter=<?php echo urlencode($user_id_filter); ?>&search=<?php echo urlencode($search); ?>'">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <?php endif; ?>

                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <button class="page-btn <?php echo ($i == $page) ? 'active' : ''; ?>"
                            onclick="window.location.href='?page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&order_id_filter=<?php echo urlencode($order_id_filter); ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&phone_filter=<?php echo urlencode($phone_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&pay_status_filter=<?php echo urlencode($pay_status_filter); ?>&call_status_filter=<?php echo urlencode($call_status_filter); ?>&condition_filter=<?php echo urlencode($condition_filter); ?>&tenant_id_filter=<?php echo urlencode($tenant_id_filter); ?>&user_id_filter=<?php echo urlencode($user_id_filter); ?>&search=<?php echo urlencode($search); ?>'">
                            <?php echo $i; ?>
                        </button>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                        <button class="page-btn"
                            onclick="window.location.href='?page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>&order_id_filter=<?php echo urlencode($order_id_filter); ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&phone_filter=<?php echo urlencode($phone_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&pay_status_filter=<?php echo urlencode($pay_status_filter); ?>&call_status_filter=<?php echo urlencode($call_status_filter); ?>&condition_filter=<?php echo urlencode($condition_filter); ?>&tenant_id_filter=<?php echo urlencode($tenant_id_filter); ?>&user_id_filter=<?php echo urlencode($user_id_filter); ?>&search=<?php echo urlencode($search); ?>'">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <!-- Include MODAL for View Order -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/include/order_view_modal.php'); ?>

    <!-- DISPATCH MODAL HTML -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/include/dispatch_modal.php'); ?>

    <!-- BULK DISPATCH MODAL HTML  -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/include/bulk_dispatch_modal.php'); ?>

    <!--  ADD THE API DISPATCH MODAL HTML -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/include/api_dispatch.php'); ?>

    <!-- ANSWER STATUS MODAL -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/include/answer_status_modal.php'); ?>

    <script>
    /**
     * JavaScript functionality for pending order management
     */

    let currentOrderId = null;
    let currentInterface = null;
    let currentPaymentSlip = null; // Store payment slip filename
    let currentPayStatus = null; // Store payment status

    function showAlert(type, message) {
        toastManager[type](message);
    }

    // Clear all filter inputs
    function clearFilters() {
        document.getElementById('order_id_filter').value = '';
        document.getElementById('customer_name_filter').value = '';
        document.getElementById('phone_filter').value = '';
        document.getElementById('date_from').value = '';
        document.getElementById('date_to').value = '';
        document.getElementById('pay_status_filter').value = '';
        document.getElementById('call_status_filter').value = '';
        document.getElementById('condition_filter').value = '';
        if (document.getElementById('tenant_id_filter')) {
            document.getElementById('tenant_id_filter').value = '';
        }
        const userIdFilter = document.getElementById('user_id_filter');
        if (userIdFilter) {
            userIdFilter.value = '';
        }

        // Submit the form to clear filters
        window.location.href = window.location.pathname;
    }

    // MODIFIED: Enhanced openOrderModal function
    function openOrderModal(orderId, interface = null) {
        if (!orderId || orderId.trim() === '') {
            toastManager.warning('Order ID is required to view order details.');
            return;
        }

        console.log('Opening modal for Order ID:', orderId, 'Interface:', interface);

        currentOrderId = orderId.trim();
        currentInterface = interface;

        const modal = document.getElementById('orderModal');
        const modalContent = document.getElementById('modalContent');
        const downloadBtn = document.getElementById('downloadBtn');
        const viewPaymentSlipBtn = document.getElementById('viewPaymentSlipBtn');

        // Show modal
        modal.style.display = 'flex';
        document.body.style.overflow = 'clip';

        // Show loading state
        modalContent.innerHTML = `
        <div class="modal-loading">
            <i class="fas fa-spinner fa-spin"></i>
            Loading ${interface === 'leads' ? 'lead' : 'order'} details for Order ID: ${currentOrderId}...
        </div>
    `;
        downloadBtn.style.display = 'none';
        viewPaymentSlipBtn.style.display = 'none';

        // Determine which PHP file to use based on interface
        const phpFile = 'download_order_page.php';
        const fetchUrl = phpFile + '?id=' + encodeURIComponent(currentOrderId);

        console.log('Fetching from:', fetchUrl);

        fetch(fetchUrl, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                }
            })
            .then(response => {
                console.log('Response status:', response.status);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.text();
            })
            .then(data => {
                console.log('Data received:', data.length, 'characters');
                if (data.trim() === '') {
                    throw new Error('No data received from server');
                }
                modalContent.innerHTML = data;
                downloadBtn.style.display = 'inline-flex';

                // MODIFIED: Check for payment slip and update button visibility
                checkPaymentSlipAvailability();
            })
            .catch(error => {
                console.error('Error loading order details:', error);
                const itemType = (interface === 'leads') ? 'lead' : 'order';
                modalContent.innerHTML = `
            <div class="modal-error" style="text-align: center; padding: 20px; color: #dc3545;">
                <i class="fas fa-exclamation-triangle" style="font-size: 2em; margin-bottom: 10px;"></i>
                <h4>Error Loading ${itemType.charAt(0).toUpperCase() + itemType.slice(1)} Details</h4>
                <p>Order ID: ${currentOrderId}</p>
                <p>Error: ${error.message}</p>
                <p>Please check if the ${phpFile} file exists and is accessible.</p>
                <button onclick="retryLoadOrder()" class="btn btn-primary" style="margin-top: 10px;">
                    <i class="fas fa-redo"></i> Retry
                </button>
            </div>
        `;
            });
    }

    // MODIFIED: Function to check payment slip availability - always show button for paid orders
    function checkPaymentSlipAvailability() {
        if (!currentOrderId) return;

        // Fetch payment slip information from server
        fetch('get_payment_slip_info.php?order_id=' + encodeURIComponent(currentOrderId), {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    currentPaymentSlip = data.payment_slip;
                    currentPayStatus = data.pay_status;

                    const viewPaymentSlipBtn = document.getElementById('viewPaymentSlipBtn');

                    // MODIFIED: Show button for all paid orders, regardless of slip availability
                    if (currentPayStatus === 'paid') {
                        if (currentPaymentSlip && currentPaymentSlip.trim() !== '') {
                            viewPaymentSlipBtn.style.display = 'inline-flex';
                            const noSlipMsg = document.getElementById('noPaymentSlipMsg');
                            if (noSlipMsg) noSlipMsg.style.display = 'none';
                        } else {
                            viewPaymentSlipBtn.style.display = 'none';
                            const noSlipMsg = document.getElementById('noPaymentSlipMsg');
                            if (noSlipMsg) noSlipMsg.style.display = 'inline-flex';
                        }
                    } else {
                        viewPaymentSlipBtn.style.display = 'none';
                        const noSlipMsg = document.getElementById('noPaymentSlipMsg');
                        if (noSlipMsg) noSlipMsg.style.display = 'none';
                    }
                } else {
                    console.log('No payment slip information available');
                }
            })
            .catch(error => {
                console.error('Error checking payment slip:', error);
            });
    }

    // MODIFIED: Function to view payment slip with no-slip message
    function viewPaymentSlip() {
        // Check if payment slip exists
        if (!currentPaymentSlip || currentPaymentSlip.trim() === '') {
            const slipBtn = document.getElementById('viewPaymentSlipBtn');
            const noSlipMsg = document.getElementById('noPaymentSlipMsg');
            if (slipBtn) slipBtn.style.display = 'none';
            if (noSlipMsg) noSlipMsg.style.display = 'inline-flex';
            return;
        }

        // Construct the payment slip URL
        const slipUrl = '/orderhub_nextwave/dist/uploads/' + encodeURIComponent(currentPaymentSlip);

        // Open payment slip in new tab
        window.open(slipUrl, '_blank');
    }

    // Retry loading order 
    function retryLoadOrder() {
        if (currentOrderId) {
            openOrderModal(currentOrderId, currentInterface);
        }
    }

    // Close order modal 
    function closeOrderModal() {
        const modal = document.getElementById('orderModal');
        modal.style.display = 'none';
        document.body.style.overflow = '';
        currentOrderId = null;
        currentInterface = null;
        currentPaymentSlip = null;
        currentPayStatus = null;
    }

    // Download order 
    function downloadOrder() {
        if (!currentOrderId) {
            alert('No order selected for download.');
            return;
        }

        const phpFile = 'download_order.php';
        const downloadUrl = phpFile + '?id=' + encodeURIComponent(currentOrderId) + '&download=1';

        console.log('Downloading from:', downloadUrl);
        window.open(downloadUrl, '_blank');
    }

    // Close modal when clicking outside 
    document.getElementById('orderModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeOrderModal();
        }
    });

    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeOrderModal();
        }
    });

    // Initialize page functionality when DOM is loaded 
    document.addEventListener('DOMContentLoaded', function() {
        console.log('Orders page loaded, initializing...');

        const tableRows = document.querySelectorAll('.orders-table tbody tr');
        tableRows.forEach(row => {
            row.addEventListener('mouseenter', function() {
                this.style.transform = 'translateX(2px)';
            });

            row.addEventListener('mouseleave', function() {
                this.style.transform = 'translateX(0)';
            });
        });

        const modal = document.getElementById('orderModal');
        const modalContent = document.getElementById('modalContent');
        if (!modal || !modalContent) {
            console.error('Modal elements not found! Check HTML structure.');
        }
    });


    // Open Dispatch Modal
  // Open Dispatch Modal - UPDATED VERSION
function openDispatchModal(orderId) {
    if (!orderId || orderId.trim() === '') {
        toastManager.warning('Order ID is required to dispatch order.');
        return;
    }

    console.log('Opening dispatch modal for Order ID:', orderId);

    // Set the order ID in the hidden input
    document.getElementById('dispatch_order_id').value = orderId.trim();

    // Reset the form
    document.getElementById('dispatch-order-form').reset();
    document.getElementById('dispatch_order_id').value = orderId.trim();

    // Reset tracking number display
    document.getElementById('tracking_number_display').innerHTML = 
        '<span class="text-muted">Select a courier to see available tracking number</span>';

    // Disable submit button initially
    document.getElementById('dispatch-submit-btn').disabled = true;

    // Show the modal
    const modal = document.getElementById('dispatchOrderModal');
    modal.style.display = 'flex';
    document.body.style.overflow = 'clip';

    // **NEW: Load couriers for this specific order**
    loadCouriersForOrder(orderId);
}

// **NEW FUNCTION: Load couriers based on order's tenant**
function loadCouriersForOrder(orderId) {
    const carrierSelect = document.getElementById('carrier');
    
    // Show loading state
    carrierSelect.innerHTML = '<option value="">Loading couriers...</option>';
    carrierSelect.disabled = true;

    // Fetch couriers for this order
    fetch('get_order_couriers.php?order_id=' + encodeURIComponent(orderId), {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            // Clear loading option
            carrierSelect.innerHTML = '<option value="" selected disabled>Select courier service</option>';
            
            // Add couriers to dropdown
            if (data.couriers && data.couriers.length > 0) {
                data.couriers.forEach(courier => {
                    const option = document.createElement('option');
                    option.value = courier.courier_id;
                    option.textContent = `${courier.courier_name} (ID: ${courier.co_id})`;
                    carrierSelect.appendChild(option);
                });
                carrierSelect.disabled = false;
            } else {
                carrierSelect.innerHTML = '<option value="" disabled>No couriers available for this order</option>';
                alert('No couriers available for this order\'s tenant. Please contact administrator.');
            }
        } else {
            carrierSelect.innerHTML = '<option value="" disabled>Error loading couriers</option>';
            alert('Error: ' + (data.message || 'Failed to load couriers'));
        }
    })
    .catch(error => {
        console.error('Error loading couriers:', error);
        carrierSelect.innerHTML = '<option value="" disabled>Error loading couriers</option>';
        alert('An error occurred while loading couriers. Please try again.');
    })
    .finally(() => {
        carrierSelect.disabled = false;
    });
}

    // Close Dispatch Modal
    function closeDispatchModal() {
        const modal = document.getElementById('dispatchOrderModal');
        modal.style.display = 'none';
        document.body.style.overflow = '';

        // Reset form
        document.getElementById('dispatch-order-form').reset();
        document.getElementById('tracking_number_display').innerHTML =
            '<span class="text-muted">Select a courier to see available tracking number</span>';
        document.getElementById('dispatch-submit-btn').disabled = true;
    }

    // Fetch tracking number for selected courier
// Fetch tracking number for selected courier
function fetchTrackingNumber(courierId) {
    const trackingDisplay = document.getElementById('tracking_number_display');
    const submitBtn = document.getElementById('dispatch-submit-btn');
    const orderId = document.getElementById('dispatch_order_id').value;

    if (!courierId) {
        trackingDisplay.innerHTML =
            '<div class="info-box" style="margin-bottom:0;"><i class="fas fa-info-circle"></i>Select a courier to see available tracking number</div>';
        submitBtn.disabled = true;
        return;
    }

    // Show loading state
    trackingDisplay.innerHTML =
        '<div class="info-box" style="margin-bottom:0;"><i class="fas fa-spinner fa-spin"></i>Loading tracking number...</div>';
    submitBtn.disabled = true;

    fetch(`get_tracking_number.php?courier_id=${courierId}&order_id=${encodeURIComponent(orderId)}`, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
            }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.status === 'success') {
                const tn = data.tracking_number;
                const available = data.available_count || 0;
                const headerBg   = available > 0 ? '#d4edda' : '#fff3cd';
                const headerIcon = available > 0 ? '<i class="fas fa-check-circle me-1"></i>' : '<i class="fas fa-exclamation-triangle me-1"></i>';
                const headerText = available > 0
                    ? `<strong>${available}</strong> tracking numbers available`
                    : `<strong>No</strong> tracking numbers available for this courier`;

                // Get customer name from the order row in the main table
                const orderCell = Array.from(document.querySelectorAll('.orders-table .order-id'))
                    .find(cell => cell.textContent.trim() === orderId);
                let customerName = '';
                if (orderCell) {
                    const nameCell = orderCell.closest('tr').querySelector('.customer-name');
                    if (nameCell) customerName = nameCell.textContent.trim().split('\n')[0].trim();
                }

                trackingDisplay.innerHTML = `
                    <div style="background:${headerBg};padding:8px 10px;border-radius:4px 4px 0 0;font-size:13px;">
                        ${headerIcon}${headerText}
                    </div>
                    <div style="max-height:150px;overflow-y:auto;">
                        <table style="width:100%;table-layout:fixed;border-collapse:collapse;font-size:13px;background:#fff;border:1px solid #dee2e6;">
                            <colgroup>
                                <col style="width:40px;">
                                <col style="width:100px;">
                                <col>
                                <col style="width:130px;">
                            </colgroup>
                            <thead>
                                <tr style="background:#f8f9fa;">
                                    <th style="padding:6px 8px;text-align:center;border-bottom:2px solid #dee2e6;">#</th>
                                    <th style="padding:6px 8px;text-align:left;border-bottom:2px solid #dee2e6;">Order ID</th>
                                    <th style="padding:6px 8px;text-align:left;border-bottom:2px solid #dee2e6;">Customer</th>
                                    <th style="padding:6px 8px;text-align:left;border-bottom:2px solid #dee2e6;">Tracking Number</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td style="padding:5px 8px;text-align:center;border-bottom:1px solid #eee;">1</td>
                                    <td style="padding:5px 8px;text-align:left;font-weight:600;border-bottom:1px solid #eee;">${orderId}</td>
                                    <td style="padding:5px 8px;text-align:left;border-bottom:1px solid #eee;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${customerName}">${customerName || '-'}</td>
                                    <td style="padding:5px 8px;text-align:left;font-family:monospace;color:#155724;border-bottom:1px solid #eee;"><strong>${tn}</strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>`;
                submitBtn.disabled = false;
            } else {
                trackingDisplay.innerHTML =
                    `<div class="info-box info-box--warning"><i class="fas fa-exclamation-circle"></i>${data.message}</div>`;
                submitBtn.disabled = true;
            }
        })
        .catch(error => {
            console.error('Error fetching tracking number:', error);
            trackingDisplay.innerHTML =
                '<div class="info-box info-box--warning"><i class="fas fa-exclamation-triangle"></i>Error loading tracking number. Please try again.</div>';
            submitBtn.disabled = true;
        });
}

    // Initialize Dispatch Functionality
    document.addEventListener('DOMContentLoaded', function() {
        const carrierSelect = document.getElementById('carrier');
        const submitBtn = document.getElementById('dispatch-submit-btn');
        const dispatchForm = document.getElementById('dispatch-order-form');
        const modal = document.getElementById('dispatchOrderModal');

        // Handle courier selection change
        if (carrierSelect) {
            carrierSelect.addEventListener('change', function() {
                const selectedCourierId = this.value;

                if (selectedCourierId) {
                    // Fetch tracking number for selected courier
                    fetchTrackingNumber(selectedCourierId);
                } else {
                    // Reset display when no courier is selected
                    document.getElementById('tracking_number_display').innerHTML =
                        '<span class="text-muted">Select a courier to see available tracking number</span>';
                    submitBtn.disabled = true;
                }
            });
        }

        // Handle dispatch form submission
        if (dispatchForm) {
            dispatchForm.addEventListener('submit', function(e) {
                e.preventDefault();

                const orderId = document.getElementById('dispatch_order_id').value;
                const carrier = document.getElementById('carrier').value;
                const dispatchNotes = document.getElementById('dispatch_notes').value;

                if (!orderId || !carrier) {
                    alert('Please select a courier service before dispatching');
                    return;
                }

                // Confirm dispatch
                if (!confirm(
                        'Are you sure you want to dispatch this order? This action cannot be undone.'
                        )) {
                    return;
                }

                // Show loading state
                submitBtn.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-2"></span>Dispatching...';
                submitBtn.disabled = true;

                // Create FormData object
                const formData = new FormData();
                formData.append('order_id', orderId);
                formData.append('carrier', carrier);
                formData.append('dispatch_notes', dispatchNotes);
                formData.append('action', 'dispatch_order');

                // Send the request
                fetch('process_dispatch.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            alert('Order dispatched successfully!' +
                                (data.tracking_number ? ' Tracking number: ' + data
                                    .tracking_number : ''));
                            closeDispatchModal();
                            // Reload the page to reflect changes
                            window.location.reload();
                        } else {
                            alert('Error: ' + (data.message || 'Failed to dispatch order'));
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('An error occurred while dispatching the order. Please try again.');
                    })
                    .finally(() => {
                        // Reset button state
                        submitBtn.innerHTML = '<i class="fas fa-truck me-1"></i>Confirm Dispatch';
                        submitBtn.disabled = !carrier; // Enable only if carrier is selected
                    });
            });
        }

        // Close modal when clicking outside
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeDispatchModal();
                }
            });
        }

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const modal = document.getElementById('dispatchOrderModal');
                if (modal && modal.style.display === 'flex') {
                    closeDispatchModal();
                }
            }
        });
    });

    // Additional helper functions
    function markAsDispatched(orderId) {
        // This is an alternative function name that calls openDispatchModal
        // Keep this for backward compatibility
        openDispatchModal(orderId);
    }




    /**
     * Edit Order function
     * Redirects to the edit order page
     */
    function editOrder(orderId) {
        if (!orderId) {
            toastManager.warning('Order ID is required to edit order.');
            return;
        }
        window.location.href = 'edit_order.php?id=' + encodeURIComponent(orderId);
    }


    // Bulk Selection JavaScript Functions
    function toggleSelectAll() {
        const selectAllCheckbox = document.getElementById('selectAll');
        const orderCheckboxes = document.querySelectorAll('.order-checkbox');

        orderCheckboxes.forEach(checkbox => {
            if (!checkbox.disabled) {
                checkbox.checked = selectAllCheckbox.checked;
            }
        });

        updateBulkSelection();
    }

    function updateBulkSelection() {
        const orderCheckboxes = document.querySelectorAll('.order-checkbox:not(:disabled)');
        const checkedBoxes = document.querySelectorAll('.order-checkbox:checked:not(:disabled)');
        const selectAllCheckbox = document.getElementById('selectAll');
        const bulkActionsBar = document.getElementById('bulkActionsBar');
        const selectedCount = document.getElementById('selectedCount');

        // Update select all checkbox state
        if (checkedBoxes.length === 0) {
            selectAllCheckbox.indeterminate = false;
            selectAllCheckbox.checked = false;
        } else if (checkedBoxes.length === orderCheckboxes.length) {
            selectAllCheckbox.indeterminate = false;
            selectAllCheckbox.checked = true;
        } else {
            selectAllCheckbox.indeterminate = true;
            selectAllCheckbox.checked = false;
        }

        // Show/hide bulk actions bar
        if (checkedBoxes.length > 0) {
            bulkActionsBar.style.display = 'flex';
            selectedCount.textContent = checkedBoxes.length;
        } else {
            bulkActionsBar.style.display = 'none';
        }
    }

    function getSelectedOrderIds() {
        const checkedBoxes = document.querySelectorAll('.order-checkbox:checked:not(:disabled)');
        return Array.from(checkedBoxes).map(checkbox => checkbox.value);
    }

    function clearBulkSelection() {
        const orderCheckboxes = document.querySelectorAll('.order-checkbox');
        const selectAllCheckbox = document.getElementById('selectAll');

        orderCheckboxes.forEach(checkbox => {
            if (!checkbox.disabled) {
                checkbox.checked = false;
            }
        });
        selectAllCheckbox.checked = false;
        selectAllCheckbox.indeterminate = false;

        updateBulkSelection();
    }
    /**
     * BULK DISPATCH FUNCTIONALITY
     * Add these functions to your existing JavaScript code
     */

    // Global variables for bulk dispatch
    let selectedOrdersForBulkDispatch = [];

    /**
     * Toggle Select All Checkbox
     */
    function toggleSelectAll() {
        const selectAllCheckbox = document.getElementById('selectAll');
        const orderCheckboxes = document.querySelectorAll('.order-checkbox');

        orderCheckboxes.forEach(checkbox => {
            if (!checkbox.disabled) {
                checkbox.checked = selectAllCheckbox.checked;
            }
        });

        updateBulkSelection();
    }

    /**
     * Update Bulk Selection Display
     */
    function updateBulkSelection() {
        const totalCheckboxes = document.querySelectorAll('.order-checkbox:not(:disabled)');
        const orderCheckboxes = document.querySelectorAll('.order-checkbox:checked:not(:disabled)');
        const selectedCount = orderCheckboxes.length;
        const bulkActionsBar = document.getElementById('bulkActionsBar');
        const selectedCountElement = document.getElementById('selectedCount');
        const selectAllCheckbox = document.getElementById('selectAll');

        // Update selected count
        if (selectedCountElement) {
            selectedCountElement.textContent = selectedCount;
        }

        // Show/hide bulk actions bar
        if (bulkActionsBar) {
            bulkActionsBar.style.display = selectedCount > 0 ? 'flex' : 'none';
        }

        // Update select all checkbox state
        if (selectAllCheckbox) {
            selectAllCheckbox.checked = selectedCount === totalCheckboxes.length && totalCheckboxes.length > 0;
            selectAllCheckbox.indeterminate = selectedCount > 0 && selectedCount < totalCheckboxes.length;
        }

        // Store selected orders for bulk dispatch
        selectedOrdersForBulkDispatch = Array.from(orderCheckboxes).map(checkbox => checkbox.value);
    }

    /**
     * Clear Bulk Selection
     */
    function clearBulkSelection() {
        const orderCheckboxes = document.querySelectorAll('.order-checkbox');
        const selectAllCheckbox = document.getElementById('selectAll');

        orderCheckboxes.forEach(checkbox => {
            if (!checkbox.disabled) {
                checkbox.checked = false;
            }
        });

        if (selectAllCheckbox) {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = false;
        }

        updateBulkSelection();
    }

    /**
     * Open Bulk Dispatch Modal
     */
  /**
 * Open Bulk Dispatch Modal - UPDATED WITH TENANT FILTERING
 */
function bulkMarkAsDispatched() {
    const selectedOrders = document.querySelectorAll('.order-checkbox:checked');

    if (selectedOrders.length === 0) {
        alert('Please select at least one order to dispatch.');
        return;
    }

    console.log('Opening bulk dispatch modal for', selectedOrders.length, 'orders');

    // Update selected orders list in modal
    updateSelectedOrdersList();

    // Reset form
    document.getElementById('bulk-dispatch-form').reset();
    document.getElementById('bulk_tracking_numbers_display').innerHTML =
        '<span class="text-muted">Select a courier to see available tracking numbers</span>';
    document.getElementById('bulk-dispatch-submit-btn').disabled = true;

    // Show modal
    const modal = document.getElementById('bulkDispatchModal');
    modal.style.display = 'flex';
    document.body.style.overflow = 'clip';

    // **NEW: Load couriers based on selected orders' tenant**
    loadCouriersForBulkDispatch();
}

/**
 * NEW FUNCTION: Load Couriers for Bulk Dispatch Based on Selected Orders
 * Fetches couriers that belong to the same tenant as selected orders
 */
function loadCouriersForBulkDispatch() {
    const carrierSelect = document.getElementById('bulk_carrier');
    const helpText = document.getElementById('bulk-courier-help-text');
    const selectedOrders = Array.from(document.querySelectorAll('.order-checkbox:checked'))
        .map(cb => cb.value);

    console.log('Loading couriers for orders:', selectedOrders);

    // Show loading state
    carrierSelect.innerHTML = '<option value="">Loading couriers...</option>';
    carrierSelect.disabled = true;
    helpText.textContent = 'Checking available couriers for selected orders...';
    helpText.className = 'form-text text-muted';

    // Fetch couriers based on selected order IDs
    fetch('get_bulk_couriers.php?order_ids=' + encodeURIComponent(JSON.stringify(selectedOrders)), {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => {
        console.log('Courier fetch response status:', response.status);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        console.log('Courier fetch response:', data);
        
        if (data.success) {
            // Clear loading option
            carrierSelect.innerHTML = '<option value="" selected disabled>Select courier service</option>';
            
            // Add couriers to dropdown
            if (data.couriers && data.couriers.length > 0) {
                data.couriers.forEach(courier => {
                    const option = document.createElement('option');
                    option.value = courier.courier_id;
                    option.textContent = `${courier.courier_name} (ID: ${courier.co_id})`;
                    carrierSelect.appendChild(option);
                });
                carrierSelect.disabled = false;
                helpText.textContent = `${data.courier_count} courier(s) available for selected orders (Tenant: ${data.tenant_id})`;
                helpText.className = 'form-text text-success';
            } else {
                carrierSelect.innerHTML = '<option value="" disabled>No couriers available for this tenant</option>';
                carrierSelect.disabled = true;
                helpText.textContent = 'No couriers available for the selected orders. Please contact administrator.';
                helpText.className = 'form-text text-danger';
                alert('No couriers are configured for the selected orders\' tenant. Please contact your system administrator.');
            }
        } else {
            // Handle error - especially multi-tenant selection
            carrierSelect.innerHTML = '<option value="" disabled>Error loading couriers</option>';
            carrierSelect.disabled = true;
            helpText.textContent = data.message || 'Error loading couriers';
            helpText.className = 'form-text text-danger';
            
            // Show detailed error message
            alert('Error: ' + (data.message || 'Failed to load couriers'));
        }
    })
    .catch(error => {
        console.error('Error loading couriers:', error);
        carrierSelect.innerHTML = '<option value="" disabled>Network error</option>';
        carrierSelect.disabled = true;
        helpText.textContent = 'Network error. Please check your connection and try again.';
        helpText.className = 'form-text text-danger';
        alert('Network error occurred while loading couriers. Please check your connection and try again.');
    });
}

    /**
     * Update Selected Orders List in Modal
     */
    function updateSelectedOrdersList() {
        const selectedOrders = document.querySelectorAll('.order-checkbox:checked');
        const selectedOrdersList = document.getElementById('selectedOrdersList');
        const bulkSelectedCount = document.getElementById('bulkSelectedCount');

        if (bulkSelectedCount) {
            bulkSelectedCount.textContent = selectedOrders.length;
        }

        if (selectedOrdersList) {
            let ordersHtml = '<div class="selected-orders-list">';

            selectedOrders.forEach((checkbox, index) => {
                const orderId = checkbox.value;
                const row = checkbox.closest('tr');
                const customerName = row.querySelector('.customer-name').textContent.trim();

                ordersHtml += `
                <div class="selected-order-item">
                    <span class="order-number">${index + 1}.</span>
                    <span class="order-id">${orderId}</span>
                    <span class="customer-name">${customerName}</span>
                </div>
            `;
            });

            ordersHtml += '</div>';
            selectedOrdersList.innerHTML = ordersHtml;
        }
    }

    /**
     * Close Bulk Dispatch Modal
     */
    function closeBulkDispatchModal() {
        const modal = document.getElementById('bulkDispatchModal');
        modal.style.display = 'none';
        document.body.style.overflow = '';

        // Reset form
        document.getElementById('bulk-dispatch-form').reset();
        document.getElementById('bulk_tracking_numbers_display').innerHTML =
            '<span class="text-muted">Select a courier to see available tracking numbers</span>';
        document.getElementById('bulk-dispatch-submit-btn').disabled = true;
    }

    /**
 * Fetch Tracking Numbers for Bulk Dispatch - UPDATED WITH TENANT FILTERING
 */
function fetchBulkTrackingNumbers(courierId) {
    const trackingDisplay = document.getElementById('bulk_tracking_numbers_display');
    const submitBtn = document.getElementById('bulk-dispatch-submit-btn');
    const selectedCheckboxes = document.querySelectorAll('.order-checkbox:checked');
    const selectedCount = selectedCheckboxes.length;

    if (!courierId) {
        trackingDisplay.innerHTML =
            '<div class="info-box" style="margin-bottom:0;"><i class="fas fa-info-circle"></i>Select a courier to see available tracking numbers</div>';
        submitBtn.disabled = true;
        return;
    }

    if (selectedCount === 0) {
        trackingDisplay.innerHTML =
            '<div class="info-box info-box--warning" style="margin-bottom:0;"><i class="fas fa-exclamation-triangle"></i>No orders selected</div>';
        submitBtn.disabled = true;
        return;
    }

    // Collect order ID + customer name from each selected checkbox row
    const selectedOrders = Array.from(selectedCheckboxes).map(checkbox => {
        const row = checkbox.closest('tr');
        const orderId = checkbox.value;
        const customerCell = row ? row.querySelector('td.customer-name') : null;
        const customerName = customerCell ? customerCell.textContent.trim().split('\n')[0].trim() : '';
        return { orderId, customerName };
    });

    // Pass first order_id for tenant-specific tracking numbers
    const firstOrderId = selectedCheckboxes[0].value;

    // Show loading state
    trackingDisplay.innerHTML =
        '<div class="info-box" style="margin-bottom:0;"><i class="fas fa-spinner fa-spin"></i>Loading tracking numbers...</div>';
    submitBtn.disabled = true;

    fetch(`get_bulk_tracking_numbers.php?courier_id=${courierId}&count=${selectedCount}&order_id=${encodeURIComponent(firstOrderId)}`, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
            }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('Tracking numbers response:', data);
            
            if (data.status === 'success') {
                const trackingNumbers = data.tracking_numbers;
                const availableCount = data.available_count || trackingNumbers.length;

                // Build paired table rows
                let rowsHtml = '';
                selectedOrders.forEach((order, index) => {
                    const tn = trackingNumbers[index] || null;
                    if (tn) {
                        rowsHtml += `
                            <tr>
                                <td style="padding:5px 8px;text-align:center;border-bottom:1px solid #eee;">${index + 1}</td>
                                <td style="padding:5px 8px;text-align:left;font-weight:600;border-bottom:1px solid #eee;">${order.orderId}</td>
                                <td style="padding:5px 8px;text-align:left;border-bottom:1px solid #eee;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${order.customerName}">${order.customerName}</td>
                                <td style="padding:5px 8px;text-align:left;font-family:monospace;color:#155724;border-bottom:1px solid #eee;"><strong>${tn}</strong></td>
                            </tr>`;
                    } else {
                        rowsHtml += `
                            <tr style="background:#fff3cd;">
                                <td style="padding:5px 8px;text-align:center;border-bottom:1px solid #eee;">${index + 1}</td>
                                <td style="padding:5px 8px;text-align:left;font-weight:600;border-bottom:1px solid #eee;">${order.orderId}</td>
                                <td style="padding:5px 8px;text-align:left;border-bottom:1px solid #eee;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${order.customerName}">${order.customerName}</td>
                                <td style="padding:5px 8px;text-align:left;color:#856404;border-bottom:1px solid #eee;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><i class="fas fa-exclamation-triangle me-1"></i>No tracking</td>
                            </tr>`;
                    }
                });

                const isSufficient = availableCount >= selectedCount;
                const headerBg   = isSufficient ? '#d4edda' : '#fff3cd';
                const headerIcon = isSufficient ? '<i class="fas fa-check-circle me-1"></i>' : '<i class="fas fa-exclamation-triangle me-1"></i>';
                const headerText = isSufficient
                    ? `<strong>${availableCount}</strong> tracking numbers available &mdash; all ${selectedCount} orders will be dispatched`
                    : `Only <strong>${availableCount}</strong> of ${selectedCount} orders will be dispatched (insufficient tracking numbers)`;

                trackingDisplay.innerHTML = `
                    <div style="background:${headerBg};padding:8px 10px;border-radius:4px 4px 0 0;font-size:13px;">
                        ${headerIcon}${headerText}
                    </div>
                    <div style="max-height:220px;overflow-y:auto;">
                        <table style="width:100%;table-layout:fixed;border-collapse:collapse;font-size:13px;background:#fff;border:1px solid #dee2e6;">
                            <colgroup>
                                <col style="width:40px;">
                                <col style="width:100px;">
                                <col>
                                <col style="width:130px;">
                            </colgroup>
                            <thead>
                                <tr style="background:#f8f9fa;">
                                    <th style="padding:6px 8px;text-align:center;border-bottom:2px solid #dee2e6;">#</th>
                                    <th style="padding:6px 8px;text-align:left;border-bottom:2px solid #dee2e6;">Order ID</th>
                                    <th style="padding:6px 8px;text-align:left;border-bottom:2px solid #dee2e6;">Customer</th>
                                    <th style="padding:6px 8px;text-align:left;border-bottom:2px solid #dee2e6;">Tracking Number</th>
                                </tr>
                            </thead>
                            <tbody>${rowsHtml}</tbody>
                        </table>
                    </div>`;

                // Allow dispatch as long as at least one tracking number is available
                submitBtn.disabled = availableCount === 0;
            } else {
                trackingDisplay.innerHTML =
                    `<div class="info-box info-box--warning"><i class="fas fa-exclamation-circle"></i>${data.message}</div>`;
                submitBtn.disabled = true;
            }
        })
        .catch(error => {
            console.error('Error fetching tracking numbers:', error);
            trackingDisplay.innerHTML =
                '<div class="info-box info-box--warning"><i class="fas fa-exclamation-triangle"></i>Error loading tracking numbers. Please try again.</div>';
            submitBtn.disabled = true;
        });
}

    /**
     * Initialize Bulk Dispatch Functionality
     */
    document.addEventListener('DOMContentLoaded', function() {
        const bulkCarrierSelect = document.getElementById('bulk_carrier');
        const bulkDispatchForm = document.getElementById('bulk-dispatch-form');
        const bulkModal = document.getElementById('bulkDispatchModal');

        // Handle bulk courier selection change
        if (bulkCarrierSelect) {
            bulkCarrierSelect.addEventListener('change', function() {
                const selectedCourierId = this.value;

                if (selectedCourierId) {
                    // Fetch tracking numbers for selected courier
                    fetchBulkTrackingNumbers(selectedCourierId);
                } else {
                    // Reset display when no courier is selected
                    document.getElementById('bulk_tracking_numbers_display').innerHTML =
                        '<span class="text-muted">Select a courier to see available tracking numbers</span>';
                    document.getElementById('bulk-dispatch-submit-btn').disabled = true;
                }
            });
        }

        // Handle bulk dispatch form submission
     // Replace your existing bulk dispatch form submission handler with this enhanced version
// This adds detailed error logging to help identify the issue

if (bulkDispatchForm) {
    bulkDispatchForm.addEventListener('submit', function(e) {
        e.preventDefault();

        const carrier = document.getElementById('bulk_carrier').value;
        const dispatchNotes = document.getElementById('bulk_dispatch_notes').value;
        const submitBtn = document.getElementById('bulk-dispatch-submit-btn');

        if (!carrier) {
            alert('Please select a courier service before dispatching');
            return;
        }

        if (selectedOrdersForBulkDispatch.length === 0) {
            alert('No orders selected for dispatch');
            return;
        }

        // Confirm bulk dispatch
        if (!confirm(
                `Are you sure you want to dispatch ${selectedOrdersForBulkDispatch.length} orders? This action cannot be undone.`
                )) {
            return;
        }

        // Show loading state
        submitBtn.innerHTML =
            '<span class="spinner-border spinner-border-sm me-2"></span>Dispatching...';
        submitBtn.disabled = true;

        // Create FormData object
        const formData = new FormData();
        formData.append('order_ids', JSON.stringify(selectedOrdersForBulkDispatch));
        formData.append('carrier', carrier);
        formData.append('dispatch_notes', dispatchNotes);
        formData.append('action', 'bulk_dispatch_orders');

        // Log the data being sent
        console.log('=== BULK DISPATCH DEBUG ===');
        console.log('Sending data:', {
            order_ids: selectedOrdersForBulkDispatch,
            carrier: carrier,
            dispatch_notes: dispatchNotes,
            action: 'bulk_dispatch_orders'
        });

        // Send the request
        fetch('process_bulk_dispatch.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                console.log('Response status:', response.status);
                console.log('Response headers:', response.headers);
                
                // Check if response is OK
                if (!response.ok) {
                    console.error('HTTP error! status:', response.status);
                    return response.text().then(text => {
                        console.error('Response text:', text);
                        throw new Error(`HTTP error! status: ${response.status}, body: ${text.substring(0, 200)}`);
                    });
                }
                
                // Try to parse as JSON
                return response.text().then(text => {
                    console.log('Raw response text:', text);
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('JSON parse error:', e);
                        console.error('Response was not valid JSON:', text);
                        throw new Error('Invalid JSON response: ' + text.substring(0, 200));
                    }
                });
            })
            .then(data => {
                console.log('Parsed data:', data);
                
                if (data.success) {
                    alert(`Successfully dispatched ${data.dispatched_count} orders!` +
                        (data.tracking_numbers ? '\nTracking numbers assigned.' : ''));
                    closeBulkDispatchModal();
                    clearBulkSelection();
                    // Reload the page to reflect changes
                    window.location.reload();
                } else {
                    console.error('Server returned error:', data.message);
                    alert('Error: ' + (data.message || 'Failed to dispatch orders'));
                }
            })
            .catch(error => {
                console.error('=== FETCH ERROR ===');
                console.error('Error type:', error.name);
                console.error('Error message:', error.message);
                console.error('Error stack:', error.stack);
                alert('An error occurred while dispatching orders.\n\nError: ' + error.message + '\n\nCheck browser console for details.');
            })
            .finally(() => {
                // Reset button state
                submitBtn.innerHTML =
                    '<i class="fas fa-truck me-1"></i>Confirm Bulk Dispatch';
                submitBtn.disabled = !carrier;
            });
    });
}

        // Close modal when clicking outside
        if (bulkModal) {
            bulkModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeBulkDispatchModal();
                }
            });
        }

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const modal = document.getElementById('bulkDispatchModal');
                if (modal && modal.style.display === 'flex') {
                    closeBulkDispatchModal();
                }
            }
        });
    });

    /**
     * Toggle Action Buttons Based on Bulk Selection State
     */
    function toggleActionButtons() {
        const selectedOrdersCount = document.querySelectorAll('.order-checkbox:checked').length;
        const allActionButtons = document.querySelectorAll('.action-buttons-group');
        const bulkActionsBar = document.getElementById('bulkActionsBar');

        if (selectedOrdersCount > 0) {
            // Disable all action buttons when bulk selection is active
            allActionButtons.forEach(buttonGroup => {
                buttonGroup.style.opacity = '0.5';
                buttonGroup.style.pointerEvents = 'none';
                buttonGroup.style.cursor = 'not-allowed';
            });

            // Add a visual indicator
            allActionButtons.forEach(buttonGroup => {
                if (!buttonGroup.querySelector('.bulk-selection-overlay')) {
                    const overlay = document.createElement('div');
                    overlay.className = 'bulk-selection-overlay';
                    overlay.style.cssText = `
                    position: absolute;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background: rgba(0,0,0,0.1);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 10px;
                    color: #666;
                    z-index: 1;
                `;
                    overlay.innerHTML = '<i class=""></i>';

                    // Make button group relative for overlay positioning
                    buttonGroup.style.position = 'relative';
                    buttonGroup.appendChild(overlay);
                }
            });

        } else {
            // Re-enable all action buttons when no bulk selection
            allActionButtons.forEach(buttonGroup => {
                buttonGroup.style.opacity = '1';
                buttonGroup.style.pointerEvents = 'auto';
                buttonGroup.style.cursor = 'default';

                // Remove overlay
                const overlay = buttonGroup.querySelector('.bulk-selection-overlay');
                if (overlay) {
                    overlay.remove();
                }
            });
        }
    }

    // Update the existing updateBulkSelection function to include action button control
    // Replace your existing updateBulkSelection function with this enhanced version:
    function updateBulkSelection() {
        const allCheckboxes = document.querySelectorAll('.order-checkbox:not(:disabled)');
        const orderCheckboxes = document.querySelectorAll('.order-checkbox:checked:not(:disabled)');
        const selectedCount = orderCheckboxes.length;
        const bulkActionsBar = document.getElementById('bulkActionsBar');
        const selectedCountElement = document.getElementById('selectedCount');
        const selectAllCheckbox = document.getElementById('selectAll');

        // Update selected count
        if (selectedCountElement) {
            selectedCountElement.textContent = selectedCount;
        }

        // Show/hide bulk actions bar
        if (bulkActionsBar) {
            bulkActionsBar.style.display = selectedCount > 0 ? 'flex' : 'none';
        }

        // Update select all checkbox state
        if (selectAllCheckbox) {
            selectAllCheckbox.checked = selectedCount === allCheckboxes.length && allCheckboxes.length > 0;
            selectAllCheckbox.indeterminate = selectedCount > 0 && selectedCount < allCheckboxes.length;
        }

        // Store selected orders for bulk dispatch
        selectedOrdersForBulkDispatch = Array.from(orderCheckboxes).map(checkbox => checkbox.value);

        // NEW: Toggle action buttons based on selection state
        toggleActionButtons();
    }

    // Print order function
    function printOrder(orderId) {
        if (!orderId || orderId.trim() === '') {
            toastManager.warning('Order ID is required to print order.');
            return;
        }

        console.log('Printing Order ID:', orderId);

        // Construct the print URL
        const printUrl = 'download_order_print.php?id=' + encodeURIComponent(orderId.trim());

        // Open print page in new window
        const printWindow = window.open(printUrl, '_blank');

        // Optional: Auto-print when page loads (uncomment if needed)
        // printWindow.onload = function() {
        //     printWindow.print();
        // };
    }
   /**
 * Open API Dispatch Modal - UPDATED WITH TENANT FILTERING
 */
function openApiDispatchModal() {
    const selectedOrders = document.querySelectorAll('.order-checkbox:checked');

    if (selectedOrders.length === 0) {
        alert('Please select at least one order to dispatch via API.');
        return;
    }

    console.log('Opening API dispatch modal for', selectedOrders.length, 'orders');

    // Update selected orders list in modal
    updateApiSelectedOrdersList();

    // Reset form
    document.getElementById('api-dispatch-form').reset();
    document.getElementById('api_tracking_numbers_display').innerHTML =
        '<span class="text-muted">Select a courier to see available tracking numbers</span>';
    document.getElementById('api-dispatch-submit-btn').disabled = true;
    document.getElementById('existingTrackingSection').style.display = 'none';

    // Show modal
    const modal = document.getElementById('apiDispatchModal');
    modal.style.display = 'flex';
    document.body.style.overflow = 'clip';

    // **NEW: Load API couriers for selected orders**
    loadApiCouriersForOrders();
}
/**
 * NEW FUNCTION: Load API Couriers Based on Selected Orders
 */
function loadApiCouriersForOrders() {
    const carrierSelect = document.getElementById('api_carrier');
    const helpText = document.querySelector('#api_carrier + .form-text');
    const selectedOrders = Array.from(document.querySelectorAll('.order-checkbox:checked'))
        .map(cb => cb.value);

    console.log('Loading API couriers for orders:', selectedOrders);

    // Show loading state
    carrierSelect.innerHTML = '<option value="">Loading API couriers...</option>';
    carrierSelect.disabled = true;
    if (helpText) {
        helpText.textContent = 'Checking available API couriers for selected orders...';
        helpText.className = 'form-text text-muted';
    }

    // Fetch API couriers based on selected order IDs
    fetch('get_api_couriers.php?order_ids=' + encodeURIComponent(JSON.stringify(selectedOrders)), {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => {
        console.log('API Courier fetch response status:', response.status);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        console.log('API Courier fetch response:', data);
        
        if (data.success) {
            // Clear loading option
            carrierSelect.innerHTML = '<option value="" selected disabled>Select API courier service</option>';
            
            // Add API couriers to dropdown
            if (data.couriers && data.couriers.length > 0) {
                data.couriers.forEach(courier => {
                    const option = document.createElement('option');
                    option.value = courier.courier_id;
                    
                    // Show API capabilities in the option text
                    let capabilities = [];
                    if (courier.has_api_new === 1) capabilities.push('New');
                    if (courier.has_api_existing === 1) capabilities.push('Existing');
                    
                    option.textContent = `${courier.courier_name} (ID: ${courier.co_id}) - [${capabilities.join(', ')}]`;
                    
                    // Store capabilities and co_id as data attributes
                    option.setAttribute('data-has-new', courier.has_api_new);
                    option.setAttribute('data-has-existing', courier.has_api_existing);
                    option.setAttribute('data-co-id', courier.co_id);
                    
                    carrierSelect.appendChild(option);
                });
                carrierSelect.disabled = false;
                
                if (helpText) {
                    helpText.textContent = `${data.courier_count} API courier(s) available for selected orders (Tenant: ${data.tenant_id})`;
                    helpText.className = 'form-text text-success';
                }
            } else {
                carrierSelect.innerHTML = '<option value="" disabled>No API couriers available for this tenant</option>';
                carrierSelect.disabled = true;
                
                if (helpText) {
                    helpText.textContent = 'No API couriers available for the selected orders. Please contact administrator.';
                    helpText.className = 'form-text text-danger';
                }
                
                alert('No API couriers are configured for the selected orders\' tenant. Please contact your system administrator.');
            }
        } else {
            // Handle error - especially multi-tenant selection
            carrierSelect.innerHTML = '<option value="" disabled>Error loading API couriers</option>';
            carrierSelect.disabled = true;
            
            if (helpText) {
                helpText.textContent = data.message || 'Error loading API couriers';
                helpText.className = 'form-text text-danger';
            }
            
            // Show detailed error message
            alert('Error: ' + (data.message || 'Failed to load API couriers'));
        }
    })
    .catch(error => {
        console.error('Error loading API couriers:', error);
        carrierSelect.innerHTML = '<option value="" disabled>Network error</option>';
        carrierSelect.disabled = true;
        
        if (helpText) {
            helpText.textContent = 'Network error. Please check your connection and try again.';
            helpText.className = 'form-text text-danger';
        }
        
        alert('Network error occurred while loading API couriers. Please check your connection and try again.');
    });
}
    /**
     * Close API Dispatch Modal
     */
    function closeApiDispatchModal() {
        const modal = document.getElementById('apiDispatchModal');
        modal.style.display = 'none';
        document.body.style.overflow = '';

        // Reset form
        document.getElementById('api-dispatch-form').reset();
        document.getElementById('api_tracking_numbers_display').innerHTML =
            '<span class="text-muted">Select a courier to see available tracking numbers</span>';
        document.getElementById('api-dispatch-submit-btn').disabled = true;
        document.getElementById('existingTrackingSection').style.display = 'none';
    }

    /**
     * Update Selected Orders List in API Modal
     */
    function updateApiSelectedOrdersList() {
        const selectedOrders = document.querySelectorAll('.order-checkbox:checked');
        const selectedOrdersList = document.getElementById('apiSelectedOrdersList');
        const apiSelectedCount = document.getElementById('apiSelectedCount');

        if (apiSelectedCount) {
            apiSelectedCount.textContent = selectedOrders.length;
        }

        if (selectedOrdersList) {
            let ordersHtml = '<div class="selected-orders-list">';

            selectedOrders.forEach((checkbox, index) => {
                const orderId = checkbox.value;
                const row = checkbox.closest('tr');
                const customerName = row.querySelector('.customer-name').textContent.trim();

                ordersHtml += `
                <div class="selected-order-item">
                    <span class="order-number">${index + 1}.</span>
                    <span class="order-id">${orderId}</span>
                    <span class="customer-name">${customerName}</span>
                </div>
            `;
            });

            ordersHtml += '</div>';
            selectedOrdersList.innerHTML = ordersHtml;
        }
    }
    /**
     * Fetch Tracking Numbers for API Dispatch (existing parcels)
     */
  /**
 * Fetch Tracking Numbers for API Dispatch (existing parcels) - UPDATED WITH TENANT FILTERING
 */
function fetchApiTrackingNumbers(courierId) {
    const trackingDisplay = document.getElementById('api_tracking_numbers_display');
    const submitBtn = document.getElementById('api-dispatch-submit-btn');
    const selectedCheckboxes = document.querySelectorAll('.order-checkbox:checked');
    const selectedCount = selectedCheckboxes.length;

    console.log('Fetching tracking numbers for courier:', courierId, 'Count:', selectedCount);

    if (!courierId) {
        trackingDisplay.innerHTML = '<span class="text-muted">Select a courier to see available tracking numbers</span>';
        submitBtn.disabled = true;
        return;
    }

    if (selectedCount === 0) {
        trackingDisplay.innerHTML = '<span class="text-warning"><i class="fas fa-exclamation-triangle me-1"></i>No orders selected</span>';
        submitBtn.disabled = true;
        return;
    }

    // Collect order ID + customer name from each selected checkbox row
    const selectedOrders = Array.from(selectedCheckboxes).map(checkbox => {
        const row = checkbox.closest('tr');
        const orderId = checkbox.value;
        const customerCell = row ? row.querySelector('td.customer-name') : null;
        const customerName = customerCell ? customerCell.textContent.trim().split('\n')[0].trim() : '';
        return { orderId, customerName };
    });

    // Pass first order_id for tenant-specific tracking numbers
    const firstOrderId = selectedCheckboxes[0].value;

    // Show loading state
    trackingDisplay.innerHTML = '<span class="text-info"><i class="fas fa-spinner fa-spin me-1"></i>Loading tracking numbers...</span>';
    submitBtn.disabled = true;

    // Include order_id for tenant-specific tracking
    fetch(`get_api_tracking_numbers.php?courier_id=${courierId}&count=${selectedCount}&order_id=${encodeURIComponent(firstOrderId)}`, {
        method: 'GET',
        headers: { 'Content-Type': 'application/json' }
    })
    .then(response => {
        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
        return response.json();
    })
    .then(data => {
        console.log('API Response:', data);

        const trackingNumbers = data.tracking_numbers || [];
        const availableCount = trackingNumbers.length;

        // No tracking numbers at all — block dispatch
        if (data.status === 'error' || availableCount === 0) {
            trackingDisplay.innerHTML =
                `<div class="info-box info-box--warning">
                    <i class="fas fa-exclamation-circle"></i>
                    ${data.message || 'No tracking numbers available for this courier.'}
                </div>`;
            submitBtn.disabled = true;
            return;
        }

        // Build paired table rows
        let rowsHtml = '';
        selectedOrders.forEach((order, index) => {
            const trackingNumber = trackingNumbers[index] || null;
            if (trackingNumber) {
                rowsHtml += `
                    <tr>
                        <td style="padding:5px 8px;text-align:center;border-bottom:1px solid #eee;">${index + 1}</td>
                        <td style="padding:5px 8px;text-align:left;font-weight:600;border-bottom:1px solid #eee;">${order.orderId}</td>
                        <td style="padding:5px 8px;text-align:left;border-bottom:1px solid #eee;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${order.customerName}">${order.customerName}</td>
                        <td style="padding:5px 8px;text-align:left;font-family:monospace;color:#155724;border-bottom:1px solid #eee;"><strong>${trackingNumber}</strong></td>
                    </tr>`;
            } else {
                rowsHtml += `
                    <tr style="background:#fff3cd;">
                        <td style="padding:5px 8px;text-align:center;border-bottom:1px solid #eee;">${index + 1}</td>
                        <td style="padding:5px 8px;text-align:left;font-weight:600;border-bottom:1px solid #eee;">${order.orderId}</td>
                        <td style="padding:5px 8px;text-align:left;border-bottom:1px solid #eee;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${order.customerName}">${order.customerName}</td>
                        <td style="padding:5px 8px;text-align:left;color:#856404;border-bottom:1px solid #eee;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><i class="fas fa-exclamation-triangle me-1"></i>No tracking -- skipped</td>
                    </tr>`;
            }
        });

        const isSufficient = availableCount >= selectedCount;
        const headerBg   = isSufficient ? '#d4edda' : '#fff3cd';
        const headerIcon = isSufficient ? '<i class="fas fa-check-circle me-1"></i>' : '<i class="fas fa-exclamation-triangle me-1"></i>';
        const headerText = isSufficient
            ? `<strong>${availableCount}</strong> tracking numbers available -- all ${selectedCount} orders will be dispatched`
            : `Only <strong>${availableCount}</strong> of ${selectedCount} selected orders will be dispatched (insufficient tracking numbers)`;

        trackingDisplay.innerHTML = `
            <div style="background:${headerBg};padding:8px 10px;border-radius:4px 4px 0 0;font-size:13px;">
                ${headerIcon}${headerText}
            </div>
            <div style="max-height:220px;overflow-y:auto;">
                <table style="width:100%;table-layout:fixed;border-collapse:collapse;font-size:13px;background:#fff;border:1px solid #dee2e6;">
                    <colgroup>
                        <col style="width:40px;">
                        <col style="width:100px;">
                        <col>
                        <col style="width:180px;">
                    </colgroup>
                    <thead>
                        <tr style="background:#f1f3f5;border-bottom:2px solid #dee2e6;">
                            <th style="padding:6px 8px;text-align:center;">#</th>
                            <th style="padding:6px 8px;text-align:left;">Order ID</th>
                            <th style="padding:6px 8px;text-align:left;">Customer</th>
                            <th style="padding:6px 8px;text-align:left;">Tracking Number</th>
                        </tr>
                    </thead>
                    <tbody>${rowsHtml}</tbody>
                </table>
            </div>`;

        // Allow dispatch as long as at least one order can be dispatched
        submitBtn.disabled = false;
    })
    .catch(error => {
        console.error('Error fetching tracking numbers:', error);
        trackingDisplay.innerHTML =
            `<div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle me-1"></i>
                <strong>Network Error:</strong> Could not load tracking numbers. Please check your connection and try again.
            </div>`;
        submitBtn.disabled = true;
    });
}

// Enhanced API Dispatch functionality with dynamic dispatch type control
  document.addEventListener('DOMContentLoaded', function() {
    const apiCarrierSelect = document.getElementById('api_carrier');
    const apiDispatchForm = document.getElementById('api-dispatch-form');
    const apiModal = document.getElementById('apiDispatchModal');
    const dispatchTypeRadios = document.querySelectorAll('input[name="api_dispatch_type"]');
    const newParcelOption = document.getElementById('newParcel')?.closest('.form-check');
    const existingParcelOption = document.getElementById('existingParcel')?.closest('.form-check');
    const dispatchTypeContainer = document.querySelector('.dispatch-type-options');

    // Store courier API capabilities - populated from PHP data
    const courierCapabilities = {
        <?php
       // Fetch all courier capabilities and create JavaScript object
        $capabilities_query = "SELECT courier_id, has_api_new, has_api_existing FROM couriers WHERE status = 'active'";
        $capabilities_result = $conn->query($capabilities_query);
        
        if ($capabilities_result && $capabilities_result->num_rows > 0) {
            $capabilities_array = [];
            while($cap = $capabilities_result->fetch_assoc()) {
                $capabilities_array[] = $cap['courier_id'] . ': {has_api_new: ' . intval($cap['has_api_new']) . ', has_api_existing: ' . intval($cap['has_api_existing']) . '}';
            }
            echo implode(',', $capabilities_array);
        }
        ?>
    };

         /**
     * Get courier capabilities from embedded data
     */
    function getCourierCapabilities(courierId) {
        if (!courierId) {
            // Hide dispatch type section if no courier selected
            if (dispatchTypeContainer) {
                dispatchTypeContainer.style.display = 'none';
            }
            const submitBtn = document.getElementById('api-dispatch-submit-btn');
            if (submitBtn) submitBtn.disabled = true;
            return;
        }

        // Check selected option's data attributes first for tenant-specific capabilities
        const selectedOption = apiCarrierSelect ? apiCarrierSelect.options[apiCarrierSelect.selectedIndex] : null;
        let capabilities = null;
        if (selectedOption && (selectedOption.hasAttribute('data-has-new') || selectedOption.hasAttribute('data-has-existing'))) {
            capabilities = {
                has_api_new: parseInt(selectedOption.getAttribute('data-has-new') || '0'),
                has_api_existing: parseInt(selectedOption.getAttribute('data-has-existing') || '0')
            };
        } else {
            capabilities = courierCapabilities[courierId];
        }

        if (capabilities) {
            updateDispatchTypeOptions(capabilities);
        } else {
            // Courier not found, hide dispatch type section
            if (dispatchTypeContainer) {
                dispatchTypeContainer.style.display = 'none';
            }
            const submitBtn = document.getElementById('api-dispatch-submit-btn');
            if (submitBtn) submitBtn.disabled = true;
        }
    }

     /**
     * Update dispatch type options based on courier capabilities
     */
    function updateDispatchTypeOptions(capabilities) {
        const hasNew = capabilities.has_api_new === 1;
        const hasExisting = capabilities.has_api_existing === 1;

        // Show/hide options based on capabilities
        if (newParcelOption) {
            newParcelOption.style.display = hasNew ? 'block' : 'none';
        }
        if (existingParcelOption) {
            existingParcelOption.style.display = hasExisting ? 'block' : 'none';
        }

        // If neither option is available, hide the entire dispatch type section
        if (!hasNew && !hasExisting) {
            if (dispatchTypeContainer) {
                dispatchTypeContainer.style.display = 'none';
            }
            const submitBtn = document.getElementById('api-dispatch-submit-btn');
            if (submitBtn) submitBtn.disabled = true;
            return;
        }

        // Show the dispatch type section
        if (dispatchTypeContainer) {
            dispatchTypeContainer.style.display = 'block';
        }

        // Auto-select the available option if only one is available
        const existingTrackingSection = document.getElementById('existingTrackingSection');
        
        if (hasNew && !hasExisting) {
            const newParcelRadio = document.getElementById('newParcel');
            if (newParcelRadio) newParcelRadio.checked = true;
            if (existingTrackingSection) existingTrackingSection.style.display = 'none';
        } else if (!hasNew && hasExisting) {
            const existingParcelRadio = document.getElementById('existingParcel');
            if (existingParcelRadio) existingParcelRadio.checked = true;
            if (existingTrackingSection) existingTrackingSection.style.display = 'block';
            // Fetch tracking numbers for existing parcels
            if (apiCarrierSelect && apiCarrierSelect.value) {
                fetchApiTrackingNumbers(apiCarrierSelect.value);
            }
        } else if (hasNew && hasExisting) {
            // Both options available, default to 'new'
            const newParcelRadio = document.getElementById('newParcel');
            if (newParcelRadio) newParcelRadio.checked = true;
            if (existingTrackingSection) existingTrackingSection.style.display = 'none';
        }

        // Enable submit button since we have valid options
        updateSubmitButtonState();
    }

    /**
     * Update submit button state based on selections
     */
    function updateSubmitButtonState() {
        const submitBtn = document.getElementById('api-dispatch-submit-btn');
        if (!submitBtn) return;
        
        const selectedOrders = document.querySelectorAll('.order-checkbox:checked').length;
        const courierSelected = apiCarrierSelect ? apiCarrierSelect.value : null;
        const dispatchTypeSelected = document.querySelector('input[name="api_dispatch_type"]:checked');

        // Enable button only if we have courier, orders, and valid dispatch type
        const canSubmit = courierSelected && selectedOrders > 0 && dispatchTypeSelected;
        submitBtn.disabled = !canSubmit;
    }

    // Handle courier selection change
    if (apiCarrierSelect) {
        apiCarrierSelect.addEventListener('change', function() {
            const selectedCourierId = this.value;

            if (selectedCourierId) {
                // Get courier capabilities and update UI
                getCourierCapabilities(selectedCourierId);
            } else {
                // No courier selected, hide dispatch options
                if (dispatchTypeContainer) {
                    dispatchTypeContainer.style.display = 'none';
                }
                const existingTrackingSection = document.getElementById('existingTrackingSection');
                if (existingTrackingSection) {
                    existingTrackingSection.style.display = 'none';
                }
                const submitBtn = document.getElementById('api-dispatch-submit-btn');
                if (submitBtn) submitBtn.disabled = true;
            }
        });
    }

    // Handle dispatch type change
    if (dispatchTypeRadios) {
        dispatchTypeRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                const existingTrackingSection = document.getElementById('existingTrackingSection');
                
                if (this.value === 'existing') {
                    if (existingTrackingSection) {
                        existingTrackingSection.style.display = 'block';
                    }
                    // Fetch tracking numbers if courier is selected
                    if (apiCarrierSelect && apiCarrierSelect.value) {
                        fetchApiTrackingNumbers(apiCarrierSelect.value);
                    }
                } else {
                    if (existingTrackingSection) {
                        existingTrackingSection.style.display = 'none';
                    }
                }
                updateSubmitButtonState();
            });
        });
    }

  // Handle API dispatch form submission - ENHANCED VERSION WITH CO_ID
if (apiDispatchForm) {
    apiDispatchForm.addEventListener('submit', function(e) {
        e.preventDefault();

        const carrier = apiCarrierSelect ? apiCarrierSelect.value : null;
        const dispatchTypeElement = document.querySelector('input[name="api_dispatch_type"]:checked');
        const dispatchType = dispatchTypeElement ? dispatchTypeElement.value : null;
        const dispatchNotesField = document.getElementById('api_dispatch_notes');
        const dispatchNotes = dispatchNotesField ? dispatchNotesField.value : '';
        const submitBtn = document.getElementById('api-dispatch-submit-btn');
        const selectedOrders = Array.from(document.querySelectorAll('.order-checkbox:checked'))
            .map(cb => cb.value);

        // Validation
        if (!carrier) {
            alert('Please select an API courier service');
            return;
        }

        if (!dispatchType) {
            alert('Please select a dispatch type');
            return;
        }

        if (selectedOrders.length === 0) {
            alert('No orders selected for dispatch');
            return;
        }

        // Additional validation for existing parcels
        if (dispatchType === 'existing') {
            const trackingDisplay = document.getElementById('api_tracking_numbers_display');
            if (trackingDisplay && (
                trackingDisplay.textContent.includes('No tracking numbers available') ||
                trackingDisplay.textContent.includes('Select a courier') ||
                trackingDisplay.textContent.includes('Insufficient')
            )) {
                alert('Please ensure you have enough tracking numbers available');
                return;
            }
        }

        // Confirm action
        const actionText = dispatchType === 'new' ? 'create new API parcels' : 'assign existing tracking numbers';
        if (!confirm(`Are you sure you want to ${actionText} for ${selectedOrders.length} orders?`)) {
            return;
        }

        // Show loading state
        if (submitBtn) {
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';
            submitBtn.disabled = true;
        }

        // ============================================
        // CRITICAL: Extract co_id from selected courier option data attribute or text
        // ============================================
        const selectedOption = apiCarrierSelect.options[apiCarrierSelect.selectedIndex];
        const coId = selectedOption ? (selectedOption.getAttribute('data-co-id') || selectedOption.textContent.match(/\(ID:\s*(\d+)\)/)?.[1]) : null;
        
        console.log('=== CO_ID EXTRACTION DEBUG ===');
        console.log('Selected option text:', selectedOption.textContent);
        console.log('Extracted co_id:', coId);

        // Create FormData object
        const formData = new FormData();
        formData.append('order_ids', JSON.stringify(selectedOrders));
        formData.append('carrier_id', carrier);
        
        // ============================================
        // ADD CO_ID TO FORM DATA
        // ============================================
        if (coId) {
            formData.append('co_id', coId);
            console.log('✅ Added co_id to form data:', coId);
        } else {
            console.error('❌ Could not extract co_id from courier selection!');
            console.error('Option text was:', selectedOption.textContent);
        }
        
        formData.append('dispatch_type', dispatchType);
        formData.append('dispatch_notes', dispatchNotes);
        formData.append('action', 'api_dispatch_orders');

        // ENHANCED: Determine which endpoint to use with better logic
        let endpoint = '';
        const carrierIdInt = parseInt(carrier);
        
        // Courier ID to endpoint mapping
        const courierEndpoints = {
            12: { // Koombiyo - EXISTING ONLY
                new: null,
                existing: 'koombiyo_bulk_existing_parcel_api.php'
            },
            11: { // FDE/Fardar - BOTH
                new: 'fde_bulk_new_parcel_api.php',
                existing: 'fde_bulk_existing_parcel_api.php'
            },
            13: { // TransExpress - BOTH
                new: 'transexpress_bulk_new_parcel_api.php',
                existing: 'transexpress_bulk_existing_parcel_api.php'
            },
            14: { // Royal Express - EXISTING ONLY
                new: null,
                existing: 'royalexpress_bulk_existing_parcel_api.php'
            }
        };

        // Get endpoint based on courier and dispatch type
        if (courierEndpoints[carrierIdInt]) {
            endpoint = courierEndpoints[carrierIdInt][dispatchType];
            
            if (!endpoint) {
                alert(`${dispatchType === 'new' ? 'New parcel creation' : 'Existing parcel'} is not supported for this courier`);
                if (submitBtn) {
                    submitBtn.innerHTML = '<i class="fas fa-cloud-upload-alt me-1"></i>Confirm API Dispatch';
                    submitBtn.disabled = false;
                }
                return;
            }
        } else {
            alert('Unknown courier selected. Please contact system administrator.');
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="fas fa-cloud-upload-alt me-1"></i>Confirm API Dispatch';
                submitBtn.disabled = false;
            }
            return;
        }

        console.log('=== API DISPATCH DEBUG ===');
        console.log('Courier ID:', carrierIdInt);
        console.log('CO_ID:', coId);
        console.log('Dispatch Type:', dispatchType);
        console.log('Endpoint:', endpoint);
        console.log('Selected Orders:', selectedOrders);
        console.log('Form Data:', {
            order_ids: selectedOrders,
            carrier_id: carrier,
            co_id: coId,
            dispatch_type: dispatchType,
            dispatch_notes: dispatchNotes,
            action: 'api_dispatch_orders'
        });

        // Send the request
        fetch(endpoint, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                console.log('Response status:', response.status);
                console.log('Response headers:', response.headers);
                
                if (!response.ok) {
                    return response.text().then(text => {
                        console.error('HTTP error! status:', response.status);
                        console.error('Response text:', text.substring(0, 500));
                        throw new Error(`HTTP error! status: ${response.status}`);
                    });
                }
                
                return response.text().then(text => {
                    console.log('Raw response text:', text);
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('JSON parse error:', e);
                        console.error('Response was not valid JSON:', text.substring(0, 500));
                        throw new Error('Invalid JSON response from server');
                    }
                });
            })
            .then(data => {
                console.log('Parsed response data:', data);
                
                if (data.success) {
                    let message = `Successfully processed ${data.processed_count || selectedOrders.length} orders via API!`;
                    
                    // Add co_id info if available
                    // if (data.co_id) {
                    //     message += `\nCourier CO_ID: ${data.co_id}`;
                    // }
                    
                    // Add tracking info if available
                    if (data.processed_orders && data.processed_orders.length > 0) {
                        const trackingNumbers = data.processed_orders.map(o => o.tracking_number).join(', ');
                        message += `\nTracking Numbers: ${trackingNumbers}`;
                    }
                    
                    // Add failed orders info if any
                    if (data.failed_count > 0) {
                        message += `\n\n⚠️ ${data.failed_count} orders failed.`;
                        if (data.failed_orders && data.failed_orders.length > 0) {
                            message += '\n\nFailed Orders:';
                            data.failed_orders.forEach(f => {
                                message += `\n- Order ${f.order_id}: ${f.error}`;
                            });
                        }
                    }
                    
                    alert(message);
                    closeApiDispatchModal();
                    clearBulkSelection();
                    window.location.reload();
                } else {
                    console.error('Server returned error:', data);
                    let errorMsg = 'Error: ' + (data.message || 'Failed to process orders via API');
                    
                    // Add detailed error info if available
                    if (data.failed_orders && data.failed_orders.length > 0) {
                        errorMsg += '\n\nDetails:';
                        data.failed_orders.forEach(f => {
                            errorMsg += `\n- Order ${f.order_id}: ${f.error}`;
                        });
                    }
                    
                    alert(errorMsg);
                }
            })
            .catch(error => {
                console.error('=== FETCH ERROR ===');
                console.error('Error type:', error.name);
                console.error('Error message:', error.message);
                console.error('Error stack:', error.stack);
                alert('An error occurred while processing orders via API.\n\nError: ' + error.message + '\n\nCheck browser console for details.');
            })
            .finally(() => {
                // Reset button state
                if (submitBtn) {
                    submitBtn.innerHTML = '<i class="fas fa-cloud-upload-alt me-1"></i>Confirm API Dispatch';
                    submitBtn.disabled = false;
                }
            });
    });
}

    // Close modal when clicking outside
    if (apiModal) {
        apiModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeApiDispatchModal();
            }
        });
    }

    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('apiDispatchModal');
            if (modal && modal.style.display === 'flex') {
                closeApiDispatchModal();
            }
        }
    });

    // Listen for order selection changes to update submit button
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('order-checkbox')) {
            updateSubmitButtonState();
        }
    });

    // Initial state - hide dispatch type section until courier is selected
    if (dispatchTypeContainer) {
        dispatchTypeContainer.style.display = 'none';
    }
});
    </script>

    <!-- Include Footer and Scripts -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/include/footer.php'); ?>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/include/scripts.php'); ?>

</body>

</html>