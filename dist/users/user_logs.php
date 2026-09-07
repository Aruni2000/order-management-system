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


// Handle search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$user_name_filter = isset($_GET['user_name_filter']) ? trim($_GET['user_name_filter']) : '';
$action_type_filter = isset($_GET['action_type_filter']) ? trim($_GET['action_type_filter']) : '';
$inquiry_id_filter = isset($_GET['inquiry_id_filter']) ? trim($_GET['inquiry_id_filter']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$tenant_filter = isset($_GET['tenant_filter']) ? trim($_GET['tenant_filter']) : '';

$is_main_admin = isset($_SESSION['is_main_admin']) && $_SESSION['is_main_admin'] == 1;

// Pagination settings
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Main query - joining user_logs with users table and tenants table
$sql = "SELECT ul.id as log_id, ul.user_id, ul.action_type, ul.inquiry_id, 
               ul.details, ul.created_at,
               u.name as username, u.email as user_email,
               t.company_name as tenant_name
        FROM user_logs ul 
        LEFT JOIN users u ON ul.user_id = u.id
        LEFT JOIN tenants t ON u.tenant_id = t.tenant_id";

$countSql = "SELECT COUNT(*) as total FROM user_logs ul 
             LEFT JOIN users u ON ul.user_id = u.id
             LEFT JOIN tenants t ON u.tenant_id = t.tenant_id";

// Build search conditions
$searchConditions = [];

// Filter by tenant_id if set
$session_tenant_id = $_SESSION['tenant_id'] ?? null;
if (!$is_main_admin && $session_tenant_id !== null) {
    $searchConditions[] = "u.tenant_id = " . (int)$session_tenant_id;
} elseif ($is_main_admin && !empty($tenant_filter)) {
    $searchConditions[] = "u.tenant_id = " . (int)$tenant_filter;
}


// General search condition
if (!empty($search)) {
    $searchTerm = $conn->real_escape_string($search);
    $searchConditions[] = "(
                        u.name LIKE '%$searchTerm%' OR 
                        ul.action_type LIKE '%$searchTerm%' OR 
                        ul.details LIKE '%$searchTerm%' OR
                        ul.inquiry_id LIKE '%$searchTerm%')";
}

// Specific User Name filter
if (!empty($user_name_filter)) {
    $userNameTerm = $conn->real_escape_string($user_name_filter);
    $searchConditions[] = "u.name LIKE '%$userNameTerm%'";
}

// Action Type filter
if (!empty($action_type_filter)) {
    $actionTypeTerm = $conn->real_escape_string($action_type_filter);
    $searchConditions[] = "ul.action_type = '$actionTypeTerm'";
}

// Inquiry ID filter
if (!empty($inquiry_id_filter)) {
    $inquiryIdTerm = $conn->real_escape_string($inquiry_id_filter);
    $searchConditions[] = "ul.inquiry_id = '$inquiryIdTerm'";
}

// Date range filter
if (!empty($date_from)) {
    $dateFromTerm = $conn->real_escape_string($date_from);
    $searchConditions[] = "DATE(ul.created_at) >= '$dateFromTerm'";
}

if (!empty($date_to)) {
    $dateToTerm = $conn->real_escape_string($date_to);
    $searchConditions[] = "DATE(ul.created_at) <= '$dateToTerm'";
}

// Apply all search conditions
if (!empty($searchConditions)) {
    $finalSearchCondition = " WHERE " . implode(' AND ', $searchConditions);
    $countSql .= $finalSearchCondition;
    $sql .= $finalSearchCondition;
}

// Add ordering and pagination
$sql .= " ORDER BY ul.created_at DESC LIMIT $limit OFFSET $offset";

// Execute queries
$countResult = $conn->query($countSql);
$totalRows = 0;
if ($countResult && $countResult->num_rows > 0) {
    $totalRows = $countResult->fetch_assoc()['total'];
}
$totalPages = ceil($totalRows / $limit);
$result = $conn->query($sql);

// Debug: Check if query failed
if (!$result) {
    die("Query failed: " . $conn->error);
}

// Get unique action types for filter dropdown - restricted by tenant
$action_types_sql = "SELECT DISTINCT ul.action_type FROM user_logs ul 
                    LEFT JOIN users u ON ul.user_id = u.id 
                    WHERE ul.action_type IS NOT NULL AND ul.action_type != ''";
if ($session_tenant_id !== null) {
    $action_types_sql .= " AND u.tenant_id = " . (int)$session_tenant_id;
}
$action_types_sql .= " ORDER BY ul.action_type";
$action_types_result = $conn->query($action_types_sql);

$action_types = [];
if ($action_types_result && $action_types_result->num_rows > 0) {
    $action_types = $action_types_result->fetch_all(MYSQLI_ASSOC);
}

    // List of users for filter
    $users_sql = "SELECT DISTINCT u.id, u.name FROM users u JOIN user_logs ul ON u.id = ul.user_id";
    if (!$is_main_admin && $session_tenant_id !== null) {
        $users_sql .= " WHERE u.tenant_id = " . (int)$session_tenant_id;
    }
    $users_sql .= " ORDER BY u.name ASC";
    $users_result = $conn->query($users_sql);
    $users_list = [];
    if ($users_result && $users_result->num_rows > 0) {
        $users_list = $users_result->fetch_all(MYSQLI_ASSOC);
    }

    // List of tenants for filter (if main admin)
    $tenants_list = [];
    if ($is_main_admin && $_SESSION['role_id'] == 1) {
        $tenants_sql = "SELECT tenant_id, company_name FROM tenants WHERE status = 'active' ORDER BY company_name ASC";
        $tenants_result = $conn->query($tenants_sql);
        if ($tenants_result && $tenants_result->num_rows > 0) {
            $tenants_list = $tenants_result->fetch_all(MYSQLI_ASSOC);
        }
    }

// Function to format action type for human-readable display
function formatActionType($actionType) {
    if (empty($actionType)) return '';
    
    // Replace underscores with spaces and capitalize each word
    $formatted = ucwords(str_replace('_', ' ', $actionType));
    
    return $formatted;
}

// Function to get entity prefix based on action type
function getInquiryPrefix($actionType) {
    $action = strtolower($actionType);
    
    // Order-related actions
    if (preg_match('/^(order_|payment_|bulk_|condition_|update_call_status|complete_mark|return_csv|create_order|updated order)/', $action)) {
        return 'ORD';
    }
    
    // Product actions
    if (strpos($action, 'product_') === 0 || $action === 'stock_update') {
        return 'PRD';
    }
    
    // Category actions
    if (strpos($action, 'category_') === 0) {
        return 'CAT';
    }
    
    // User actions
    if (strpos($action, 'user_') === 0) {
        return 'USR';
    }
    
    // Customer actions
    if (strpos($action, 'customer_') === 0) {
        return 'CUS';
    }
    
    // Courier actions
    if (strpos($action, 'courier_') === 0 || $action === 'api_update') {
        return 'COU';
    }
    
    // Branding actions
    if (strpos($action, 'branding_') === 0) {
        return 'BRD';
    }
    
    // Lead actions
    if (strpos($action, 'lead_') === 0) {
        return 'LED';
    }
    
    // Tenant actions
    if (strpos($action, 'tenant_') === 0) {
        return 'TNT';
    }
    
    return 'REF';
}

// Function to format details JSON
function formatLogDetails($details) {
    if (empty($details)) {
        return 'No details available';
    }

    // Try to decode JSON
    $decoded = json_decode($details, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $formatted = [];
        
        // Exclude unwanted fields
        $excludeFields = ['ip_address', 'user_agent'];

        foreach ($decoded as $key => $value) {
            if (in_array($key, $excludeFields)) {
                continue;
            }

            $formattedKey = ucwords(str_replace('_', ' ', $key));

            // Handle detailed changes with 'from' and 'to'
            if (is_array($value) && isset($value['from']) && isset($value['to'])) {
                $from = htmlspecialchars($value['from'] ?: 'N/A');
                $to = htmlspecialchars($value['to'] ?: 'N/A');
                $formatted[] = "<strong>{$formattedKey}:</strong> Changed from '{$from}' to '{$to}'";
            } else {
                // Fallback for other fields
                if (is_array($value) || is_object($value)) {
                    $formattedValue = htmlspecialchars(json_encode($value));
                } else {
                    $formattedValue = htmlspecialchars((string)$value);
                }
                $formatted[] = "<strong>{$formattedKey}:</strong> {$formattedValue}";
            }
        }

        if (empty($formatted)) {
            return 'No relevant details to display.';
        }

        return implode('<br>', $formatted);
    }

    // If not JSON, return as is
    return htmlspecialchars($details);
}
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>User Activity Logs | <?= htmlspecialchars($_SESSION['company_name'] ?? '') ?></title>
    
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="../assets/css/orders.css" />
    <link rel="stylesheet" href="../assets/css/customers.css" />
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
                        <h5 class="mb-0 font-medium">User Activity Logs <i class="fas fa-info-circle" style="cursor: pointer; font-size: 16px; margin-left: 8px; color: #3b82f6;" onclick="openInfoModal()" title="Click here to know more about this page"></i></h5>
                    </div>
                </div>
            </div>

            <div class="main-content-wrapper">
                
                <!-- User Logs Filter Section -->
                <div class="tracking-container">
                    <form class="tracking-form" method="GET" action="">
                        <div class="form-group">
                            <label for="user_name_filter">User Name</label>
                            <select id="user_name_filter" name="user_name_filter">
                                <option value="">All Users</option>
                                <?php foreach ($users_list as $user): ?>
                                    <option value="<?php echo htmlspecialchars($user['name']); ?>" 
                                            <?php echo $user_name_filter == $user['name'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($user['name'] . ' (' . $user['id'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if ($is_main_admin && $_SESSION['role_id'] == 1): ?>
                        <div class="form-group">
                            <label for="tenant_filter">Tenant Company</label>
                            <select id="tenant_filter" name="tenant_filter">
                                <option value="">All Companies</option>
                                <?php foreach ($tenants_list as $tenant): ?>
                                    <option value="<?php echo $tenant['tenant_id']; ?>" 
                                            <?php echo ($tenant_filter == $tenant['tenant_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($tenant['company_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <label for="action_type_filter">Action Type</label>
                            <select id="action_type_filter" name="action_type_filter">
                                <option value="">All Actions</option>
                                <?php foreach ($action_types as $action_type): ?>
                                    <option value="<?php echo htmlspecialchars($action_type['action_type']); ?>" 
                                            <?php echo $action_type_filter == $action_type['action_type'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(formatActionType($action_type['action_type'])); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="inquiry_id_filter">Ref ID</label>
                            <input type="number" id="inquiry_id_filter" name="inquiry_id_filter" 
                                   placeholder="Enter ID number" 
                                   value="<?php echo htmlspecialchars($inquiry_id_filter); ?>">
                        </div>
                        
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

                <!-- Logs Count Display -->
                <div class="order-count-container">
                    <div class="order-count-number"><?php echo number_format($totalRows); ?></div>
                    <div class="order-count-dash">-</div>
                    <div class="order-count-subtitle">Total Activity Logs</div>
                </div>

                <!-- User Logs Table -->
                <div class="table-wrapper">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th style="width: 100px; min-width: 100px;">Log ID</th>
                                <th style="width: 220px; min-width: 220px;">User Info</th>
                                <th style="width: 200px; min-width: 200px;">Action Type</th>
                                <th style="width: 140px; min-width: 140px;">Ref ID</th>
                                <th style="width: 450px; min-width: 450px; max-width: 450px;">Details</th>
                                <th style="width: 170px; min-width: 170px;">Date</th>
                            </tr>
                        </thead>
                        <tbody id="userLogsTableBody">
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <!-- Log ID -->
                                        <td class="order-id">
                                            <?php echo htmlspecialchars($row['log_id']); ?>
                                        </td>
                                        
                                        <!-- User Info -->
                                        <td class="user-info-column">
                                            <div class="user-info-section" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;">
                                                <h6 style="margin: 0 0 5px 0; font-size: 14px; font-weight: 600; color: #333;">
                                                    <?php echo htmlspecialchars($row['username'] ?: 'Unknown User'); ?> (<?php echo htmlspecialchars($row['user_id']); ?>)
                                                </h6>
                                                <?php if ($is_main_admin && !empty($row['tenant_name'])): ?>
                                                    <div style="color: #4b5563; font-size: 12px; font-weight: 500; margin-bottom: 2px;">
                                                        <i class="fas fa-building" style="font-size: 10px; color: #94a3b8; margin-right: 4px;"></i>
                                                        <?php echo htmlspecialchars($row['tenant_name']); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($row['user_email'])): ?>
                                                    <small style="color: #6c757d; font-size: 11px; display: block;">
                                                        <?php echo htmlspecialchars($row['user_email']); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                        </td>

                                        <!-- Action Type -->
                                        <td class="action-type-column">
                                            <?php 
                                                $action = strtolower($row['action_type']);
                                                if (strpos($action, 'create') !== false || strpos($action, 'add') !== false) {
                                                    $badgeClass = 'pay-status-paid';
                                                } elseif (strpos($action, 'delete') !== false || strpos($action, 'remove') !== false) {
                                                    $badgeClass = 'pay-status-unpaid';
                                                } elseif (strpos($action, 'update') !== false || strpos($action, 'edit') !== false) {
                                                    $badgeClass = 'status-badge-warning';
                                                } else {
                                                    $badgeClass = 'status-badge-info';
                                                }
                                            ?>
                                            <span class="status-badge <?php echo $badgeClass; ?>" title="<?php echo htmlspecialchars($row['action_type']); ?>">
                                                <?php echo htmlspecialchars(formatActionType($row['action_type'])); ?>
                                            </span>
                                        </td>
                                        
                                        <!-- Ref ID (with type prefix) -->
                                        <td>
                                            <?php if (!empty($row['inquiry_id'])): ?>
                                                <div style="font-weight: 500; color: #495057;">
                                                    <span class="ref-prefix" style="font-size: 10px; font-weight: 700; color: #6c757d; background: #e9ecef; padding: 1px 5px; border-radius: 3px; margin-right: 3px;"><?php echo getInquiryPrefix($row['action_type']); ?></span>
                                                    <?php echo htmlspecialchars($row['inquiry_id']); ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: #6c757d; font-style: italic;">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <!-- Details -->
                                        <td>
                                            <div class="details-container" style="width: 100%; word-wrap: break-word; overflow-wrap: break-word;">
                                                <?php 
                                                $formattedDetails = formatLogDetails($row['details']);
                                                if (strlen($formattedDetails) > 250) {
                                                    $shortText = strip_tags(str_replace(['<br>', '<br/>', '<br />'], ' | ', $formattedDetails));
                                                    echo '<div class="details-short">' . substr($shortText, 0, 250) . '...</div>';
                                                    echo '<div class="details-full" style="display: none;">' . $formattedDetails . '</div>';
                                                    echo '<a href="#" class="toggle-details" style="color: #007bff; font-size: 12px;">Show More</a>';
                                                } else {
                                                    echo '<div>' . $formattedDetails . '</div>';
                                                }
                                                ?>
                                            </div>
                                        </td>
                                        
                                        <!-- Date & Time -->
                                        <td>
                                            <div style="font-size: 12px; line-height: 1.4;">
                                                <div style="font-weight: 500;"><?php echo date('M d, Y', strtotime($row['created_at'])); ?></div>
                                                <div style="color: #6c757d;"><?php echo date('h:i:s A', strtotime($row['created_at'])); ?></div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                        <td colspan="6" class="text-center" style="padding: 40px; text-align: center; color: #666;">
                                        <i class="fas fa-history" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                                        No activity logs found
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
                            <button class="page-btn" onclick="window.location.href='?page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>&user_name_filter=<?php echo urlencode($user_name_filter); ?>&action_type_filter=<?php echo urlencode($action_type_filter); ?>&inquiry_id_filter=<?php echo urlencode($inquiry_id_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>&tenant_filter=<?php echo urlencode($tenant_filter); ?>'">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <button class="page-btn <?php echo ($i == $page) ? 'active' : ''; ?>" 
                                    onclick="window.location.href='?page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&user_name_filter=<?php echo urlencode($user_name_filter); ?>&action_type_filter=<?php echo urlencode($action_type_filter); ?>&inquiry_id_filter=<?php echo urlencode($inquiry_id_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>&tenant_filter=<?php echo urlencode($tenant_filter); ?>'">
                                <?php echo $i; ?>
                            </button>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <button class="page-btn" onclick="window.location.href='?page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>&user_name_filter=<?php echo urlencode($user_name_filter); ?>&action_type_filter=<?php echo urlencode($action_type_filter); ?>&inquiry_id_filter=<?php echo urlencode($inquiry_id_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>&tenant_filter=<?php echo urlencode($tenant_filter); ?>'">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Info Modal -->
    <?php
    include_once($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/info_modal.php');
    renderInfoModal(
        'How User Activity Logs Work',
        'fas fa-history',
        '<h6 style="color: #374151; border-bottom: 2px solid #e5e7eb; padding-bottom: 6px;">📋 What You See</h6>
        <ul style="color: #4b5563;">
            <li><strong>User Info:</strong> Who performed the action</li>
            <li><strong>Action Type:</strong> Badge shows the action (create, update, delete, etc.)</li>
            <li><strong>Ref ID:</strong> Linked record with a type prefix (e.g. ORD, PRD, CUS)</li>
            <li><strong>Details:</strong> Full change summary; expand with Show More</li>
        </ul>

        <h6 style="color: #374151; border-bottom: 2px solid #e5e7eb; padding-bottom: 6px; margin-top: 16px;">🔍 How to Use Filters</h6>
        <ul style="color: #4b5563;">
            <li><strong>User Name:</strong> Show one user activity</li>
            <li><strong>Action Type:</strong> Filter by a specific action</li>
            <li><strong>Ref ID & Date:</strong> Find a specific record or time range</li>
            <li><strong>Search</strong> applies filters; <strong>Clear</strong> resets them</li>
        </ul>

        <div style="background: #fef3c7; padding: 10px; border-radius: 6px; margin-top: 16px; font-size: 13px;">
            <strong>💡 Tip:</strong> Badges are color-coded — green for creates, yellow for updates, red for deletes, blue for other actions.
        </div>',
        '500px'
    );
    ?>

    <!-- Log Details Modal -->
    <div id="logDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h4>Activity Log Details</h4>
                <span class="close" onclick="closeLogModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="customer-detail-row">
                    <span class="detail-label">Log ID:</span>
                    <span class="detail-value" id="modal-log-id"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">User:</span>
                    <span class="detail-value" id="modal-username"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">User ID:</span>
                    <span class="detail-value" id="modal-user-id"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">User Email:</span>
                    <span class="detail-value" id="modal-user-email"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Action Type:</span>
                    <span class="detail-value">
                        <span id="modal-action-type" class="status-badge"></span>
                    </span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Inquiry ID:</span>
                    <span class="detail-value" id="modal-inquiry-id"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Details:</span>
                    <span class="detail-value" id="modal-details"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Date & Time:</span>
                    <span class="detail-value" id="modal-created-at"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php'); ?>

    <!-- Scripts -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php'); ?>

    <script>
// Complete JavaScript code for user logs page

// Log Details Modal Functions
function openLogModal(button) {
    const modal = document.getElementById('logDetailsModal');
    
    // Extract data from button attributes
    const logId = button.getAttribute('data-log-id');
    const userId = button.getAttribute('data-user-id');
    const username = button.getAttribute('data-username');
    const userEmail = button.getAttribute('data-user-email');
    const actionType = button.getAttribute('data-action-type');
    const inquiryId = button.getAttribute('data-inquiry-id');
    const details = button.getAttribute('data-details');
    const createdAt = button.getAttribute('data-created-at');

    // Populate modal fields
    document.getElementById('modal-log-id').textContent = '#' + logId;
    document.getElementById('modal-username').textContent = username || 'Unknown User';
    document.getElementById('modal-user-id').textContent = userId;
    document.getElementById('modal-user-email').textContent = userEmail || 'N/A';
    document.getElementById('modal-inquiry-id').textContent = inquiryId ? '#' + inquiryId : 'N/A';
    
    // Format details for modal
    document.getElementById('modal-details').innerHTML = formatDetailsForModal(details);
    document.getElementById('modal-created-at').textContent = formatDateTime(createdAt);
    
    // Set action type badge
    const actionTypeElement = document.getElementById('modal-action-type');
    actionTypeElement.textContent = formatActionTypeLabel(actionType);
    
    // Set appropriate badge class based on action type
    const action = actionType.toLowerCase();
    if (action.includes('create') || action.includes('add')) {
        actionTypeElement.className = 'status-badge pay-status-paid';
    } else if (action.includes('delete') || action.includes('remove')) {
        actionTypeElement.className = 'status-badge pay-status-unpaid';
    } else if (action.includes('update') || action.includes('edit')) {
        actionTypeElement.className = 'status-badge status-badge-warning';
    } else {
        actionTypeElement.className = 'status-badge status-badge-info';
    }

    // Show modal
    modal.style.display = 'block';
}

function formatActionTypeLabel(actionType) {
    if (!actionType) return '';
    return actionType
        .split('_')
        .map(word => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}

function formatDetailsForModal(details) {
    if (!details) return 'No details available';
    
    const escapeHTML = (str) => {
        const p = document.createElement('p');
        p.textContent = str;
        return p.innerHTML;
    };

    try {
        const decoded = JSON.parse(details);
        if (typeof decoded === 'object' && decoded !== null) {
            const excludeFields = ['ip_address', 'user_agent'];
            const formatted = [];
            
            for (const [key, value] of Object.entries(decoded)) {
                if (excludeFields.includes(key)) {
                    continue;
                }

                const formattedKey = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                
                if (typeof value === 'object' && value !== null && 'from' in value && 'to' in value) {
                    const from = escapeHTML(value.from || 'N/A');
                    const to = escapeHTML(value.to || 'N/A');
                    formatted.push(`<strong>${formattedKey}:</strong> Changed from '${from}' to '${to}'`);
                } else {
                    const formattedValue = typeof value === 'object' ? escapeHTML(JSON.stringify(value)) : escapeHTML(value);
                    formatted.push(`<strong>${formattedKey}:</strong> ${formattedValue}`);
                }
            }
            
            if (formatted.length === 0) {
                return 'No relevant details to display.';
            }
            return formatted.join('<br>');
        }
    } catch (e) {
        // Not JSON, return as is but escaped
        return escapeHTML(details);
    }
    
    return escapeHTML(details);
}

function closeLogModal() {
    document.getElementById('logDetailsModal').style.display = 'none';
}

function formatDateTime(dateString) {
    if (!dateString) return 'N/A';
    try {
        const date = new Date(dateString);
        if (isNaN(date.getTime())) return dateString;
        const yyyy = date.getFullYear();
        const mm = String(date.getMonth() + 1).padStart(2, '0');
        const dd = String(date.getDate()).padStart(2, '0');
        let hours = date.getHours();
        const minutes = String(date.getMinutes()).padStart(2, '0');
        const seconds = String(date.getSeconds()).padStart(2, '0');
        const ampm = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12;
        hours = hours ? hours : 12;
        const hh = String(hours).padStart(2, '0');
        return `${yyyy}-${mm}-${dd} ${hh}:${minutes}:${seconds} ${ampm}`;
    } catch (e) {
        return dateString;
    }
}

// View Related Inquiry Function
function viewInquiry(inquiryId) {
    // Redirect to inquiries page with specific inquiry ID
    window.location.href = `inquiries.php?inquiry_id=${inquiryId}`;
}

// Toggle Details Function
function toggleDetails(element) {
    const container = element.closest('.details-container');
    const shortText = container.querySelector('.details-short');
    const fullText = container.querySelector('.details-full');
    
    if (fullText.style.display === 'none') {
        shortText.style.display = 'none';
        fullText.style.display = 'block';
        element.textContent = 'Show Less';
    } else {
        shortText.style.display = 'block';
        fullText.style.display = 'none';
        element.textContent = 'Show More';
    }
}

// Event Listeners
document.addEventListener('DOMContentLoaded', function() {
    // View log button event listeners
    const viewButtons = document.querySelectorAll('.view-log-btn');
    viewButtons.forEach(button => {
        button.addEventListener('click', function() {
            openLogModal(this);
        });
    });
    
    // Toggle details event listeners
    const toggleDetailsButtons = document.querySelectorAll('.toggle-details');
    toggleDetailsButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            toggleDetails(this);
        });
    });
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        const logModal = document.getElementById('logDetailsModal');
        
        if (event.target === logModal) {
            closeLogModal();
        }
    };
    
    // Escape key to close modal
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeLogModal();
        }
    });
});

// Search functionality
function performSearch() {
    const searchForm = document.querySelector('.tracking-form');
    if (searchForm) {
        searchForm.submit();
    }
}

// Auto-submit search on Enter key for text inputs
document.addEventListener('DOMContentLoaded', function() {
    const searchInputs = document.querySelectorAll('#inquiry_id_filter, #date_from, #date_to');
    searchInputs.forEach(input => {
        input.addEventListener('keydown', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                performSearch();
            }
        });
    });
});

// Clear all filters
function clearFilters() {
    window.location.href = 'user_logs.php';
}
</script>



</body>
</html>