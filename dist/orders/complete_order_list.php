<?php
/**
 * Complete Orders Management System
 * This page displays orders with status 'complete' for individual interface
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

/**
 * SEARCH AND PAGINATION PARAMETERS
 */
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$order_id_filter = isset($_GET['order_id_filter']) ? trim($_GET['order_id_filter']) : '';
$customer_name_filter = isset($_GET['customer_name_filter']) ? trim($_GET['customer_name_filter']) : '';
$tracking_id = isset($_GET['tracking_id']) ? trim($_GET['tracking_id']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$tenant_id_filter = isset($_GET['tenant_id_filter']) ? trim($_GET['tenant_id_filter']) : '';
$user_id_filter = isset($_GET['user_id_filter']) ? trim($_GET['user_id_filter']) : '';

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

/**
 * DATABASE QUERIES
 * Main query to fetch orders with customer and payment information
 * Filtered for individual interface and complete status only
 */

// Base SQL for counting total records
$countSql = "SELECT COUNT(*) as total FROM order_header i 
             LEFT JOIN customers c ON i.customer_id = c.customer_id
             LEFT JOIN users u2 ON i.created_by = u2.id
             WHERE i.interface IN ('individual', 'leads') AND i.status = 'done'";

// Main query with all required joins
$sql = "SELECT i.*, c.name as customer_name, 
               p.payment_id, p.amount_paid, p.payment_method, p.payment_date, p.pay_by,
               u1.name as paid_by_name,
               u2.name as creator_name,
               t.company_name
        FROM order_header i 
        LEFT JOIN customers c ON i.customer_id = c.customer_id
        LEFT JOIN payments p ON i.order_id = p.order_id
        LEFT JOIN users u1 ON p.pay_by = u1.id
        LEFT JOIN users u2 ON i.created_by = u2.id
        LEFT JOIN tenants t ON i.tenant_id = t.tenant_id
        WHERE i.interface IN ('individual', 'leads') AND i.status = 'done'";

// Add tenant filter for non-main admin users
if ($is_main_admin != 1) {
    $countSql .= " AND i.tenant_id = $tenant_id";
    $sql .= " AND i.tenant_id = $tenant_id";
}

// Build search conditions
$searchConditions = [];

// General search condition (existing functionality)
if (!empty($search)) {
    $searchTerm = $conn->real_escape_string($search);
    $searchConditions[] = "(
                        i.order_id LIKE '%$searchTerm%' OR 
                        i.full_name LIKE '%$searchTerm%' OR 
                        i.issue_date LIKE '%$searchTerm%' OR 
                        i.due_date LIKE '%$searchTerm%' OR 
                        i.total_amount LIKE '%$searchTerm%' OR
                        i.pay_status LIKE '%$searchTerm%' OR
                        u2.name LIKE '%$searchTerm%')";
}

// Specific Order ID filter
if (!empty($order_id_filter)) {
    $orderIdTerm = $conn->real_escape_string($order_id_filter);
    $searchConditions[] = "i.order_id LIKE '%$orderIdTerm%'";
}

// Specific Customer Name filter
if (!empty($customer_name_filter)) {
    $customerNameTerm = $conn->real_escape_string($customer_name_filter);
    $searchConditions[] = "i.full_name LIKE '%$customerNameTerm%'";
}

// Tracking ID filter
if (!empty($tracking_id)) {
    $trackingTerm = $conn->real_escape_string($tracking_id);
    $searchConditions[] = "i.tracking_number LIKE '%$trackingTerm%'";
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

// Include navigation components


// Fetch all users for the User ID dropdown based on permissions
if ($is_main_admin == 1 && $current_user_role == 1) {
    $usersQuery = "SELECT id, name FROM users ORDER BY name ASC";
} else {
    $usersQuery = "SELECT id, name FROM users WHERE tenant_id = " . (int)$tenant_id . " ORDER BY name ASC";
}
$usersResult = $conn->query($usersQuery);


// Get unique tenants for filter dropdown
$tenant_sql = "SELECT DISTINCT tenant_id, company_name FROM tenants WHERE status = 'active'";
$tenant_result = $conn->query($tenant_sql);
$tenants = $tenant_result->fetch_all(MYSQLI_ASSOC);

?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Complete Orders | <?= htmlspecialchars($_SESSION['company_name'] ?? '') ?></title>
    
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>
    
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
                        <h5 class="mb-0 font-medium">Complete Orders</h5>
                    </div>
                </div>
            </div>

            <div class="main-content-wrapper">
                
                <!-- Order Tracking and Filter Section -->
                <div class="tracking-container">
                    <form class="tracking-form" method="GET" action="">
                        <div class="form-group">
                            <label for="order_id_filter">Order ID</label>
                            <input type="text" id="order_id_filter" name="order_id_filter" 
                                   placeholder="Enter order ID" 
                                   value="<?php echo htmlspecialchars($order_id_filter); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="customer_name_filter">Customer Name</label>
                            <input type="text" id="customer_name_filter" name="customer_name_filter" 
                                   placeholder="Enter customer name" 
                                   value="<?php echo htmlspecialchars($customer_name_filter); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="tracking_id">Tracking ID</label>
                            <input type="text" id="tracking_id" name="tracking_id" 
                                   placeholder="Enter tracking ID" 
                                   value="<?php echo htmlspecialchars($tracking_id); ?>">
                        </div>
                        
<?php if ($is_main_admin == 1 && $_SESSION['role_id'] == 1): ?>
                        <div class="form-group">
                            <label for="tenant_id_filter">Tenant</label>
                            <select id="tenant_id_filter" name="tenant_id_filter">
                                <option value="">All Tenants</option>
                                <?php foreach ($tenants as $tenant): ?>
                                    <option value="<?php echo $tenant['tenant_id']; ?>" <?php echo ($tenant_id_filter == $tenant['tenant_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($tenant['company_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

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
                                <button type="button" class="search-btn" onclick="clearFilters()" style="background: #6c757d;">
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
                    <div class="order-count-subtitle">Total Complete Orders</div>
                </div>

                <!-- Orders Table -->
                <div class="table-wrapper">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Issue Date</th>
                                <th>Updated Time</th>
                                <th>Customer Name</th>
                                <th>Total Amount</th>
                                <?php if ($is_main_admin == 1 && $_SESSION['role_id'] == 1): ?>
                                <th>Tenant Company</th>
                                <?php endif; ?>
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
                                            <?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>                                                <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/leads_badge.php'); ?>
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

                                        <!-- Update Time -->
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
                                            ?>
                                        </td>
                                        
                                        <!-- Total Amount with Pay Status -->
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

                                        <?php if ($is_main_admin == 1 && $_SESSION['role_id'] == 1): ?>
                                        <!-- Company Name -->
                                        <td>
                                            <?php
                                            echo isset($row['company_name']) ? htmlspecialchars($row['company_name']) : 'N/A';
                                            ?>
                                        </td>
                                        <?php endif; ?>
                                        
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
                                        
                                        <!-- Action Buttons -->
<td class="actions">
    <div class="action-buttons-group">
        <button class="action-btn view-btn" title="View Order Details" 
                onclick="openOrderModal('<?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>', '<?php echo isset($row['interface']) ? htmlspecialchars($row['interface']) : ''; ?>')">
            <i class="fas fa-eye"></i>
        </button>
    
    </div>
</td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="<?php echo ($is_main_admin == 1) ? '8' : '7'; ?>" class="text-center" style="padding: 40px; text-align: center; color: #666;">
                                        No complete orders found
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
                        <?php if ($page > 1): ?>
                            <button class="page-btn" onclick="window.location.href='?page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>&order_id_filter=<?php echo urlencode($order_id_filter); ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&tracking_id=<?php echo urlencode($tracking_id); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&tenant_id_filter=<?php echo urlencode($tenant_id_filter); ?>&user_id_filter=<?php echo urlencode($user_id_filter); ?>&search=<?php echo urlencode($search); ?>'">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <button class="page-btn <?php echo ($i == $page) ? 'active' : ''; ?>" 
                                    onclick="window.location.href='?page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&order_id_filter=<?php echo urlencode($order_id_filter); ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&tracking_id=<?php echo urlencode($tracking_id); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&tenant_id_filter=<?php echo urlencode($tenant_id_filter); ?>&user_id_filter=<?php echo urlencode($user_id_filter); ?>&search=<?php echo urlencode($search); ?>'">
                                <?php echo $i; ?>
                            </button>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <button class="page-btn" onclick="window.location.href='?page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>&order_id_filter=<?php echo urlencode($order_id_filter); ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&tracking_id=<?php echo urlencode($tracking_id); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&tenant_id_filter=<?php echo urlencode($tenant_id_filter); ?>&user_id_filter=<?php echo urlencode($user_id_filter); ?>&search=<?php echo urlencode($search); ?>'">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Order View Modal -->
    <div id="orderModal" class="modal-overlay">
        <div class="modal-container">
            <div class="modal-header">
                <h3 class="modal-title">Order Details</h3>
                <button class="modal-close" onclick="closeOrderModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="modalContent">
                <div class="modal-loading">
                    <i class="fas fa-spinner fa-spin"></i>
                    Loading order details...
                </div>
            </div>
            <div class="modal-footer">
                <!-- Payment Slip View Button / No Slip Message -->
                <button class="modal-btn modal-btn-info" onclick="viewPaymentSlip()" id="viewPaymentSlipBtn" style="display:none;">
                    <i class="fas fa-file-image"></i>
                    View Payment Slip
                </button>
                <span id="noPaymentSlipMsg" class="modal-btn modal-btn-secondary" style="display:none;cursor:default;opacity:0.7;">
                    <i class="fas fa-times-circle"></i>
                    No Payment Slip
                </span>
                <button class="modal-btn modal-btn-secondary" onclick="closeOrderModal()">Close</button>
                <button class="modal-btn modal-btn-primary" onclick="downloadOrder()" id="downloadBtn" style="display:none;">
                    <i class="fas fa-download"></i>
                    Download
                </button>
            </div>
        </div>
    </div>
        </form>
    </div>
</div>


    <script>
        /**
         * JavaScript functionality for complete order management
         */
        
        let currentOrderId = null;
        let currentInterface = null;
        let currentPaymentSlip = null;
        let currentPayStatus = null;

        // Clear all filter inputs
        function clearFilters() {
            document.getElementById('order_id_filter').value = '';
            document.getElementById('customer_name_filter').value = '';
            document.getElementById('tracking_id').value = '';
            document.getElementById('date_from').value = '';
            document.getElementById('date_to').value = '';
            var tenantFilter = document.getElementById('tenant_id_filter');
            if (tenantFilter) {
                tenantFilter.value = '';
            }
            var userIdFilter = document.getElementById('user_id_filter');
            if (userIdFilter) {
                userIdFilter.value = '';
            }
            
            window.location.href = window.location.pathname;
        }

        // Open order modal and load details
        function openOrderModal(orderId, interfaceType = null) {
            // Enhanced validation
            if (!orderId || orderId.trim() === '') {
                toastManager.warning('Order ID is required to view order details.');
                return;
            }
            
            console.log('Opening modal for Order ID:', orderId, 'Interface:', interfaceType);
            
            currentOrderId = orderId.trim();
            currentInterface = interfaceType;
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
                    Loading ${interfaceType === 'leads' ? 'lead' : 'order'} details for Order ID: ${currentOrderId}...
                </div>
            `;
            downloadBtn.style.display = 'none';
            if (viewPaymentSlipBtn) viewPaymentSlipBtn.style.display = 'none';
            
            // Fetch order details - use correct file based on interface
            const phpFile = 'download_order.php';
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

                // Check payment slip availability
                checkPaymentSlipAvailability();
            })
            .catch(error => {
                console.error('Error loading order details:', error);
                modalContent.innerHTML = `
                    <div class="modal-error" style="text-align: center; padding: 20px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle" style="font-size: 2em; margin-bottom: 10px;"></i>
                        <h4>Error Loading Order Details</h4>
                        <p>Order ID: ${currentOrderId}</p>
                        <p>Error: ${error.message}</p>
                        <p>Please check if the file exists and is accessible.</p>
                        <button onclick="retryLoadOrder()" class="btn btn-primary" style="margin-top: 10px;">
                            <i class="fas fa-redo"></i> Retry
                        </button>
                    </div>
                `;
            });
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

        // Function to check payment slip availability
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
                    const noSlipMsg = document.getElementById('noPaymentSlipMsg');

                    if (currentPayStatus === 'paid') {
                        if (currentPaymentSlip && currentPaymentSlip.trim() !== '') {
                            viewPaymentSlipBtn.style.display = 'inline-flex';
                            if (noSlipMsg) noSlipMsg.style.display = 'none';
                        } else {
                            viewPaymentSlipBtn.style.display = 'none';
                            if (noSlipMsg) noSlipMsg.style.display = 'inline-flex';
                        }
                    } else {
                        viewPaymentSlipBtn.style.display = 'none';
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

        // Function to view payment slip with no-slip message
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
            const slipUrl = '/OMS/dist/uploads/' + encodeURIComponent(currentPaymentSlip);

            // Open payment slip in new tab
            window.open(slipUrl, '_blank');
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
            console.log('Complete Orders page loaded, initializing...');
            
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
        });
/**
 * JAVASCRIPT FUNCTIONS - Add these functions to your existing script section
 * Handles the Answer/No Answer modal functionality
 */

// Global variable to store current modal state
let currentAnswerOrderId = null;
let currentCallLog = null;

/**
 * Open Answer Status Modal
 * @param {string} orderId - The order ID to update
 * @param {number} callLogStatus - Current call_log status (0 or 1)
 */
function openAnswerModal(orderId, callLogStatus) {
    if (!orderId || orderId.trim() === '') {
        toastManager.warning('Order ID is required to update call status.');
        return;
    }
    
    console.log('Opening answer modal for Order ID:', orderId, 'Current call_log:', callLogStatus);
    
    // Store current values
    currentAnswerOrderId = orderId.trim();
    currentCallLog = parseInt(callLogStatus);
    
    // Set form values
    document.getElementById('answer_order_id').value = currentAnswerOrderId;
    document.getElementById('current_call_log').value = currentCallLog;
    document.getElementById('displayOrderId').textContent = currentAnswerOrderId;
    
    // Determine new status (toggle: 0->1, 1->0)
    const newCallLog = currentCallLog === 0 ? 1 : 0;
    document.getElementById('new_call_log').value = newCallLog;
    
    // Update modal content based on action
    updateModalContent(currentCallLog, newCallLog);
    
    // Reset form
    document.getElementById('answer-status-form').reset();
    // Re-set the hidden fields after reset
    document.getElementById('answer_order_id').value = currentAnswerOrderId;
    document.getElementById('current_call_log').value = currentCallLog;
    document.getElementById('new_call_log').value = newCallLog;
    document.getElementById('displayOrderId').textContent = currentAnswerOrderId;
    
    // Show the modal
    const modal = document.getElementById('answerStatusModal');
    modal.style.display = 'flex';
    document.body.style.overflow = 'clip';
    
    // Focus on textarea
    setTimeout(() => {
        document.getElementById('answer_reason').focus();
    }, 100);
}

/**
 * Update Modal Content Based on Action
 * @param {number} currentStatus - Current call_log value
 * @param {number} newStatus - New call_log value to set
 */
function updateModalContent(currentStatus, newStatus) {
    const modalTitle = document.getElementById('answerModalTitle');
    const alertMessage = document.getElementById('answerAlertMessage');
    const alertText = document.getElementById('alertText');
    const reasonLabel = document.getElementById('reasonLabel');
    const reasonHelp = document.getElementById('reasonHelp');
    const submitButtonText = document.getElementById('submitButtonText');
    const submitBtn = document.getElementById('answer-submit-btn');
    
    if (newStatus === 1) {
        // Marking as ANSWERED (call_log = 1)
        modalTitle.innerHTML = '<i class="fas fa-check-circle me-2"></i>Mark as Answered';
        alertMessage.className = 'alert alert-success mb-3';
        alertText.textContent = 'Mark this order as answered and provide call notes';
        reasonLabel.innerHTML = 'Answer Notes <span class="text-danger">*</span>';
        reasonHelp.textContent = 'Please provide details about the customer conversation';
        submitButtonText.textContent = 'Mark as Answered';
        submitBtn.style.background = '#28a745 !important';
        document.getElementById('answer_reason').placeholder = 'Enter details about customer conversation...';
    } else {
        // Marking as NO ANSWER (call_log = 0)
        modalTitle.innerHTML = '<i class="fas fa-times-circle me-2"></i>Mark as No Answer';
        alertMessage.className = 'alert alert-warning mb-3';
        alertText.textContent = 'Mark this order as no answer and provide reason';
        reasonLabel.innerHTML = 'No Answer Reason <span class="text-danger">*</span>';
        reasonHelp.textContent = 'Please specify why the customer did not answer';
        submitButtonText.textContent = 'Mark as No Answer';
        submitBtn.style.background = '#dc3545 !important';
        document.getElementById('answer_reason').placeholder = 'Enter reason for no answer (busy, unreachable, etc.)...';
    }
}

/**
 * Close Answer Status Modal
 */
function closeAnswerModal() {
    const modal = document.getElementById('answerStatusModal');
    modal.style.display = 'none';
    document.body.style.overflow = '';
    
    // Reset form and variables
    document.getElementById('answer-status-form').reset();
    currentAnswerOrderId = null;
    currentCallLog = null;
}

/**
 * Initialize Answer Status Functionality
 */
document.addEventListener('DOMContentLoaded', function() {
    const answerForm = document.getElementById('answer-status-form');
    const modal = document.getElementById('answerStatusModal');
    
    // Handle answer form submission
    if (answerForm) {
        answerForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const orderId = document.getElementById('answer_order_id').value;
            const newCallLog = document.getElementById('new_call_log').value;
            const answerReason = document.getElementById('answer_reason').value.trim();
            const submitBtn = document.getElementById('answer-submit-btn');
            
            // Validation
            if (!orderId || !answerReason) {
                toastManager.warning('Please fill in all required fields');
                return;
            }
            
            if (answerReason.length < 5) {
                toastManager.warning('Please provide more detailed notes (minimum 5 characters)');
                return;
            }
            
            // Confirm action
            const actionText = (newCallLog == 1) ? 'mark as answered' : 'mark as no answer';
            if (!confirm(`Are you sure you want to ${actionText} for Order ID: ${orderId}?`)) {
                return;
            }
            
            // Show loading state
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Updating...';
            submitBtn.disabled = true;
            
            // Create FormData object
            const formData = new FormData();
            formData.append('order_id', orderId);
            formData.append('call_log', newCallLog);
            formData.append('answer_reason', answerReason);
            formData.append('action', 'update_call_status');
            
            // Send the request
            fetch('update_call_status.php', {
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
                    const statusText = (newCallLog == 1) ? 'answered' : 'no answer';
                    toastManager.success(`Order marked as ${statusText} successfully!`);
                    closeAnswerModal();
                    // Reload the page to reflect changes
                    window.location.reload();
                } else {
                    toastManager.error(data.message || 'Failed to update call status');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                toastManager.error('An error occurred while updating call status. Please try again.');
            })
            .finally(() => {
                // Reset button state
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        });
    }
    
    // Close modal when clicking outside
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeAnswerModal();
            }
        });
    }
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('answerStatusModal');
            if (modal && modal.style.display === 'flex') {
                closeAnswerModal();
            }
        }
    });
});

/**
 * CANCEL ORDER MODAL FUNCTIONALITY
 * Add these functions to your existing JavaScript code
 */

    }

    </script>

    <!-- Include Footer and Scripts -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php'); ?>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php'); ?>

</body>
</html>