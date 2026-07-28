<?php
/**
 * Dispatched Orders Management System
 * This page displays orders with status 'dispatch' only for individual and leads interface
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
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

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

// If still no user data, redirect to login
if ($current_user_id == 0) {
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

/**
 * SEARCH AND PAGINATION PARAMETERS
 */
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$order_id_filter = isset($_GET['order_id_filter']) ? trim($_GET['order_id_filter']) : '';
$customer_name_filter = isset($_GET['customer_name_filter']) ? trim($_GET['customer_name_filter']) : '';
$tracking_id = isset($_GET['tracking_id']) ? trim($_GET['tracking_id']) : '';
$user_id_filter = isset($_GET['user_id_filter']) ? trim($_GET['user_id_filter']) : '';
$updated_date_from = isset($_GET['updated_date_from']) ? trim($_GET['updated_date_from']) : '';
$updated_date_to = isset($_GET['updated_date_to']) ? trim($_GET['updated_date_to']) : '';
$tenant_id_filter = isset($_GET['tenant_id_filter']) ? trim($_GET['tenant_id_filter']) : '';

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;


// NEW: Role-based access control condition
$roleBasedCondition = "";
if ($current_user_role != 1) {
    // Non-admin users can only see their own orders
    $roleBasedCondition = " AND i.user_id = $current_user_id";
}else{
    $roleBasedCondition = "";
}

/**
 * DATABASE QUERIES
 * Main query to fetch orders with customer and payment information
 * Filtered for individual and leads interface and dispatch status only
 */

// Base SQL for counting total records - ONLY DISPATCH STATUS
$countSql = "SELECT COUNT(*) as total FROM order_header i 
             WHERE i.interface IN ('individual', 'leads') 
             AND i.status = 'dispatch'$roleBasedCondition";


// Main query with all required joins - ONLY DISPATCH STATUS - UPDATED with customer name fallback
$sql = "SELECT i.*, 
                -- Duplicate order count based on mobile and product_code
                (SELECT COUNT(*) FROM order_header o2 WHERE o2.mobile = i.mobile AND o2.product_code = i.product_code AND  o2.status = 'dispatch') as duplicate_count,
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
               
               -- User who created the order
               u2.name as user_name,
               t.company_name,
               cr.courier_name

        FROM order_header i 
        LEFT JOIN payments p ON i.order_id = p.order_id
        LEFT JOIN users u1 ON p.pay_by = u1.id
        LEFT JOIN users u2 ON i.user_id = u2.id
        LEFT JOIN customers c ON i.customer_id = c.customer_id
        LEFT JOIN tenants t ON i.tenant_id = t.tenant_id
        LEFT JOIN couriers cr ON i.co_id = cr.co_id
         WHERE i.interface IN ('individual', 'leads') AND i.status = 'dispatch' ";

// Add tenant filter for non-main admin users
if ($is_main_admin == 1){
   // Add ordering and pagination
   $sql .= "$roleBasedCondition";
}else{
    // Add ordering and pagination
    $sql .= "  AND i.tenant_id = $tenant_id $roleBasedCondition";
}


// Build search conditions
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
                        i.tracking_number LIKE '%$searchTerm%' OR
                        i.pay_status LIKE '%$searchTerm%' OR
                        t.company_name LIKE '%$searchTerm%' OR
                        u2.name LIKE '%$searchTerm%')";
}

// Specific Order ID filter
if (!empty($order_id_filter)) {
    $orderIdTerm = $conn->real_escape_string($order_id_filter);
    $searchConditions[] = "i.order_id LIKE '%$orderIdTerm%'";
}

// Specific Customer Name filter - UPDATED
if (!empty($customer_name_filter)) {
    $customerNameTerm = $conn->real_escape_string($customer_name_filter);
    $searchConditions[] = "i.full_name LIKE '%$customerNameTerm%'";
}

// Tracking ID filter
if (!empty($tracking_id)) {
    $trackingTerm = $conn->real_escape_string($tracking_id);
    $searchConditions[] = "i.tracking_number LIKE '%$trackingTerm%'";
}

// Specific User ID filter - MODIFIED: Apply role-based restrictions
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

// Updated Date range filter
if (!empty($updated_date_from)) {
    $updatedDateFromTerm = $conn->real_escape_string($updated_date_from);
    $searchConditions[] = "DATE(i.updated_at) >= '$updatedDateFromTerm'";
}

if (!empty($updated_date_to)) {
    $updatedDateToTerm = $conn->real_escape_string($updated_date_to);
    $searchConditions[] = "DATE(i.updated_at) <= '$updatedDateToTerm'";
}

// Specific tenant ID filter
if (!empty($tenant_id_filter)) {
    $tenantIdTerm = $conn->real_escape_string($tenant_id_filter);
    $searchConditions[] = "i.tenant_id = '$tenantIdTerm'";
}

// Apply all search conditions
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

// Fetch all users for the User ID dropdown
// Fetch users for the dropdown based on permissions
if ($is_main_admin == 1 && $current_user_role == 1) {
    // Super Main Admin can see all users
    $usersQuery = "SELECT id, name FROM users ORDER BY name ASC";
} else {
    // Regular Admins (or others) can only see users in their tenant
    $usersQuery = "SELECT id, name FROM users WHERE tenant_id = " . (int)$tenant_id . " ORDER BY name ASC";
}
$usersResult = $conn->query($usersQuery);

// Include navigation components


// Get unique tenants for filter dropdown
$tenant_sql = "SELECT DISTINCT tenant_id, company_name 
               FROM tenants";
$tenant_result = $conn->query($tenant_sql);
$tenants = $tenant_result->fetch_all(MYSQLI_ASSOC);

?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr"
    data-pc-theme="light">

<head>
    <title>Order Management Admin Portal - Dispatched Orders</title>

    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>

    <!-- Stylesheets -->
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/orders.css" id="main-style-link" />
    <style>
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
                        <h5 class="mb-0 font-medium">Dispatched Orders</h5>
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
                            <label for="tracking_id">Tracking ID</label>
                            <input type="text" id="tracking_id" name="tracking_id" placeholder="Enter tracking ID"
                                value="<?php echo htmlspecialchars($tracking_id); ?>">
                        </div>

                        <div class="form-group">
                            <label for="updated_date_from">Updated From</label>
                            <input type="date" id="updated_date_from" name="updated_date_from"
                                value="<?php echo htmlspecialchars($updated_date_from); ?>">
                        </div>

                        <div class="form-group">
                            <label for="updated_date_to">Updated To</label>
                            <input type="date" id="updated_date_to" name="updated_date_to"
                                value="<?php echo htmlspecialchars($updated_date_to); ?>">
                        </div>

                        <?php if ($is_admin && $is_main_admin) { ?>
                        <div class="form-group">
                            <label for="tenant_id_filter">Tenant ID</label>
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
                        <?php } else { ?>
                        <?php } ?>

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
                    <div class="order-count-subtitle">
                        <?php echo ($current_user_role == 1) ? 'Total Orders' : ' Total Orders'; ?>
                    </div>
                </div>

                <!-- Orders Table -->
                <div class="table-wrapper">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Updated Time</th>
                                <th>Customer Name</th>
                                <th>Amount</th>
                                <th>Tracking Number</th>
                                <?php if ($is_admin && $is_main_admin) { ?>
                                <th>Tenant Company</th>
                                <?php } else { ?>
                                        <?php } ?>
                                <th>Processed By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="ordersTableBody">
                            <?php if ($result && $result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <!-- Order ID -->
                                <td class="order-id">
                                    <?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>
                                    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/leads_badge.php'); ?>
                                </td>

                                <!-- Updated Time Column -->
                                <td class="updated-time">
                                    <?php
                                            if (isset($row['updated_at']) && !empty($row['updated_at'])) {
                                                $updatedAt = new DateTime($row['updated_at']);
                                                echo '<span class="updated-date">' . $updatedAt->format('Y-m-d') . '</span>';
                                                echo '<span class="updated-time-only">' . $updatedAt->format('H:i:s') . '</span>';
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
                                </td>

                                <!-- Total Amount with Currency + Pay Status -->
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

                                <!-- Tracking Number + Courier -->
                                <td class="tracking-number">
                                    <?php
                                            $trackingNumber = isset($row['tracking_number']) ? $row['tracking_number'] : '';
                                            $courierName = isset($row['courier_name']) ? $row['courier_name'] : '';
                                            
                                            if (!empty($trackingNumber)) {
                                                echo htmlspecialchars($trackingNumber);
                                                if (!empty($courierName)) {
                                                    echo '<br><span style="font-size: 11px; color: #6c757d;">' . htmlspecialchars($courierName) . '</span>';
                                                }
                                            } else {
                                                echo '<span style="color: #999; font-style: italic;">Not assigned</span>';
                                            }
                                            ?>
                                </td>

                                <!-- Tenant Company Name -->
                                <?php if ($is_admin && $is_main_admin) { ?>
                                <td class="customer-name">
                                    <div class="customer-info">
                                        <h6 style="margin: 0; font-size: 14px;">
                                            <?php echo htmlspecialchars($row['company_name']); ?></h6>
                                    </div>
                                </td>
                                <?php } else { ?>
                                        <?php } ?>

                                <!-- Processed By (who marked paid + payment method) -->
                                <td>
                                    <?php
                                    $paidByName = isset($row['paid_by_name']) ? htmlspecialchars($row['paid_by_name']) : '';
                                    $paymentMethod = isset($row['payment_method']) ? htmlspecialchars($row['payment_method']) : '';
                                    
                                    if ($payStatus == 'paid' && !empty($paidByName)) {
                                        echo '<span style="font-weight: 600; color: #28a745;">' . $paidByName . '</span>';
                                        if (!empty($paymentMethod)) {
                                            $methodDisplay = ucwords(str_replace('_', ' ', $paymentMethod));
                                            echo '<br><span style="font-size: 11px; color: #6c757d;">' . $methodDisplay . '</span>';
                                        }
                                    } else {
                                        echo '<span style="color: #adb5bd;">-</span>';
                                    }
                                    ?>
                                </td>

                                <!-- Action Buttons -->
                                <td class="actions">
                                    <?php
                                            $payStatus = isset($row['pay_status']) ? $row['pay_status'] : 'unpaid';
                                            $orderId = isset($row['order_id']) ? htmlspecialchars($row['order_id']) : '';
                                            ?>

                                    <!-- VIEW button - always show -->
                                    <button class="action-btn view-btn" title="View Order Details"
                                        onclick="openOrderModal('<?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>', '<?php echo isset($row['interface']) ? htmlspecialchars($row['interface']) : ''; ?>')">
                                        <i class="fas fa-eye"></i>
                                    </button>

                                    <!-- RECEIPT button - opens receipt in new tab -->
                                    <a href="order_receipt.php?id=<?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>" 
                                       target="_blank" class="action-btn" title="View Receipt" style="background-color: #17a2b8; color: white; text-decoration: none; display: inline-flex; align-items: center; justify-content: center;">
                                        <i class="fas fa-file-invoice"></i>
                                    </a>

                                    <!-- PRINT BUTTON -->
                                    <button class="action-btn print-btn" title="Print Order"
                                        onclick="printOrder('<?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>')">
                                        <i class="fas fa-print"></i>
                                    </button>

                                    <!-- CANCEL button - always show for dispatch status -->
                                    <button class="action-btn cancel-btn" title="Cancel Order"
                                        onclick="cancelOrder('<?php echo $orderId; ?>')">
                                        <i class="fas fa-times"></i>
                                    </button>

                                    <?php 
                                    $payStatus = isset($row['pay_status']) ? $row['pay_status'] : '';
                                    ?>

                                    <?php if ($payStatus === 'unpaid'): ?>
                                    <!-- MARK AS PAID button - only show for unpaid orders -->
                                    <button class="action-btn paid-btn" title="Mark as Paid"
                                        onclick="markAsPaid('<?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>')">
                                        <i class="fas fa-dollar-sign"></i>
                                    </button>
                                    <?php elseif ($payStatus === 'paid'): ?>
                                    <!-- UNMARK AS PAID button - only show for paid orders -->
                                    <button class="action-btn cancel-btn" title="Unmark as Paid"
                                        onclick="unmarkPaid('<?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>')">
                                        <i class="fas fa-undo"></i>
                                    </button>
                                    <?php endif; ?>

                                </td>
                            </tr>
                            <?php endwhile; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center"
                                    style="padding: 40px; text-align: center; color: #666;">
                                    No dispatched orders found
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
                            onclick="window.location.href='?page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>&order_id_filter=<?php echo urlencode($order_id_filter); ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&user_id_filter=<?php echo urlencode($user_id_filter); ?>&tracking_id=<?php echo urlencode($tracking_id); ?>&updated_date_from=<?php echo urlencode($updated_date_from); ?>&updated_date_to=<?php echo urlencode($updated_date_to); ?>&search=<?php echo urlencode($search); ?>'">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <?php endif; ?>

                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <button class="page-btn <?php echo ($i == $page) ? 'active' : ''; ?>"
                            onclick="window.location.href='?page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&order_id_filter=<?php echo urlencode($order_id_filter); ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&user_id_filter=<?php echo urlencode($user_id_filter); ?>&tracking_id=<?php echo urlencode($tracking_id); ?>&updated_date_from=<?php echo urlencode($updated_date_from); ?>&updated_date_to=<?php echo urlencode($updated_date_to); ?>&search=<?php echo urlencode($search); ?>'">
                            <?php echo $i; ?>
                        </button>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                        <button class="page-btn"
                            onclick="window.location.href='?page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>&order_id_filter=<?php echo urlencode($order_id_filter); ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&user_id_filter=<?php echo urlencode($user_id_filter); ?>&tracking_id=<?php echo urlencode($tracking_id); ?>&updated_date_from=<?php echo urlencode($updated_date_from); ?>&updated_date_to=<?php echo urlencode($updated_date_to); ?>&search=<?php echo urlencode($search); ?>'">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>




    <!-- Include MODAL for View Order -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/order_view_modal.php'); ?>

    <!-- Include Footer and Scripts (toast.js loads here) -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php'); ?>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php'); ?>

    <script>
    /**
     * JavaScript functionality for dispatched order management
     * Enhanced with Mark as Paid and Cancel Order functionality
     */

    let currentOrderId = null;
    let currentInterface = null;
    let currentPaymentSlip = null; // Store payment slip filename
    let currentPayStatus = null; // Store payment status

    function showAlert(type, message) {
        toastManager[type](message);
    }

    // NEW: Current user role from PHP
    const currentUserRole = <?php echo $current_user_role; ?>;
    const currentUserId = <?php echo $current_user_id; ?>;

    // Clear all filter inputs
    function clearFilters() {
        document.getElementById('order_id_filter').value = '';
        document.getElementById('customer_name_filter').value = '';
        document.getElementById('user_id_filter').value = '';
        document.getElementById('tracking_id').value = '';
        document.getElementById('updated_date_from').value = '';
        document.getElementById('updated_date_to').value = '';

        // Only clear user_id_filter for admin users (if it exists)
        const userIdFilter = document.getElementById('user_id_filter');
        if (userIdFilter && currentUserRole == 1) {
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
        const slipUrl = '/OMS/dist/uploads/payment_slips/' + encodeURIComponent(currentPaymentSlip);

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
            toastManager.warning('No order selected for download.');
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
        console.log('Dispatched Orders page loaded, initializing...'); // Debug log

        // Add hover effects to table rows
        const tableRows = document.querySelectorAll('.orders-table tbody tr');
        tableRows.forEach(row => {
            row.addEventListener('mouseenter', function() {
                this.style.transform = 'translateX(2px)';
            });

            row.addEventListener('mouseleave', function() {
                this.style.transform = 'translateX(0)';
            });
        });

        // Check if modal elements exist
        const modal = document.getElementById('orderModal');
        const modalContent = document.getElementById('modalContent');
        if (!modal || !modalContent) {
            console.error('Modal elements not found! Check HTML structure.');
        }



        /**
         * CANCEL ORDER FUNCTIONALITY INITIALIZATION
         * No longer needed - cancel order uses direct SweetAlert2 flow.
         */

        // Enhanced Escape key handling for all modals (cancel modal removed — uses SweetAlert2)
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                // Check which modal is open and close it
                const orderModal = document.getElementById('orderModal');
                const markPaidModal = document.getElementById('markPaidModal');

                if (orderModal && orderModal.style.display === 'flex') {
                    closeOrderModal();
                }
            }
        });
    });

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
    </script>

</body>

</html>