<?php
// Start session at the very beginning
session_start();

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /order_management/dist/pages/login.php");
    exit();
}

// Include the database connection file
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/connection/db_connection.php');

// Check if user has admin role (role_id = 1)
if (!isset($_SESSION['user_id'])) {
    header("Location: /order_management/dist/pages/login.php");
    exit();
}

// Get user's role from database
$user_id = $_SESSION['user_id'];
$role_check_sql = "SELECT u.role_id, r.name as role_name 
                   FROM users u 
                   LEFT JOIN roles r ON u.role_id = r.id 
                   WHERE u.id = ? AND u.status = 'active'";
$role_stmt = $conn->prepare($role_check_sql);
$role_stmt->bind_param("i", $user_id);
$role_stmt->execute();
$role_result = $role_stmt->get_result();

if ($role_result->num_rows === 0) {
    session_destroy();
    header("Location: /order_management/dist/pages/login.php");
    exit();
}

$user_role = $role_result->fetch_assoc();

// Access Rule:
// - is_main_admin = 1 AND role_id = 1: See all users success rates
// - role_id = 1 AND is_main_admin = 0: See all users success rates in their tenant
// - Others: cannot see users success rates
$is_main_admin = isset($_SESSION['is_main_admin']) && $_SESSION['is_main_admin'] == 1;
$session_tenant_id = $_SESSION['tenant_id'] ?? null;

if ($user_role['role_id'] != 1) {
    header("Location: /order_management/dist/dashboard/index.php");
    exit();
}

// ============================================
// HANDLE SUCCESS REPORT EXPORT (CSV/EXCEL)
// IMPORTANT: This MUST be before any HTML output or includes
// ============================================
if (isset($_GET['export']) && $_GET['export'] == 'success_report') {
    // Get all filters for export
    $export_search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $export_role = isset($_GET['role_filter']) ? trim($_GET['role_filter']) : '';
    $export_status = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : '';
    $export_date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
    $export_date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
    $export_tenant = isset($_GET['tenant_filter']) ? trim($_GET['tenant_filter']) : '';
    
    // Build export query with same filters as main query
    $export_sql = "SELECT u.id as user_id, u.name as username, u.email, u.mobile as phone, 
                   u.nic, r.name as role, u.status, u.created_at, t.company_name as tenant_name,
                   (SELECT COUNT(*) FROM order_header WHERE user_id = u.id AND status NOT IN ('pending', 'cancel', 'dispatch','waiting')) as dispatched_orders,
                   (SELECT COUNT(*) FROM order_header WHERE user_id = u.id AND status IN ('done', 'delivered')) as delivered_orders,
                   (SELECT COUNT(*) FROM order_header WHERE user_id = u.id AND status = 'cancel') as cancelled_orders,
                   (SELECT COUNT(*) FROM order_header WHERE user_id = u.id AND status = 'pending') as pending_orders,
                   (SELECT COUNT(*) FROM order_header WHERE user_id = u.id AND status = 'waiting') as waiting_orders
            FROM users u 
            LEFT JOIN roles r ON u.role_id = r.id
            LEFT JOIN tenants t ON u.tenant_id = t.tenant_id";
    
    $export_conditions = [];
    
    if (!empty($export_search)) {
        $searchTerm = $conn->real_escape_string($export_search);
        $export_conditions[] = "(u.name LIKE '%$searchTerm%' OR u.email LIKE '%$searchTerm%' OR 
                                u.mobile LIKE '%$searchTerm%' OR u.nic LIKE '%$searchTerm%')";
    }
    
    if (!empty($export_role)) {
        $roleTerm = $conn->real_escape_string($export_role);
        $export_conditions[] = "r.name = '$roleTerm'";
    }
    
    if (!empty($export_status)) {
        $statusTerm = $conn->real_escape_string($export_status);
        $export_conditions[] = "u.status = '$statusTerm'";
    }
    
    if (!empty($export_date_from)) {
        $dateFromTerm = $conn->real_escape_string($export_date_from);
        $export_conditions[] = "DATE(u.created_at) >= '$dateFromTerm'";
    }
    
    if (!empty($export_date_to)) {
        $dateToTerm = $conn->real_escape_string($export_date_to);
        $export_conditions[] = "DATE(u.created_at) <= '$dateToTerm'";
    }

    // Access Scoping for Export
    if (!$is_main_admin) {
        $export_conditions[] = "u.tenant_id = " . (int)$session_tenant_id;
    } elseif (!empty($export_tenant)) {
        $export_conditions[] = "u.tenant_id = " . (int)$export_tenant;
    }
    
    if (!empty($export_conditions)) {
        $export_sql .= " WHERE " . implode(' AND ', $export_conditions);
    }
    
    $export_sql .= " ORDER BY u.created_at DESC";
    $export_result = $conn->query($export_sql);
    
    // Clear any output buffers
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=user_success_report_' . date('Y-m-d_His') . '.csv');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    
    // Add BOM for proper UTF-8 encoding in Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // CSV Headers
    fputcsv($output, [
        'User ID',
        'Username',
        'Email',
        'Phone',
        'NIC',
        'Role',
        'Tenant',
        'Status',
        'Dispatched Orders',
        'Delivered Orders',
        'Cancelled Orders',
        'Pending Orders',
        'Waiting Orders',
        'Success Rate (%)',
        'Performance Rating',
        'Created Date'
    ]);
    
    // CSV Data
    if ($export_result && $export_result->num_rows > 0) {
        while ($export_row = $export_result->fetch_assoc()) {
            $dispatched = $export_row['dispatched_orders'];
            $delivered = $export_row['delivered_orders'];
            
            // Calculate success rate
            if ($dispatched == 0) {
                $success_rate = 'N/A';
                $performance_rating = 'No Data';
            } else {
                $rate = ($delivered / $dispatched) * 100;
                $success_rate = number_format($rate, 2);
                
                // Determine performance rating
                if ($rate >= 80) {
                    $performance_rating = 'Excellent';
                } elseif ($rate >= 60) {
                    $performance_rating = 'Good';
                } elseif ($rate >= 40) {
                    $performance_rating = 'Average';
                } else {
                    $performance_rating = 'Poor';
                }
            }
            
            fputcsv($output, [
                $export_row['user_id'],
                $export_row['username'],
                $export_row['email'],
                $export_row['phone'] ?: 'N/A',
                $export_row['nic'] ?: 'N/A',
                $export_row['role'] ?: 'User',
                $export_row['tenant_name'] ?: 'N/A',
                ucfirst($export_row['status']),
                $dispatched,
                $delivered,
                $export_row['cancelled_orders'],
                $export_row['pending_orders'],
                $export_row['waiting_orders'],
                $success_rate,
                $performance_rating,
                date('Y-m-d H:i:s', strtotime($export_row['created_at']))
            ]);
        }
    }
    
    fclose($output);
    exit();
}

// If we reach here, user is admin and NOT exporting - continue with page display
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/sidebar.php');

// ============================================
// HANDLE SEARCH AND FILTER PARAMETERS
// ============================================
// General search: Searches across name, email, phone, and NIC
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Role filter: Filter by user role (Admin, Courier, etc.)
$role_filter = isset($_GET['role_filter']) ? trim($_GET['role_filter']) : '';

// Status filter: Filter by active/inactive status
// - 'active': Users who can access the system
// - 'inactive': Users who are disabled/suspended
$status_filter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : '';

// Date range filter: Filter users by their account creation date
// Useful for reports like "Show me all users created in Q4 2024"
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$tenant_filter = isset($_GET['tenant_filter']) ? trim($_GET['tenant_filter']) : '';

// Pagination settings
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Base SQL for counting total records
$countSql = "SELECT COUNT(*) as total FROM users u LEFT JOIN tenants t ON u.tenant_id = t.tenant_id";

// Main query with success rate calculation
$sql = "SELECT u.id as user_id, u.name as username, u.email, u.mobile as phone, 
               u.nic, r.name as role, u.status, u.created_at, t.company_name as tenant_name,
               (SELECT COUNT(*) FROM order_header WHERE user_id = u.id AND status NOT IN ('pending', 'cancel', 'dispatch','waiting')) as dispatched_orders,
               (SELECT COUNT(*) FROM order_header WHERE user_id = u.id AND status IN ('done', 'delivered')) as delivered_orders
        FROM users u 
        LEFT JOIN roles r ON u.role_id = r.id
        LEFT JOIN tenants t ON u.tenant_id = t.tenant_id";

// Build search conditions
$searchConditions = [];

// ============================================
// APPLY FILTERS TO SQL QUERY
// ============================================

// General search filter: Matches partial text in name, email, phone, or NIC
if (!empty($search)) {
    $searchTerm = $conn->real_escape_string($search);
    $searchConditions[] = "(u.name LIKE '%$searchTerm%' OR 
                            u.email LIKE '%$searchTerm%' OR 
                            u.mobile LIKE '%$searchTerm%' OR 
                            u.nic LIKE '%$searchTerm%')";
}

// Role filter: Exact match for role name
if (!empty($role_filter)) {
    $roleTerm = $conn->real_escape_string($role_filter);
    $searchConditions[] = "r.name = '$roleTerm'";
}

// Status filter: Shows only active or inactive users
if (!empty($status_filter)) {
    $statusTerm = $conn->real_escape_string($status_filter);
    $searchConditions[] = "u.status = '$statusTerm'";
}

// Date FROM filter: Shows users created on or after this date
if (!empty($date_from)) {
    $dateFromTerm = $conn->real_escape_string($date_from);
    $searchConditions[] = "DATE(u.created_at) >= '$dateFromTerm'";
}

// Date TO filter: Shows users created on or before this date
if (!empty($date_to)) {
    $dateToTerm = $conn->real_escape_string($date_to);
    $searchConditions[] = "DATE(u.created_at) <= '$dateToTerm'";
}

// Access Scoping for Main Query
if (!$is_main_admin) {
    $searchConditions[] = "u.tenant_id = " . (int)$session_tenant_id;
} elseif (!empty($tenant_filter)) {
    $searchConditions[] = "u.tenant_id = " . (int)$tenant_filter;
}

// Apply all search conditions to the query
if (!empty($searchConditions)) {
    $finalSearchCondition = " WHERE " . implode(' AND ', $searchConditions);
    $countSql = "SELECT COUNT(*) as total FROM users u LEFT JOIN roles r ON u.role_id = r.id LEFT JOIN tenants t ON u.tenant_id = t.tenant_id" . $finalSearchCondition;
    $sql .= $finalSearchCondition;
}

// Add ordering and pagination
$sql .= " ORDER BY u.created_at DESC LIMIT $limit OFFSET $offset";

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

// Get unique roles for filter dropdown
$role_sql = "SELECT DISTINCT r.name as role FROM roles r WHERE r.name IS NOT NULL AND r.name != '' ORDER BY r.name";
$role_result = $conn->query($role_sql);
$roles = [];
if ($role_result && $role_result->num_rows > 0) {
    $roles = $role_result->fetch_all(MYSQLI_ASSOC);  // ← FIXED: Changed from order_managementSQLI_ASSOC
}

// Get active tenants for filter dropdown
$tenants_list = [];
$tenants_sql = "SELECT tenant_id, company_name FROM tenants WHERE status = 'active' ORDER BY company_name";
$tenants_result = $conn->query($tenants_sql);
if ($tenants_result && $tenants_result->num_rows > 0) {
    $tenants_list = $tenants_result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Calculate User Success Rate
 * 
 * SUCCESS RATE EXPLANATION:
 * -------------------------
 * The success rate measures how effectively a user (typically a courier/delivery person) 
 * completes their assigned deliveries.
 * 
 * Formula: (Delivered Orders / Dispatched Orders) × 100
 * 
 * ORDER STATUS MEANINGS:
 * - 'dispatch': Order has been assigned to courier for delivery
 * - 'done': Order successfully delivered to customer
 * - 'pending': Order created but not yet dispatched
 * - 'cancel': Order cancelled by customer or admin
 * - 'no_answer': Customer didn't answer call (from call_log field)
 * 
 * WHY ONLY 'dispatch' and 'done' ARE USED:
 * - We only count orders that were actually assigned (dispatched) to the user
 * - Success = How many of those dispatched orders were successfully delivered (done)
 * - Pending orders aren't counted because they haven't been assigned yet
 * - Cancelled orders aren't counted as failures because they're not delivery failures
 * 
 * Real-World Example:
 * If a courier is assigned 100 orders (dispatched) and successfully delivers 85 of them (done),
 * their success rate is 85%. This helps management identify:
 * - Top performers (80%+ success rate)
 * - Training needs (40-60% success rate)
 * - Problem areas (<40% success rate)
 * 
 * Ratings:
 * - Excellent: 80%+ (Green badge)
 * - Good: 60-79% (Blue badge)
 * - Average: 40-59% (Yellow badge)
 * - Poor: <40% (Red badge)
 * - N/A: No dispatched orders yet (Gray badge)
 */
function calculateSuccessRate($dispatched, $delivered) {
    if ($dispatched == 0) {
        return ['rate' => 0, 'display' => 'N/A'];
    }
    $rate = ($delivered / $dispatched) * 100;
    return ['rate' => $rate, 'display' => number_format($rate, 2) . '%'];
}

// Function to get success rate badge color
function getSuccessRateBadgeClass($rate) {
    if ($rate >= 80) return 'success-rate-excellent';
    if ($rate >= 60) return 'success-rate-good';
    if ($rate >= 40) return 'success-rate-average';
    return 'success-rate-poor';
}
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Order Management Admin Portal - User Management</title>
    
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/head.php'); ?>
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/orders.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/customers.css" id="main-style-link" />
    
    <style>
        /* Success Rate Badge Styles */
        .success-rate-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            min-width: 60px;
        }
        
        .success-rate-excellent {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .success-rate-good {
            background-color: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        .success-rate-average {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        
        .success-rate-poor {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .success-rate-na {
            background-color: #e9ecef;
            color: #6c757d;
            border: 1px solid #dee2e6;
        }
        
        .order-stats {
            font-size: 11px;
            color: #6c757d;
            margin-top: 4px;
        }
        
        .order-stats-item {
            display: inline-block;
            margin-right: 8px;
        }
        
        .order-stats-label {
            font-weight: 600;
            color: #495057;
        }
        
        /* Export Button Styling */
        .export-btn {
            background: #28a745;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .export-btn:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.3);
        }
        
        .export-btn i {
            font-size: 16px;
        }
        
        .header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding: 0 15px;
        }
        
        .filter-info {
            font-size: 13px;
            color: #6c757d;
        }
    </style>
</head>

<body>
    <!-- Page Loader -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/loader.php'); ?>

    <div class="pc-container">
        <div class="pc-content">
            
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">User Management</h5>
                        <small class="text-muted">Administrator Access - User Performance & Success Rate</small>
                    </div>
                </div>
            </div>

            <div class="main-content-wrapper">
                
                <!-- User Filter Section -->
                <div class="tracking-container">
                    <form class="tracking-form" method="GET" action="">
                        <!-- General Search: Searches across name, email, phone, and NIC -->
                        <div class="form-group">
                            <label for="search">
                                Search
                                <small style="color: #6c757d; font-weight: normal;"></small>
                            </label>
                            <input type="text" id="search" name="search" 
                                   placeholder="Search by name, email, phone, or NIC" 
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        
                        <!-- Role Filter: Filter by user role (Admin, Courier, Manager, etc.) -->
                        <div class="form-group">
                            <label for="role_filter">
                                Role
                                <small style="color: #6c757d; font-weight: normal;"></small>
                            </label>
                            <select id="role_filter" name="role_filter">
                                <option value="">All Roles</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo htmlspecialchars($role['role']); ?>" 
                                            <?php echo $role_filter == $role['role'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($role['role']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Status Filter: Active users can login, Inactive users are disabled -->
                        <div class="form-group">
                            <label for="status_filter">
                                Status
                                <small style="color: #6c757d; font-weight: normal;"></small>
                            </label>
                            <select id="status_filter" name="status_filter">
                                <option value="">All Status</option>
                                <option value="active" <?php echo ($status_filter == 'active') ? 'selected' : ''; ?>>
                                    Active (Can Login)
                                </option>
                                <option value="inactive" <?php echo ($status_filter == 'inactive') ? 'selected' : ''; ?>>
                                    Inactive (Disabled)
                                </option>
                            </select>
                        </div>
                        
                        <!-- Date From: Show users created on or after this date -->
                        <div class="form-group">
                            <label for="date_from">
                                Date From
                                <small style="color: #6c757d; font-weight: normal;">(Created Date)</small>
                            </label>
                            <input type="date" id="date_from" name="date_from" 
                                   value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        
                        <!-- Date To: Show users created on or before this date -->
                        <div class="form-group">
                            <label for="date_to">
                                Date To
                                <small style="color: #6c757d; font-weight: normal;">(Created Date)</small>
                            </label>
                            <input type="date" id="date_to" name="date_to" 
                                   value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>

                        <?php if ($is_main_admin): ?>
                        <!-- Tenant Filter: Only for Main Admins -->
                        <div class="form-group">
                            <label for="tenant_filter">
                                Tenant
                                <small style="color: #6c757d; font-weight: normal;"></small>
                            </label>
                            <select id="tenant_filter" name="tenant_filter">
                                <option value="">All Tenants</option>
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

                <!-- Header Actions: User Count and Export Button -->
                <div class="header-actions">
                    <div>
                        <div class="order-count-container" style="margin: 0;">
                            <div class="order-count-number"><?php echo number_format($totalRows); ?></div>
                            <div class="order-count-dash">-</div>
                            <div class="order-count-subtitle">Total Users</div>
                        </div>
                        <?php if (!empty($search) || !empty($role_filter) || !empty($status_filter) || !empty($date_from) || !empty($date_to) || !empty($tenant_filter)): ?>
                            <div class="filter-info" style="margin-top: 5px;">
                                <i class="fas fa-filter"></i> Filters applied
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Export Success Report Button -->
                    <div>
                        <button type="button" class="export-btn" onclick="exportSuccessReport()">
                            <i class="fas fa-file-excel"></i>
                            Export Success Report
                        </button>
                    </div>
                </div>

                <!-- Users Table -->
                <div class="table-wrapper">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>User Info</th>
                                <?php if ($is_main_admin): ?>
                                <th>Tenant</th>
                                <?php endif; ?>
                                <th>Contact & NIC</th>
                                <th>Role & Status</th>
                                <th>Success Rate</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="usersTableBody">
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): 
                                    $successRate = calculateSuccessRate($row['dispatched_orders'], $row['delivered_orders']);
                                    $badgeClass = $successRate['display'] === 'N/A' ? 'success-rate-na' : getSuccessRateBadgeClass($successRate['rate']);
                                ?>
                                    <tr>
                                        <!-- User Info -->
                                        <td class="customer-name">
                                            <div class="customer-info">
                                                <h6 style="margin: 0; font-size: 14px; font-weight: 600;"><?php echo htmlspecialchars($row['username']); ?></h6>
                                                <small style="color: #6c757d; font-size: 12px;">ID: <?php echo htmlspecialchars($row['user_id']); ?></small>
                                            </div>
                                        </td>

                                        <!-- Tenant Info - Only for Main Admins -->
                                        <?php if ($is_main_admin): ?>
                                        <td>
                                            <div style="font-weight: 500; color: #495057;">
                                                <?php echo htmlspecialchars($row['tenant_name'] ?: 'N/A'); ?>
                                            </div>
                                        </td>
                                        <?php endif; ?>
                                        
                                        <!-- Contact Info & NIC -->
                                        <td>
                                            <div style="line-height: 1.4;">
                                                <div style="font-weight: 500; margin-bottom: 2px;"><?php echo htmlspecialchars($row['phone'] ?: 'N/A'); ?></div>
                                                <div style="font-size: 12px; color: #6c757d; margin-bottom: 2px;"><?php echo htmlspecialchars($row['email']); ?></div>
                                                <?php if (!empty($row['nic'])): ?>
                                                    <div style="font-size: 11px; color: #007bff; font-weight: 500;">NIC: <?php echo htmlspecialchars($row['nic']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        
                                        <!-- Role & Status -->
                                        <td>
                                            <div style="line-height: 1.4;">
                                                <div style="font-weight: 500; margin-bottom: 4px; color: #495057;">
                                                    <?php echo htmlspecialchars($row['role'] ?: 'User'); ?>
                                                </div>
                                                <?php if ($row['status'] === 'active'): ?>
                                                    <span class="status-badge pay-status-paid">Active</span>
                                                <?php else: ?>
                                                    <span class="status-badge pay-status-unpaid">Inactive</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        
                                        <!-- Success Rate -->
                                        <td>
                                            <div style="line-height: 1.6;">
                                                <span class="success-rate-badge <?php echo $badgeClass; ?>">
                                                    <?php echo $successRate['display']; ?>
                                                </span>
                                                <div class="order-stats">
                                                    <span class="order-stats-item">
                                                        <span class="order-stats-label">Dispatched:</span> <?php echo $row['dispatched_orders']; ?>
                                                    </span>
                                                    <span class="order-stats-item">
                                                        <span class="order-stats-label">Delivered:</span> <?php echo $row['delivered_orders']; ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </td>
                                        
                                        <!-- Created -->
                                        <td>
                                            <div style="font-size: 12px; line-height: 1.4;">
                                                <div style="font-weight: 500;"><?php echo date('M d, Y', strtotime($row['created_at'])); ?></div>
                                                <div style="color: #6c757d;"><?php echo date('h:i A', strtotime($row['created_at'])); ?></div>
                                            </div>
                                        </td>
                                        
                                        <!-- Action Buttons - ONLY VIEW -->
                                        <td class="actions">
                                            <div class="action-buttons-group">
                                                <button type="button" class="action-btn view-btn view-user-btn"
                                                        data-user-id="<?= $row['user_id'] ?>"
                                                        data-username="<?= htmlspecialchars($row['username']) ?>"
                                                        data-user-email="<?= htmlspecialchars($row['email']) ?>"
                                                        data-user-phone="<?= htmlspecialchars($row['phone']) ?>"
                                                        data-user-nic="<?= htmlspecialchars($row['nic']) ?>"
                                                        data-user-role="<?= htmlspecialchars($row['role']) ?>"
                                                        data-user-tenant="<?= htmlspecialchars($row['tenant_name']) ?>"
                                                        data-user-status="<?= htmlspecialchars($row['status']) ?>"
                                                        data-user-created="<?= htmlspecialchars($row['created_at']) ?>"
                                                        data-dispatched-orders="<?= $row['dispatched_orders'] ?>"
                                                        data-delivered-orders="<?= $row['delivered_orders'] ?>"
                                                        data-success-rate="<?= $successRate['display'] ?>"
                                                        title="View User Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="<?php echo $is_main_admin ? 7 : 6; ?>" class="text-center" style="padding: 40px; text-align: center; color: #666;">
                                        <i class="fas fa-users" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                                        No users found
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
                            <button class="page-btn" onclick="window.location.href='?page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>&role_filter=<?php echo urlencode($role_filter); ?>&status_filter=<?php echo urlencode($status_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>'">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <button class="page-btn <?php echo ($i == $page) ? 'active' : ''; ?>" 
                                    onclick="window.location.href='?page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&role_filter=<?php echo urlencode($role_filter); ?>&status_filter=<?php echo urlencode($status_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>'">
                                <?php echo $i; ?>
                            </button>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <button class="page-btn" onclick="window.location.href='?page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>&role_filter=<?php echo urlencode($role_filter); ?>&status_filter=<?php echo urlencode($status_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>&tenant_filter=<?php echo urlencode($tenant_filter); ?>'">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- User Details Modal -->
    <div id="userDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h4>User Details</h4>
                <span class="close" onclick="closeUserModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="customer-detail-row">
                    <span class="detail-label">User ID:</span>
                    <span class="detail-value" id="modal-user-id"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Name:</span>
                    <span class="detail-value" id="modal-username"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Email:</span>
                    <span class="detail-value" id="modal-user-email"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Phone:</span>
                    <span class="detail-value" id="modal-user-phone"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">NIC Number:</span>
                    <span class="detail-value" id="modal-user-nic"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Role:</span>
                    <span class="detail-value" id="modal-user-role"></span>
                </div>
                <?php if ($is_main_admin): ?>
                <div class="customer-detail-row">
                    <span class="detail-label">Tenant:</span>
                    <span class="detail-value" id="modal-user-tenant"></span>
                </div>
                <?php endif; ?>
                <div class="customer-detail-row">
                    <span class="detail-label">Status:</span>
                    <span class="detail-value">
                        <span id="modal-user-status" class="status-badge"></span>
                    </span>
                </div>
                <div class="customer-detail-row" style="border-top: 2px solid #e9ecef; margin-top: 15px; padding-top: 15px;">
                    <span class="detail-label">Success Rate:</span>
                    <span class="detail-value">
                        <span id="modal-success-rate" class="success-rate-badge"></span>
                    </span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Dispatched Orders:</span>
                    <span class="detail-value" id="modal-dispatched-orders"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Delivered Orders:</span>
                    <span class="detail-value" id="modal-delivered-orders"></span>
                </div>
                <div class="customer-detail-row" style="border-top: 2px solid #e9ecef; margin-top: 15px; padding-top: 15px;">
                    <span class="detail-label">Created:</span>
                    <span class="detail-value" id="modal-user-created"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/scripts.php'); ?>

    <script>
        /* ================================
           FILTER CLEAR
        ================================= */
        function clearFilters() {
            window.location.href = window.location.pathname;
        }

        /* ================================
           EXPORT SUCCESS REPORT
        ================================= */
        function exportSuccessReport() {
            // Get current filter values
            const search = document.getElementById('search').value;
            const roleFilter = document.getElementById('role_filter').value;
            const statusFilter = document.getElementById('status_filter').value;
            const dateFrom = document.getElementById('date_from').value;
            const dateTo = document.getElementById('date_to').value;
            const tenantFilter = document.getElementById('tenant_filter').value;
            
            // Build export URL with all filters
            let exportUrl = window.location.pathname + '?export=success_report';
            
            if (search) exportUrl += '&search=' + encodeURIComponent(search);
            if (roleFilter) exportUrl += '&role_filter=' + encodeURIComponent(roleFilter);
            if (statusFilter) exportUrl += '&status_filter=' + encodeURIComponent(statusFilter);
            if (dateFrom) exportUrl += '&date_from=' + encodeURIComponent(dateFrom);
            if (dateTo) exportUrl += '&date_to=' + encodeURIComponent(dateTo);
            
            const tenantFilterElement = document.getElementById('tenant_filter');
            if (tenantFilterElement) {
                const tenantFilter = tenantFilterElement.value;
                if (tenantFilter) exportUrl += '&tenant_filter=' + encodeURIComponent(tenantFilter);
            }
            
            // Redirect to export URL (will download CSV file)
            window.location.href = exportUrl;
        }

        /* ================================
           USER DETAILS MODAL
        ================================= */
        document.querySelectorAll('.view-user-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                document.getElementById('modal-user-id').innerText = this.dataset.userId;
                document.getElementById('modal-username').innerText = this.dataset.username;
                document.getElementById('modal-user-email').innerText = this.dataset.userEmail || 'N/A';
                document.getElementById('modal-user-phone').innerText = this.dataset.userPhone || 'N/A';
                document.getElementById('modal-user-nic').innerText = this.dataset.userNic || 'N/A';
                document.getElementById('modal-user-role').innerText = this.dataset.userRole || 'User';
                document.getElementById('modal-user-tenant').innerText = this.dataset.userTenant || 'N/A';

                const statusBadge = document.getElementById('modal-user-status');
                statusBadge.innerText = this.dataset.userStatus;
                statusBadge.className = 'status-badge ' +
                    (this.dataset.userStatus === 'active' ? 'pay-status-paid' : 'pay-status-unpaid');

                document.getElementById('modal-success-rate').innerText = this.dataset.successRate;
                document.getElementById('modal-dispatched-orders').innerText = this.dataset.dispatchedOrders;
                document.getElementById('modal-delivered-orders').innerText = this.dataset.deliveredOrders;
                document.getElementById('modal-user-created').innerText = this.dataset.userCreated;

                document.getElementById('userDetailsModal').style.display = 'block';
            });
        });

        function closeUserModal() {
            document.getElementById('userDetailsModal').style.display = 'none';
        }

        /* ================================
           CLOSE MODALS ON OUTSIDE CLICK
        ================================= */
        window.onclick = function (event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = "none";
            }
        }
    </script>

    <!--Footer-->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/footer.php'); ?>

</body>
</html>