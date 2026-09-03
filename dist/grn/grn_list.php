<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) ob_end_clean();
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$supplier_filter = isset($_GET['supplier_filter']) ? intval($_GET['supplier_filter']) : 0;
$status_filter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Fetch active suppliers for filter
$suppliers = [];
$supResult = $conn->query("SELECT id, name FROM suppliers WHERE status = 'active' ORDER BY name ASC");
if ($supResult) {
    while ($row = $supResult->fetch_assoc()) {
        $suppliers[] = $row;
    }
}

$countSql = "SELECT COUNT(*) as total FROM grn g LEFT JOIN suppliers s ON g.supplier_id = s.id WHERE 1=1";
$sql = "SELECT g.*, s.name as supplier_name FROM grn g LEFT JOIN suppliers s ON g.supplier_id = s.id WHERE 1=1";

$conditions = [];
if (!empty($search)) {
    $t = $conn->real_escape_string($search);
    $conditions[] = "(g.grn_number LIKE '%$t%' OR s.name LIKE '%$t%')";
}
if ($supplier_filter > 0) {
    $conditions[] = "g.supplier_id = $supplier_filter";
}
if (!empty($status_filter)) {
    $st = $conn->real_escape_string($status_filter);
    $conditions[] = "g.status = '$st'";
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
    <title>GRN List | <?= htmlspecialchars($_SESSION['company_name'] ?? 'OMS') ?></title>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>
    <link rel="stylesheet" href="../assets/css/orders.css" />
    <link rel="stylesheet" href="../assets/css/customers.css" />
    <link rel="stylesheet" href="../assets/css/status-badge-colors.css" />
</head>
<body>
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
                        <h5 class="mb-0 font-medium">Goods Received Notes (GRN)</h5>
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
                            <label for="status_filter">Status</label>
                            <select id="status_filter" name="status_filter">
                                <option value="">All Status</option>
                                <option value="draft" <?php echo ($status_filter == 'draft') ? 'selected' : ''; ?>>Draft</option>
                                <option value="confirmed" <?php echo ($status_filter == 'confirmed') ? 'selected' : ''; ?>>Confirmed</option>
                                <option value="cancelled" <?php echo ($status_filter == 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
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
                                <button type="button" class="search-btn" onclick="window.location.href='grn_list.php'" style="background: #6c757d;">
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
                    <div class="order-count-subtitle">Total GRNs</div>
                </div>

                <!-- GRNs Table -->
                <div class="table-wrapper">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>GRN Number</th>
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
                                        <td><strong><?php echo htmlspecialchars($row['grn_number']); ?></strong></td>
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
                                            <?php if ($row['status'] == 'draft'): ?>
                                                <span class="status-badge status-pending">Draft</span>
                                            <?php elseif ($row['status'] == 'confirmed'): ?>
                                                <span class="status-badge status-completed">Confirmed</span>
                                            <?php else: ?>
                                                <span class="status-badge status-cancelled">Cancelled</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons-group">
                                                <a href="view_grn.php?id=<?php echo $row['grn_id']; ?>" class="action-btn view-btn" title="View GRN">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <?php if ($row['status'] == 'draft'): ?>
                                                    <button class="action-btn paid-btn confirm-grn-btn"
                                                        data-id="<?php echo $row['grn_id']; ?>"
                                                        data-number="<?php echo htmlspecialchars($row['grn_number']); ?>"
                                                        title="Confirm GRN (Update Stock)">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                    <button class="action-btn unpaid-btn cancel-grn-btn"
                                                        data-id="<?php echo $row['grn_id']; ?>"
                                                        data-number="<?php echo htmlspecialchars($row['grn_number']); ?>"
                                                        title="Cancel GRN">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 40px; color: #64748b;">
                                        <i class="fas fa-clipboard-list" style="font-size: 2.2rem; display: block; margin-bottom: 12px; color: #94a3b8;"></i>
                                        No Goods Received Notes (GRN) found
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

    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php'); ?>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php'); ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>

    <script>
    // Confirm GRN
    document.querySelectorAll('.confirm-grn-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const number = this.dataset.number;
            Swal.fire({
                title: 'Confirm GRN?',
                text: 'This will add received item quantities to product inventory. GRN: ' + number,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#10b981',
                confirmButtonText: 'Yes, Confirm & Add Stock!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch('confirm_grn.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ grn_id: id })
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({ icon: 'success', title: 'Confirmed!', text: data.message, timer: 2000, showConfirmButton: false })
                                .then(() => location.reload());
                        } else {
                            Swal.fire({ icon: 'error', title: 'Error', text: data.message || 'Failed to confirm.' });
                        }
                    })
                    .catch(() => Swal.fire({ icon: 'error', title: 'Error', text: 'An error occurred.' }));
                }
            });
        });
    });

    // Cancel GRN
    document.querySelectorAll('.cancel-grn-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const number = this.dataset.number;
            Swal.fire({
                title: 'Cancel GRN?',
                text: 'This action cannot be undone. GRN: ' + number,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                confirmButtonText: 'Yes, Cancel GRN!',
                cancelButtonText: 'No'
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch('cancel_grn.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ grn_id: id })
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({ icon: 'success', title: 'Cancelled!', text: data.message, timer: 2000, showConfirmButton: false })
                                .then(() => location.reload());
                        } else {
                            Swal.fire({ icon: 'error', title: 'Error', text: data.message || 'Failed to cancel.' });
                        }
                    })
                    .catch(() => Swal.fire({ icon: 'error', title: 'Error', text: 'An error occurred.' }));
                }
            });
        });
    });
    </script>
</body>
</html>
<?php $conn->close(); ?>
