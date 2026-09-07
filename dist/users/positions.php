<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) ob_end_clean();
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

if (!isset($_SESSION['user_id'])) {
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

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
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

$user_role = $role_result->fetch_assoc();

if ($user_role['role_id'] != 1) {
    header("Location: /OMS/dist/dashboard/index.php");
    exit();
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : '';

$countSql = "SELECT COUNT(*) as total FROM positions";
$sql = "SELECT * FROM positions";

$conditions = [];
if (!empty($search)) {
    $searchTerm = $conn->real_escape_string($search);
    $conditions[] = "(name LIKE '%$searchTerm%' OR description LIKE '%$searchTerm%')";
}
if (!empty($status_filter)) {
    $statusTerm = $conn->real_escape_string($status_filter);
    $conditions[] = "status = '$statusTerm'";
}
if (!empty($conditions)) {
    $condStr = " WHERE " . implode(' AND ', $conditions);
    $countSql .= $condStr;
    $sql .= $condStr;
}

$sql .= " ORDER BY id ASC";

$countResult = $conn->query($countSql);
$totalPositions = 0;
if ($countResult && $countResult->num_rows > 0) {
    $totalPositions = $countResult->fetch_assoc()['total'];
}
$result = $conn->query($sql);

function getStatusBadge($status) {
    if ($status === 'active') {
        return '<span class="status-badge pay-status-paid">Active</span>';
    }
    return '<span class="status-badge pay-status-unpaid">Inactive</span>';
}
?>
<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">
<head>
    <title>Position Management | <?= htmlspecialchars($_SESSION['company_name'] ?? '') ?></title>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>
    <link rel="stylesheet" href="../assets/css/orders.css" />
    <link rel="stylesheet" href="../assets/css/customers.css" />
    <link rel="stylesheet" href="../assets/css/message.css" />
    <style>
        .position-name { font-weight: 600; color: #495057; }
        .action-buttons-group { display: flex; gap: 6px; }
        .modal { display: none; }
        .modal.show { display: block; }
        .form-control-static { padding: 8px 12px; background: #f8f9fa; border-radius: 6px; min-height: 38px; }
    </style>
</head>
<body>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/loader.php');
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php'); ?>

    <div class="pc-container">
        <div class="pc-content">
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">Position Management</h5>
                    </div>
                </div>
            </div>

            <div class="main-content-wrapper">
                <div class="tracking-container">
                    <form class="tracking-form" method="GET" action="">
                        <div class="form-group">
                            <label for="search">Search Positions</label>
                            <input type="text" id="search" name="search"
                                   placeholder="Search by name or description"
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="form-group">
                            <label for="status_filter">Status</label>
                            <select id="status_filter" name="status_filter">
                                <option value="">All Status</option>
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <div class="button-group">
                                <button type="submit" class="search-btn">
                                    <i class="fas fa-search"></i> Search
                                </button>
                                <button type="button" class="search-btn" onclick="window.location.href='positions.php'" style="background: #6c757d;">
                                    <i class="fas fa-times"></i> Clear
                                </button>
                                <button type="button" class="search-btn" onclick="openAddModal()" style="background: #28a745;">
                                    <i class="fas fa-plus"></i> Add Position
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="order-count-container">
                    <div class="order-count-number"><?php echo number_format($totalPositions); ?></div>
                    <div class="order-count-dash">-</div>
                    <div class="order-count-subtitle">Total Positions</div>
                </div>

                <div class="table-wrapper">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>Position Name</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Users Count</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()):
                                    $countStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM users WHERE position_id = ?");
                                    $countStmt->bind_param("i", $row['id']);
                                    $countStmt->execute();
                                    $userCount = $countStmt->get_result()->fetch_assoc()['cnt'];
                                    $countStmt->close();
                                ?>
                                    <tr>
                                        <td class="customer-name">
                                            <div class="customer-info">
                                                <h6 style="margin: 0; font-size: 14px; font-weight: 600;" class="position-name"><?php echo htmlspecialchars($row['name']); ?></h6>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="color: #6c757d; font-size: 13px;">
                                                <?php echo htmlspecialchars($row['description'] ?? '—'); ?>
                                            </div>
                                        </td>
                                        <td><?php echo getStatusBadge($row['status'] ?? 'active'); ?></td>
                                        <td>
                                            <span class="badge badge-user-count">
                                                <?php echo $userCount; ?> user<?php echo $userCount != 1 ? 's' : ''; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div style="font-size: 12px; line-height: 1.4;">
                                                <div style="font-weight: 500;"><?php echo date('M d, Y', strtotime($row['created_at'])); ?></div>
                                                <div style="color: #6c757d;"><?php echo date('h:i:s A', strtotime($row['created_at'])); ?></div>
                                            </div>
                                        </td>
                                        <td class="actions">
                                            <div class="action-buttons-group">
                                                <button type="button" class="action-btn dispatch-btn"
                                                        onclick="openEditModal(<?php echo $row['id']; ?>)"
                                                        title="Edit Position">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button"
                                                        class="action-btn <?php echo ($row['status'] ?? 'active') === 'active' ? 'deactivate-btn' : 'activate-btn'; ?> toggle-status-btn"
                                                        data-position-id="<?php echo $row['id']; ?>"
                                                        data-position-name="<?php echo htmlspecialchars($row['name']); ?>"
                                                        data-current-status="<?php echo $row['status'] ?? 'active'; ?>"
                                                        title="<?php echo ($row['status'] ?? 'active') === 'active' ? 'Deactivate Position' : 'Activate Position'; ?>">
                                                    <i class="fas <?php echo ($row['status'] ?? 'active') === 'active' ? 'fa-toggle-off' : 'fa-toggle-on'; ?>"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center" style="padding: 40px; text-align: center; color: #666;">
                                        <i class="fas fa-briefcase" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                                        No positions found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Position Modal -->
    <div id="addPositionModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h4>Add New Position</h4>
                <span class="close" onclick="closeModal('addPositionModal')">&times;</span>
            </div>
            <div class="modal-body">
                <form id="addPositionForm">
                    <div class="customer-form-group" style="margin-bottom: 16px;">
                        <label for="add_name" class="form-label">
                            <i class="fas fa-briefcase"></i> Position Name<span class="required">*</span>
                        </label>
                        <input type="text" class="form-control" id="add_name" name="name"
                               placeholder="e.g. Senior Manager" required>
                        <div class="error-feedback" id="add_name-error"></div>
                    </div>
                    <div class="customer-form-group" style="margin-bottom: 16px;">
                        <label for="add_description" class="form-label">
                            <i class="fas fa-align-left"></i> Description
                        </label>
                        <textarea class="form-control" id="add_description" name="description" rows="3"
                                  placeholder="Brief description of this position"></textarea>
                        <div class="error-feedback" id="add_description-error"></div>
                    </div>
                    <div class="modal-buttons">
                        <button type="submit" class="btn-confirm" id="addSubmitBtn">
                            <i class="fas fa-save"></i> Save Position
                        </button>
                        <button type="button" class="btn-cancel" onclick="closeModal('addPositionModal')">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Position Modal -->
    <div id="editPositionModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h4>Edit Position</h4>
                <span class="close" onclick="closeModal('editPositionModal')">&times;</span>
            </div>
            <div class="modal-body">
                <form id="editPositionForm">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="customer-form-group" style="margin-bottom: 16px;">
                        <label for="edit_name" class="form-label">
                            <i class="fas fa-briefcase"></i> Position Name<span class="required">*</span>
                        </label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                        <div class="error-feedback" id="edit_name-error"></div>
                    </div>
                    <div class="customer-form-group" style="margin-bottom: 16px;">
                        <label for="edit_description" class="form-label">
                            <i class="fas fa-align-left"></i> Description
                        </label>
                        <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                        <div class="error-feedback" id="edit_description-error"></div>
                    </div>
                    <div class="modal-buttons">
                        <button type="submit" class="btn-confirm" id="editSubmitBtn">
                            <i class="fas fa-save"></i> Update Position
                        </button>
                        <button type="button" class="btn-cancel" onclick="closeModal('editPositionModal')">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>



    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php'); ?>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php'); ?>

    <script>
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }



        function openAddModal() {
            document.getElementById('addPositionForm').reset();
            document.getElementById('addPositionModal').style.display = 'block';
        }

        function openEditModal(id) {
            document.getElementById('edit_id').value = id;
            document.getElementById('editSubmitBtn').disabled = true;
            document.getElementById('editSubmitBtn').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';

            fetch('save_position.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'get', id: id })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('edit_name').value = data.data.name;
                    document.getElementById('edit_description').value = data.data.description || '';
                    document.getElementById('editPositionModal').style.display = 'block';
                } else {
                    toastManager.error(data.message);
                }
                document.getElementById('editSubmitBtn').disabled = false;
                document.getElementById('editSubmitBtn').innerHTML = '<i class="fas fa-save"></i> Update Position';
            })
            .catch(() => {
                toastManager.error('Error loading position data');
                document.getElementById('editSubmitBtn').disabled = false;
                document.getElementById('editSubmitBtn').innerHTML = '<i class="fas fa-save"></i> Update Position';
            });
        }

        function openConfirmationModal(button) {
            const positionId = button.getAttribute('data-position-id');
            const positionName = button.getAttribute('data-position-name');
            const currentStatus = button.getAttribute('data-current-status');

            const isActive = currentStatus.toLowerCase() === 'active';
            const actionText = isActive ? 'deactivate' : 'activate';

            Swal.fire({
                title: 'Are you sure?',
                text: `You are about to ${actionText} position: ${positionName}`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: isActive ? '#dc3545' : '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: isActive ? 'Yes, deactivate it!' : 'Yes, activate it!',
                cancelButtonText: 'Cancel',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    togglePositionStatus(positionId, isActive ? 'inactive' : 'active');
                }
            });
        }

        function togglePositionStatus(positionId, newStatus) {
            fetch('save_position.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'toggle_status', id: positionId, status: newStatus })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        title: 'Updated!',
                        text: data.message,
                        icon: 'success',
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => { location.reload(); });
                } else {
                    toastManager.error(data.message);
                }
            })
            .catch(() => {
                toastManager.error('Error updating position status');
            });
        }

        document.getElementById('addPositionForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = document.getElementById('addSubmitBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            const formData = new FormData(this);
            formData.append('action', 'add');

            fetch('save_position.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    toastManager.success(data.message);
                    closeModal('addPositionModal');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    toastManager.error(data.message);
                    if (data.errors) {
                        Object.keys(data.errors).forEach(f => {
                            const el = document.getElementById('add_' + f + '-error');
                            if (el) { el.textContent = data.errors[f]; el.style.display = 'block'; }
                        });
                    }
                }
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-save"></i> Save Position';
            })
            .catch(() => {
                toastManager.error('Error saving position');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-save"></i> Save Position';
            });
        });

        document.getElementById('editPositionForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = document.getElementById('editSubmitBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';

            const formData = new FormData(this);
            formData.append('action', 'edit');

            fetch('save_position.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    toastManager.success(data.message);
                    closeModal('editPositionModal');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    toastManager.error(data.message);
                    if (data.errors) {
                        Object.keys(data.errors).forEach(f => {
                            const el = document.getElementById('edit_' + f + '-error');
                            if (el) { el.textContent = data.errors[f]; el.style.display = 'block'; }
                        });
                    }
                }
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-save"></i> Update Position';
            })
            .catch(() => {
                toastManager.error('Error updating position');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-save"></i> Update Position';
            });
        });

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.toggle-status-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    openConfirmationModal(this);
                });
            });
        });        window.onclick = function(event) {
            const modals = ['addPositionModal', 'editPositionModal'];
            modals.forEach(id => {
                const m = document.getElementById(id);
                if (event.target === m) {
                    closeModal(id);
                }
            });
        };
        
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                ['addPositionModal', 'editPositionModal'].forEach(id => closeModal(id));
            }
        });
    </script>
</body>
</html>
