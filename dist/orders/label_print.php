<?php
/**
 * Label Print Page
 * Displays orders for label printing with date filters
 * Multi-tenant support added for Main Admins
 */

// Start session management
session_start();

// Authentication check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /order_management/dist/pages/login.php");
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/connection/db_connection.php');

/**
 * SEARCH AND FILTER PARAMETERS
 * Default: Today's date, Updated Date filter, Dispatch status
 */
$is_main_admin = isset($_SESSION['is_main_admin']) ? (int)$_SESSION['is_main_admin'] : 0;
$role_id = isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : 0;
$tenant_id_session = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;

$date = isset($_GET['date']) ? trim($_GET['date']) : date('Y-m-d'); // Default to today
$time_from = isset($_GET['time_from']) ? trim($_GET['time_from']) : '';
$time_to = isset($_GET['time_to']) ? trim($_GET['time_to']) : '';
$tenant_filter = isset($_GET['tenant_filter']) ? trim($_GET['tenant_filter']) : '';

$status_filter = 'dispatch'; // Always dispatch status
$date_filter = 'updated_at'; // Always filter by updated_at

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

/**
 * SANITIZE AND VALIDATE INPUTS
 */
$date = $conn->real_escape_string($date);
$time_from = $conn->real_escape_string($time_from);
$time_to = $conn->real_escape_string($time_to);

// Normalize time inputs (HH:MM format)
$time_from = preg_match('/^\d{1,2}:\d{2}$/', $time_from) ? $time_from : "";
$time_to = preg_match('/^\d{1,2}:\d{2}$/', $time_to) ? $time_to : "";

/**
 * BUILD WHERE CONDITIONS
 */
$where = [];
$where[] = "o.interface IN ('individual', 'leads')";
$where[] = "o.status = 'dispatch'"; // Always dispatch status

// Access Control Logic
if ($is_main_admin === 1 && $role_id === 1) {
    // Main Admin: View All or Filter Specific Tenant
    if (!empty($tenant_filter)) {
        $tenant_id_safe = (int)$tenant_filter;
        $where[] = "o.tenant_id = $tenant_id_safe";
    }
} elseif ($role_id === 1 && $is_main_admin === 0) {
    // Tenant Admin: View All in their Tenant
    $where[] = "o.tenant_id = $tenant_id_session";
} else {
    // Regular User: View Only Assigned Orders in their Tenant
    $logged_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $where[] = "o.tenant_id = $tenant_id_session";
    $where[] = "o.user_id = $logged_user_id";
}

// Date and time range filter
$startDateTime = $date . " 00:00:00";
$endDateTime = $date . " 23:59:59";

// Apply time range if provided
if ($time_from !== "" && $time_to !== "") {
    $startDateTime = $date . " $time_from:00";
    $endDateTime = $date . " $time_to:59";
} elseif ($time_from !== "") {
    $startDateTime = $date . " $time_from:00";
    $endDateTime = $date . " 23:59:59";
} elseif ($time_to !== "") {
    $startDateTime = $date . " 00:00:00";
    $endDateTime = $date . " $time_to:59";
}

// Always filter by updated_at
$where[] = "o.updated_at BETWEEN '$startDateTime' AND '$endDateTime'";

// Always include only orders with tracking numbers
$where[] = "o.tracking_number IS NOT NULL AND o.tracking_number != ''";

$whereClause = implode(" AND ", $where);

/**
 * DATABASE QUERIES
 */
// Count query
$countSql = "SELECT COUNT(*) as total FROM order_header o 
             LEFT JOIN customers c ON o.customer_id = c.customer_id
             WHERE $whereClause";

// Main query
$sql = "SELECT o.order_id, o.customer_id, o.full_name, o.mobile, o.address_line1, o.address_line2,
               o.status, o.updated_at, o.created_at, o.issue_date, o.interface, o.tracking_number, 
               o.total_amount, o.currency,
               c.name as customer_name, c.phone as customer_phone, 
               CONCAT_WS(', ', c.address_line1, c.address_line2) as customer_address,
               COALESCE(o.full_name, c.name) as display_name,
               COALESCE(o.mobile, c.phone) as display_mobile,
               COALESCE(CONCAT_WS(', ', o.address_line1, o.address_line2), 
                       CONCAT_WS(', ', c.address_line1, c.address_line2)) as display_address
        FROM order_header o 
        LEFT JOIN customers c ON o.customer_id = c.customer_id
        WHERE $whereClause
        ORDER BY o.updated_at DESC, o.order_id DESC 
        LIMIT $limit OFFSET $offset";

// Execute queries
$countResult = $conn->query($countSql);
$totalRows = 0;
if ($countResult && $countResult->num_rows > 0) {
    $totalRows = $countResult->fetch_assoc()['total'];
}
$totalPages = ceil($totalRows / $limit);
$result = $conn->query($sql);

// Fetch tenants for Main Admin filter
$tenants = [];
if ($is_main_admin === 1 && $role_id === 1) {
    $tenantsResult = $conn->query("SELECT tenant_id, company_name FROM tenants WHERE status = 'active' ORDER BY company_name ASC");
    if ($tenantsResult) {
        while ($t = $tenantsResult->fetch_assoc()) {
            $tenants[] = $t;
        }
    }
}

// Include navigation components
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/sidebar.php');
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Label Print - Order Management</title>
    
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/head.php'); ?>
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/orders.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/label_print.css" id="main-style-link" />

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
                        <h5 class="mb-0 font-medium">Label Print - Dispatch Orders</h5>
                    </div>
                </div>
            </div>
            <div class="main-content-wrapper">

                <!-- Filters Container -->
                <div class="filters-container">
                    <form method="GET" action="" id="filterForm">
                        <div class="filter-row">
                            <div class="filter-group">
                                <label for="date">Date</label>
                                <input type="date" id="date" name="date" 
                                       value="<?php echo htmlspecialchars($date); ?>" required>
                            </div>
                            
                            <div class="filter-group">
                                <label for="date_filter">Filter By</label>
                                <input type="text" id="date_filter_display" value="Updated Date" 
                                       readonly style="background-color: #e9ecef; cursor: not-allowed;">
                                <input type="hidden" name="date_filter" value="updated_at">
                            </div>
                            
                            <div class="filter-group">
                                <label for="time_from">Time From</label>
                                <input type="time" id="time_from" name="time_from" 
                                       value="<?php echo htmlspecialchars($time_from); ?>" placeholder="HH:MM">
                            </div>
                            
                            <div class="filter-group">
                                <label for="time_to">Time To</label>
                                <input type="time" id="time_to" name="time_to" 
                                       value="<?php echo htmlspecialchars($time_to); ?>" placeholder="HH:MM">
                            </div>
                            
                            <div class="filter-group">
                                <label for="status_filter">Status</label>
                                <input type="text" id="status_filter_display" value="Dispatch" 
                                       readonly style="background-color: #e9ecef; cursor: not-allowed;">
                                <input type="hidden" name="status_filter" value="dispatch">
                            </div>

                            <?php if ($is_main_admin === 1 && $role_id === 1): ?>
                            <div class="filter-group">
                                <label for="tenant_filter">Tenant</label>
                                <select id="tenant_filter" name="tenant_filter">
                                    <option value="">All Tenants</option>
                                    <?php foreach ($tenants as $t): ?>
                                        <option value="<?php echo $t['tenant_id']; ?>" 
                                            <?php echo ($tenant_filter == $t['tenant_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($t['company_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>

                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter"></i>
                                    Filter
                                </button>
                                <button type="button" class="clear-btn" onclick="clearFilters()">
                                    <i class="fas fa-times"></i>
                                    Clear
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Results Info -->
                <div class="results-info">
                    <strong>Total orders found:</strong> <?php echo $totalRows; ?>
                    <?php if (!empty($time_from) || !empty($time_to)): ?>
                        <span style="color: #666;">
                            (Time range: <?php echo $time_from ?: '00:00'; ?> - <?php echo $time_to ?: '23:59'; ?>)
                        </span>
                    <?php endif; ?>
                    <span style="color: #666; margin-left: 15px;">
                        Status: <strong>Dispatch</strong> | Filtering by: <strong>Updated Date</strong>
                    </span>
                </div>

                <!-- Print Buttons -->
                <div class="print-buttons">
                    <button class="print-btn" onclick="printLabels('9x9')">
                        <i class="fas fa-print"></i>
                        Print 10×10 Labels
                    </button>
                    <button class="print-btn" onclick="printLabels('4x13')">
                        <i class="fas fa-print"></i>
                        Print 4×13 Labels
                    </button>
                    <button class="print-btn" onclick="printLabels('regular')">
                        <i class="fas fa-print"></i>
                       Print 4×6 Labels
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
             // Print labels function
        function printLabels(format) {
            // Build parameters object with current filter values from the form
            const params = new URLSearchParams();
            
            // Get values from form inputs
            const date = document.getElementById('date').value;
            const timeFrom = document.getElementById('time_from').value;
            const timeTo = document.getElementById('time_to').value;
            const statusFilter = document.querySelector('input[name="status_filter"]').value;
            const dateFilter = document.querySelector('input[name="date_filter"]').value;
            
            // Add filter parameters
            if (date) params.set('date', date);
            if (timeFrom) params.set('time_from', timeFrom);
            if (timeTo) params.set('time_to', timeTo);
            params.set('status_filter', statusFilter);
            params.set('date_filter', dateFilter);
            
            // Add tenant filter if exists (for main admin)
            const tenantFilter = document.getElementById('tenant_filter');
            if (tenantFilter && tenantFilter.value) {
                params.set('tenant_filter', tenantFilter.value);
            }
            
            // Add format
            params.set('format', format);
            
            // Set high limit to get all matching orders
            params.set('limit', '1000');

            // Open print page in new window
            const printUrl = 'bulk_print.php?' + params.toString();
            const printWindow = window.open(printUrl, '_blank');
            if (!printWindow) {
                alert('Please allow popups for this site to open the print window.');
            }
        }
        // Clear filters function
        function clearFilters() {
            // Reset date and time fields only
            document.getElementById('date').value = '<?php echo date('Y-m-d'); ?>';
            document.getElementById('time_from').value = '';
            document.getElementById('time_to').value = '';
            
            const tenantFilter = document.getElementById('tenant_filter');
            if(tenantFilter) tenantFilter.value = '';
            
            // Submit the form to apply the cleared filters
            document.getElementById('filterForm').submit();
        }
        
        // Auto-submit form on date change
        document.addEventListener('DOMContentLoaded', function() {
            const dateInput = document.getElementById('date');
            dateInput.addEventListener('change', function() {
                document.getElementById('filterForm').submit();
            });
            
            console.log('Label print page loaded');
            console.log('Total orders found: <?php echo $totalRows; ?>');
            console.log('Filter: Updated Date | Status: Dispatch');
        });
    </script>

    <!-- Include Footer and Scripts -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/footer.php'); ?>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/scripts.php'); ?>

</body>
</html>