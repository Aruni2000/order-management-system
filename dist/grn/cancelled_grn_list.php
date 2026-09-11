<?php
/**
 * Cancelled GRNs List
 * Standalone page displaying GRNs with status 'cancelled' (view only).
 * Modeled after the order management pages (e.g. cancel_order_list.php).
 * Includes search, filters, pagination, and modal view functionality.
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

if (!isset($_SESSION['is_main_admin']) || $_SESSION['is_main_admin'] != 1 || !in_array((int)($_SESSION['role_id'] ?? 0), [1, 3], true)) {
    header("Location: /orderhub_nextwave/dist/dashboard/index.php");
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/connection/db_connection.php');

/**
 * SEARCH AND PAGINATION PARAMETERS
 */
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$supplier_filter = isset($_GET['supplier_filter']) ? intval($_GET['supplier_filter']) : 0;
$tenant_filter = isset($_GET['tenant_filter']) ? intval($_GET['tenant_filter']) : 0;
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Fetch active tenants for filter
$tenants = [];
$tRes = $conn->query("SELECT tenant_id, company_name FROM tenants WHERE status = 'active' ORDER BY company_name ASC");
if ($tRes) {
    while ($row = $tRes->fetch_assoc()) {
        $tenants[] = $row;
    }
}

// Fetch active suppliers for filter
$suppliers = [];
$supResult = $conn->query("SELECT id, name FROM suppliers WHERE status = 'active' ORDER BY name ASC");
if ($supResult) {
    while ($row = $supResult->fetch_assoc()) {
        $suppliers[] = $row;
    }
}

// Count query - cancelled GRNs only
$countSql = "SELECT COUNT(*) as total FROM grn g LEFT JOIN suppliers s ON g.supplier_id = s.id LEFT JOIN tenants t ON g.tenant_id = t.tenant_id WHERE g.status = 'cancelled'";
// Main query - cancelled GRNs only
$sql = "SELECT g.*, s.name as supplier_name, t.company_name FROM grn g LEFT JOIN suppliers s ON g.supplier_id = s.id LEFT JOIN tenants t ON g.tenant_id = t.tenant_id WHERE g.status = 'cancelled'";

// Build search conditions
$conditions = [];
if (!empty($search)) {
    $t = $conn->real_escape_string($search);
    $conditions[] = "(g.grn_number LIKE '%$t%' OR s.name LIKE '%$t%' OR t.company_name LIKE '%$t%')";
}
if ($tenant_filter > 0) {
    $conditions[] = "g.tenant_id = $tenant_filter";
}
if ($supplier_filter > 0) {
    $conditions[] = "g.supplier_id = $supplier_filter";
}
if (!empty($date_from)) {
    $df = $conn->real_escape_string($date_from);
    $conditions[] = "g.received_date >= '$df'";
}
if (!empty($date_to)) {
    $dt = $conn->real_escape_string($date_to);
    $conditions[] = "g.received_date <= '$dt'";
}

if (!empty($conditions)) {
    $where = " AND " . implode(' AND ', $conditions);
    $countSql .= $where;
    $sql .= $where;
}

// Execute queries
$countResult = $conn->query($countSql);
$totalRows = 0;
if ($countResult && $countResult->num_rows > 0) {
    $totalRows = $countResult->fetch_assoc()['total'];
}
$totalPages = ceil($totalRows / $limit);
$sql .= " ORDER BY g.grn_id DESC LIMIT $limit OFFSET $offset";
$result = $conn->query($sql);
?>
<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">
<head>
    <title>Cancelled GRNs | <?= htmlspecialchars($_SESSION['company_name'] ?? 'orderhub_nextwave') ?></title>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/include/head.php'); ?>
    <link rel="stylesheet" href="../assets/css/orders.css" />
    <link rel="stylesheet" href="../assets/css/customers.css" />
    <link rel="stylesheet" href="../assets/css/status-badge-colors.css" />
</head>
<body>
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
                        <h5 class="mb-0 font-medium">Cancelled GRNs</h5>
                    </div>
                </div>
            </div>

            <div class="main-content-wrapper">
                <!-- Filter Section -->
                <div class="tracking-container">
                    <form class="tracking-form" method="GET" action="">
                        <div class="form-group">
                            <label for="search">Search</label>
                            <input type="text" id="search" name="search" placeholder="GRN number, supplier..."
                                value="<?php echo htmlspecialchars($search); ?>">
                        </div>

                        <div class="form-group">
                            <label for="tenant_filter">Tenant</label>
                            <select id="tenant_filter" name="tenant_filter">
                                <option value="">All Companies</option>
                                <?php foreach ($tenants as $t): ?>
                                    <option value="<?php echo $t['tenant_id']; ?>" <?php echo ($tenant_filter == $t['tenant_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($t['company_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="supplier_filter">Supplier</label>
                            <select id="supplier_filter" name="supplier_filter">
                                <option value="">All Suppliers</option>
                                <?php foreach ($suppliers as $s): ?>
                                    <option value="<?php echo $s['id']; ?>" <?php echo ($supplier_filter == $s['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($s['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

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
                                    <i class="fas fa-search"></i> Search
                                </button>
                                <button type="button" class="search-btn" onclick="window.location.href='cancelled_grn_list.php'" style="background: #6c757d;">
                                    <i class="fas fa-times"></i> Clear
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- GRN Count Display -->
                <div class="order-count-container">
                    <div class="order-count-number"><?php echo number_format($totalRows); ?></div>
                    <div class="order-count-dash">-</div>
                    <div class="order-count-subtitle">Total Cancelled GRNs</div>
                </div>

                <!-- GRNs Table -->
                <div class="table-wrapper">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>GRN Number</th>
                                <th>Tenant</th>
                                <th>Supplier</th>
                                <th>Received Date</th>
                                <th>Total Amount</th>
                                <th>Items</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <?php
                                    $itemCountResult = $conn->query("SELECT COUNT(*) as cnt, SUM(quantity) as total_qty FROM grn_items WHERE grn_id = " . $row['grn_id']);
                                    $itemData = $itemCountResult ? $itemCountResult->fetch_assoc() : ['cnt' => 0, 'total_qty' => 0];
                                    ?>
                                    <tr>
                                        <td class="order-id"><?php echo htmlspecialchars($row['grn_number']); ?></td>
                                        <td class="customer-name">
                                            <div class="customer-info">
                                                <h6 style="margin: 0; font-size: 14px;">
                                                    <?php echo isset($row['company_name']) && $row['company_name'] !== '' ? htmlspecialchars($row['company_name']) : 'N/A'; ?>
                                                </h6>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600; color: #1e293b;">
                                                <?php echo htmlspecialchars($row['supplier_name'] ?? 'Unknown'); ?>
                                            </div>
                                        </td>
                                        <td><?php echo date('Y-m-d', strtotime($row['received_date'])); ?></td>
                                        <td><strong>Rs. <?php echo number_format($row['total_amount'], 2); ?></strong></td>
                                        <td>
                                            <span style="font-size: 13px; color: #475569;">
                                                <?php echo $itemData['cnt']; ?> items (<?php echo number_format($itemData['total_qty'] ?? 0); ?> qty)
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge status-cancelled">Cancelled</span>
                                        </td>
                                        <td>
                                            <div class="action-buttons-group">
                                                <button class="action-btn view-btn" title="View GRN"
                                                    onclick="openGrnModal('<?php echo $row['grn_id']; ?>')">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 40px; color: #64748b;">
                                        <i class="fas fa-clipboard-list" style="font-size: 2.2rem; display: block; margin-bottom: 12px; color: #94a3b8;"></i>
                                        No cancelled GRNs found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div class="pagination">
                    <div class="pagination-info">
                        Showing <?php echo $totalRows > 0 ? $offset + 1 : 0; ?> to <?php echo min($offset + $limit, $totalRows); ?> of <?php echo $totalRows; ?> entries
                    </div>
                    <div class="pagination-controls">
                        <?php
                        $qp = $_GET; unset($qp['page']);
                        $qs = http_build_query($qp);
                        $base = '?' . ($qs ? $qs . '&' : '');
                        ?>
                        <?php if ($page > 1): ?>
                            <button class="page-btn" onclick="window.location.href='<?php echo $base; ?>page=<?php echo $page - 1; ?>'">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                        <?php endif; ?>
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <button class="page-btn <?php echo ($i == $page) ? 'active' : ''; ?>" onclick="window.location.href='<?php echo $base; ?>page=<?php echo $i; ?>'">
                                <?php echo $i; ?>
                            </button>
                        <?php endfor; ?>
                        <?php if ($page < $totalPages): ?>
                            <button class="page-btn" onclick="window.location.href='<?php echo $base; ?>page=<?php echo $page + 1; ?>'">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- GRN View Modal -->
    <div id="grnModal" class="modal-overlay">
        <div class="modal-container">
            <div class="modal-header">
                <h3 class="modal-title">GRN Details</h3>
                <button class="modal-close" onclick="closeGrnModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="grnModalContent">
                <div class="modal-loading">
                    <i class="fas fa-spinner fa-spin"></i>
                    Loading GRN details...
                </div>
            </div>
            <div class="modal-footer">
                <button class="modal-btn modal-btn-secondary" onclick="closeGrnModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button class="modal-btn modal-btn-primary" onclick="downloadGrn()" id="grnDownloadBtn" style="display:none;">
                    <i class="fas fa-download"></i> Download
                </button>
            </div>
        </div>
    </div>

    <?php include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/include/footer.php'); ?>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/include/scripts.php'); ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>

    <script>
    let currentGrnId = null;

    // Open GRN modal and load details
    function openGrnModal(grnId) {
        if (!grnId) {
            Swal.fire({ icon: 'warning', title: 'Missing ID', text: 'GRN ID is required.' });
            return;
        }

        currentGrnId = grnId;
        const modal = document.getElementById('grnModal');
        const modalContent = document.getElementById('grnModalContent');
        const downloadBtn = document.getElementById('grnDownloadBtn');

        // Show modal
        modal.style.display = 'flex';
        document.body.style.overflow = 'clip';

        // Show loading state
        modalContent.innerHTML = `
            <div class="modal-loading">
                <i class="fas fa-spinner fa-spin"></i>
                Loading GRN details for ID: ${currentGrnId}...
            </div>`;
        downloadBtn.style.display = 'none';

        // Fetch GRN details
        fetch('download_grn.php?id=' + encodeURIComponent(currentGrnId))
            .then(response => {
                if (!response.ok) throw new Error('HTTP error! status: ' + response.status);
                return response.text();
            })
            .then(data => {
                if (data.trim() === '') throw new Error('No data received from server.');
                modalContent.innerHTML = data;
                downloadBtn.style.display = 'inline-flex';
            })
            .catch(error => {
                modalContent.innerHTML = `
                    <div class="modal-error">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h4>Error Loading GRN Details</h4>
                        <p>GRN ID: ${currentGrnId}</p>
                        <p>Error: ${error.message}</p>
                        <button onclick="openGrnModal(currentGrnId)" class="btn btn-primary" style="margin-top: 10px;">
                            <i class="fas fa-redo"></i> Retry
                        </button>
                    </div>`;
            });
    }

    // Close GRN modal
    function closeGrnModal() {
        const modal = document.getElementById('grnModal');
        modal.style.display = 'none';
        document.body.style.overflow = '';
        currentGrnId = null;
    }

    // Download GRN (navigate to print page)
    function downloadGrn() {
        if (!currentGrnId) {
            Swal.fire({ icon: 'warning', title: 'No GRN Selected', text: 'Please select a GRN to download.' });
            return;
        }
        window.open('grn_print.php?id=' + encodeURIComponent(currentGrnId), '_blank');
    }

    // Close modal on outside click
    document.getElementById('grnModal').addEventListener('click', function(e) {
        if (e.target === this) closeGrnModal();
    });

    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeGrnModal();
    });
    </script>
</body>
</html>
<?php $conn->close(); ?>