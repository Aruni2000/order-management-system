<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) ob_end_clean();
    header("Location: /orderhub_nextwave/dist/pages/login.php");
    exit();
}
// Purchasing Management (Suppliers) is accessible only to Main Admin Tenant (Admin & Store roles)
if ((int)($_SESSION['is_main_admin'] ?? 0) !== 1 || !in_array((int)($_SESSION['role_id'] ?? 0), [1, 3], true)) {
    if (ob_get_level()) ob_end_clean();
    header("Location: /orderhub_nextwave/dist/pages/access_denied.php");
    exit();
}

include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/connection/db_connection.php');

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$countSql = "SELECT COUNT(*) as total FROM suppliers WHERE 1=1";
$sql = "SELECT * FROM suppliers WHERE 1=1";

$searchConditions = [];
if (!empty($search)) {
    $searchTerm = $conn->real_escape_string($search);
    $searchConditions[] = "(name LIKE '%$searchTerm%' OR contact_person LIKE '%$searchTerm%' OR phone LIKE '%$searchTerm%' OR email LIKE '%$searchTerm%')";
}
if (!empty($status_filter)) {
    $statusTerm = $conn->real_escape_string($status_filter);
    $searchConditions[] = "status = '$statusTerm'";
}

if (!empty($searchConditions)) {
    $where = " WHERE " . implode(' AND ', $searchConditions);
    $countSql .= $where;
    $sql .= $where;
}

$countResult = $conn->query($countSql);
$totalRows = 0;
if ($countResult && $countResult->num_rows > 0) {
    $totalRows = $countResult->fetch_assoc()['total'];
}
$totalPages = ceil($totalRows / $limit);
$sql .= " ORDER BY id DESC LIMIT $limit OFFSET $offset";
$result = $conn->query($sql);
?>
<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">
<head>
    <title>Supplier Management | <?= htmlspecialchars($_SESSION['company_name'] ?? 'orderhub_nextwave') ?></title>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/include/head.php'); ?>
    <link rel="stylesheet" href="../assets/css/orders.css" />
    <link rel="stylesheet" href="../assets/css/customers.css" />
</head>
<body>
    <?php
    include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/include/loader.php');
    include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/include/navbar.php');
    include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/include/sidebar.php');
    ?>

    <div class="pc-container">
        <div class="pc-content">
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">Supplier Management</h5>
                    </div>
                </div>
            </div>

            <div class="main-content-wrapper">
                <!-- Supplier Filter Section -->
                <div class="tracking-container">
                    <form class="tracking-form" method="GET" action="">
                        <div class="form-group">
                            <label for="search">Search</label>
                            <input type="text" id="search" name="search" placeholder="Name, phone, email..."
                                value="<?php echo htmlspecialchars($search); ?>">
                        </div>

                        <div class="form-group">
                            <label for="status_filter">Status</label>
                            <select id="status_filter" name="status_filter">
                                <option value="">All Status</option>
                                <option value="active" <?php echo ($status_filter == 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo ($status_filter == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <div class="button-group">
                                <button type="submit" class="search-btn">
                                    <i class="fas fa-search"></i> Search
                                </button>
                                <button type="button" class="search-btn" onclick="window.location.href='supplier_list.php'" style="background: #6c757d;">
                                    <i class="fas fa-times"></i> Clear
                                </button>
                                <a href="add_supplier.php" class="search-btn" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); text-decoration: none;">
                                    <i class="fas fa-plus"></i> Add Supplier
                                </a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Supplier Count Display -->
                <div class="order-count-container">
                    <div class="order-count-number"><?php echo number_format($totalRows); ?></div>
                    <div class="order-count-dash">-</div>
                    <div class="order-count-subtitle">Total Suppliers</div>
                </div>

                <!-- Suppliers Table -->
                <div class="table-wrapper">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Supplier Name</th>
                                <th>Contact Person</th>
                                <th>Phone & Email</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <td class="order-id"><?php echo htmlspecialchars($row['id']); ?></td>
                                        <td class="customer-name">
                                            <div class="customer-info">
                                                <h6 style="margin: 0; font-size: 14px; font-weight: 600;">
                                                    <?php echo htmlspecialchars($row['name']); ?>
                                                </h6>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($row['contact_person'] ?? '-'); ?></td>
                                        <td>
                                            <div style="line-height: 1.4;">
                                                <?php if (!empty($row['phone'])): ?>
                                                <div style="font-weight: 500; margin-bottom: 2px;">
                                                    <?php echo htmlspecialchars($row['phone']); ?>
                                                </div>
                                                <?php endif; ?>
                                                <?php if (!empty($row['email'])): ?>
                                                <div style="font-size: 12px; color: #6c757d; margin-bottom: 2px;">
                                                    <?php echo htmlspecialchars($row['email']); ?>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($row['status'] == 'active'): ?>
                                                <span class="status-badge pay-status-paid">Active</span>
                                            <?php else: ?>
                                                <span class="status-badge pay-status-unpaid">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons-group" style="justify-content: flex-start;">
                                                <button class="action-btn view-btn view-supplier-btn"
                                                    data-id="<?php echo $row['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($row['name']); ?>"
                                                    data-contact="<?php echo htmlspecialchars($row['contact_person'] ?? ''); ?>"
                                                    data-phone="<?php echo htmlspecialchars($row['phone'] ?? ''); ?>"
                                                    data-email="<?php echo htmlspecialchars($row['email'] ?? ''); ?>"
                                                    data-address="<?php echo htmlspecialchars($row['address'] ?? ''); ?>"
                                                    data-status="<?php echo $row['status']; ?>"
                                                    data-created="<?php echo htmlspecialchars($row['created_at'] ?? ''); ?>"
                                                    data-updated="<?php echo htmlspecialchars($row['updated_at'] ?? ''); ?>"
                                                    title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>

                                                <a href="edit_supplier.php?id=<?php echo $row['id']; ?>" class="action-btn dispatch-btn" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>

                                                <button class="action-btn <?php echo $row['status'] == 'active' ? 'unpaid-btn' : 'paid-btn'; ?> toggle-status-btn"
                                                    data-id="<?php echo $row['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($row['name']); ?>"
                                                    data-status="<?php echo $row['status']; ?>"
                                                    title="<?php echo $row['status'] == 'active' ? 'Deactivate' : 'Activate'; ?>">
                                                    <i class="fas <?php echo $row['status'] == 'active' ? 'fa-toggle-off' : 'fa-toggle-on'; ?>"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 40px; color: #666;">
                                        <i class="fas fa-truck" style="font-size: 2rem; display: block; margin-bottom: 10px;"></i>
                                        No suppliers found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div class="pagination">
                    <div class="pagination-info">
                        Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $totalRows); ?> of <?php echo $totalRows; ?> entries
                    </div>
                    <div class="pagination-controls">
                        <?php
                        $qp = $_GET; unset($qp['page']);
                        $qs = http_build_query($qp);
                        $base = '?' . ($qs ? $qs . '&' : '');
                        ?>
                        <?php if ($page > 1): ?>
                            <button class="page-btn" onclick="window.location.href='<?php echo $base; ?>page=<?php echo $page - 1; ?>'"><i class="fas fa-chevron-left"></i></button>
                        <?php endif; ?>
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <button class="page-btn <?php echo ($i == $page) ? 'active' : ''; ?>" onclick="window.location.href='<?php echo $base; ?>page=<?php echo $i; ?>'"><?php echo $i; ?></button>
                        <?php endfor; ?>
                        <?php if ($page < $totalPages): ?>
                            <button class="page-btn" onclick="window.location.href='<?php echo $base; ?>page=<?php echo $page + 1; ?>'"><i class="fas fa-chevron-right"></i></button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- View Supplier Modal -->
    <div id="viewSupplierModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h4>Supplier Details</h4>
                <span class="close" onclick="document.getElementById('viewSupplierModal').style.display='none'">&times;</span>
            </div>
            <div class="modal-body">
                <div class="customer-detail-row">
                    <span class="detail-label">ID:</span>
                    <span class="detail-value" id="m-id"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Name:</span>
                    <span class="detail-value" id="m-name"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Contact Person:</span>
                    <span class="detail-value" id="m-contact"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Phone:</span>
                    <span class="detail-value" id="m-phone"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Email:</span>
                    <span class="detail-value" id="m-email"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Address:</span>
                    <span class="detail-value" id="m-address"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Status:</span>
                    <span class="detail-value" id="m-status"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Created:</span>
                    <span class="detail-value" id="m-created"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Last Updated:</span>
                    <span class="detail-value" id="m-updated"></span>
                </div>
            </div>
        </div>
    </div>

    <?php include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/include/footer.php'); ?>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/include/scripts.php'); ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>

    <script>
    function formatDateTime(dateString) {
        if (!dateString) return '-';
        try {
            const date = new Date(dateString);
            if (isNaN(date.getTime())) return dateString;
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            const yyyy = date.getFullYear();
            const mmm = months[date.getMonth()];
            const dd = String(date.getDate()).padStart(2, '0');
            let hours = date.getHours();
            const minutes = String(date.getMinutes()).padStart(2, '0');
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12;
            hours = hours ? hours : 12;
            return `${mmm} ${dd}, ${yyyy} ${hours}:${minutes} ${ampm}`;
        } catch (e) {
            return dateString;
        }
    }

    // View supplier
    document.querySelectorAll('.view-supplier-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('m-id').textContent = this.dataset.id;
            document.getElementById('m-name').textContent = this.dataset.name;
            document.getElementById('m-contact').textContent = this.dataset.contact || '-';
            document.getElementById('m-phone').textContent = this.dataset.phone || '-';
            document.getElementById('m-email').textContent = this.dataset.email || '-';
            document.getElementById('m-address').textContent = this.dataset.address || '-';
            document.getElementById('m-created').textContent = formatDateTime(this.dataset.created);
            document.getElementById('m-updated').textContent = formatDateTime(this.dataset.updated);
            const statusEl = document.getElementById('m-status');
            statusEl.innerHTML = '<span class="status-badge ' + (this.dataset.status === 'active' ? 'status-active' : 'status-inactive') + '">' + this.dataset.status.charAt(0).toUpperCase() + this.dataset.status.slice(1) + '</span>';
            document.getElementById('viewSupplierModal').style.display = 'block';
        });
    });

    // Close modal on outside click
    window.onclick = function(e) {
        if (e.target === document.getElementById('viewSupplierModal')) {
            document.getElementById('viewSupplierModal').style.display = 'none';
        }
    }

    // Toggle status
    document.querySelectorAll('.toggle-status-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const name = this.dataset.name;
            const currentStatus = this.dataset.status;
            const newStatus = currentStatus === 'active' ? 'inactive' : 'active';
            const action = currentStatus === 'active' ? 'deactivate' : 'activate';

            Swal.fire({
                title: 'Are you sure?',
                text: 'You are about to ' + action + ' supplier: ' + name,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, ' + action + '!',
                cancelButtonText: 'Cancel',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch('toggle_supplier_status.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ supplier_id: id, new_status: newStatus })
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({ icon: 'success', title: 'Updated!', text: data.message, timer: 1500, showConfirmButton: false })
                                .then(() => location.reload());
                        } else {
                            Swal.fire({ icon: 'error', title: 'Error', text: data.message || 'Failed to update.' });
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