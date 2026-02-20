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

// Check if user has admin role (role_id = 1)
if (!isset($_SESSION['user_id'])) {
    header("Location: /OMS/dist/pages/login.php");
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
    // User not found or inactive
    session_destroy();
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

$user_role = $role_result->fetch_assoc();

// Check if user is admin (role_id = 1)
if ($user_role['role_id'] != 1) {
    // User is not admin, redirect to dashboard
    header("Location: /OMS/dist/dashboard/index.php");
    exit();
}

// If we reach here, user is admin - continue with the original functionality
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');

// Handle search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$company_name_filter = isset($_GET['company_name_filter']) ? trim($_GET['company_name_filter']) : '';
$email_filter = isset($_GET['email_filter']) ? trim($_GET['email_filter']) : '';
$phone_filter = isset($_GET['phone_filter']) ? trim($_GET['phone_filter']) : '';
$contact_person_filter = isset($_GET['contact_person_filter']) ? trim($_GET['contact_person_filter']) : '';
$status_filter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : '';
$is_main_admin_filter = isset($_GET['is_main_admin_filter']) ? trim($_GET['is_main_admin_filter']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

// Pagination settings
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Base SQL for counting total records
$countSql = "SELECT COUNT(*) as total FROM tenants";

// Main query
$sql = "SELECT tenant_id, company_name, contact_person, email, phone, status, is_main_admin, 
               created_at, updated_at 
        FROM tenants";

// Build search conditions
$searchConditions = [];

// General search condition
if (!empty($search)) {
    $searchTerm = $conn->real_escape_string($search);
    $searchConditions[] = "(
                        company_name LIKE '%$searchTerm%' OR 
                        contact_person LIKE '%$searchTerm%' OR 
                        email LIKE '%$searchTerm%' OR 
                        phone LIKE '%$searchTerm%')";
}

// Specific Company Name filter
if (!empty($company_name_filter)) {
    $companyTerm = $conn->real_escape_string($company_name_filter);
    $searchConditions[] = "company_name LIKE '%$companyTerm%'";
}

// Specific Email filter
if (!empty($email_filter)) {
    $emailTerm = $conn->real_escape_string($email_filter);
    $searchConditions[] = "email LIKE '%$emailTerm%'";
}

// Specific Phone filter
if (!empty($phone_filter)) {
    $phoneTerm = $conn->real_escape_string($phone_filter);
    $searchConditions[] = "phone LIKE '%$phoneTerm%'";
}

// Specific Contact Person filter
if (!empty($contact_person_filter)) {
    $contactTerm = $conn->real_escape_string($contact_person_filter);
    $searchConditions[] = "contact_person LIKE '%$contactTerm%'";
}

// Status filter
if (!empty($status_filter)) {
    $statusTerm = $conn->real_escape_string($status_filter);
    $searchConditions[] = "status = '$statusTerm'";
}

// Main Admin filter
if ($is_main_admin_filter !== '') {
    $mainAdminTerm = $conn->real_escape_string($is_main_admin_filter);
    $searchConditions[] = "is_main_admin = '$mainAdminTerm'";
}

// Date range filter
if (!empty($date_from)) {
    $dateFromTerm = $conn->real_escape_string($date_from);
    $searchConditions[] = "DATE(created_at) >= '$dateFromTerm'";
}

if (!empty($date_to)) {
    $dateToTerm = $conn->real_escape_string($date_to);
    $searchConditions[] = "DATE(created_at) <= '$dateToTerm'";
}

// Apply all search conditions
if (!empty($searchConditions)) {
    $finalSearchCondition = " WHERE " . implode(' AND ', $searchConditions);
    $countSql = "SELECT COUNT(*) as total FROM tenants" . $finalSearchCondition;
    $sql .= $finalSearchCondition;
}

// Add ordering and pagination
$sql .= " ORDER BY created_at DESC LIMIT $limit OFFSET $offset";

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
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Order Management Admin Portal - Tenant Management</title>
    
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/orders.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/customers.css" id="main-style-link" />
</head>

<body>
    <!-- Page Loader -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/loader.php'); ?>

    <div class="pc-container">
        <div class="pc-content">
            
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">Tenant Management</h5>
                        <small class="text-muted">Administrator Access</small>
                    </div>
                </div>
            </div>

            <div class="main-content-wrapper">
                
                <!-- Tenant Filter Section -->
                <div class="tracking-container">
                    <form class="tracking-form" method="GET" action="">
                        <div class="form-group">
                            <label for="company_name_filter">Company Name</label>
                            <input type="text" id="company_name_filter" name="company_name_filter" 
                                   placeholder="Enter company name" 
                                   value="<?php echo htmlspecialchars($company_name_filter); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="contact_person_filter">Contact Person</label>
                            <input type="text" id="contact_person_filter" name="contact_person_filter" 
                                   placeholder="Enter contact person" 
                                   value="<?php echo htmlspecialchars($contact_person_filter); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="email_filter">Email</label>
                            <input type="text" id="email_filter" name="email_filter" 
                                   placeholder="Enter email" 
                                   value="<?php echo htmlspecialchars($email_filter); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="phone_filter">Phone</label>
                            <input type="text" id="phone_filter" name="phone_filter" 
                                   placeholder="Enter phone number" 
                                   value="<?php echo htmlspecialchars($phone_filter); ?>">
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
                            <label for="is_main_admin_filter">Main Admin</label>
                            <select id="is_main_admin_filter" name="is_main_admin_filter">
                                <option value="">All</option>
                                <option value="1" <?php echo ($is_main_admin_filter == '1') ? 'selected' : ''; ?>>Yes</option>
                                <option value="0" <?php echo ($is_main_admin_filter == '0') ? 'selected' : ''; ?>>No</option>
                            </select>
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

                <!-- Tenant Count Display -->
                <div class="order-count-container">
                    <div class="order-count-number"><?php echo number_format($totalRows); ?></div>
                    <div class="order-count-dash">-</div>
                    <div class="order-count-subtitle">Total Tenants</div>
                </div>

                <!-- Tenants Table -->
                <div class="table-wrapper">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>Company Info</th>
                                <th>Contact Details</th>
                                <th>Status & Type</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="tenantsTableBody">
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <!-- Company Info -->
                                        <td class="customer-name">
                                            <div class="customer-info">
                                                <h6 style="margin: 0; font-size: 14px; font-weight: 600;"><?php echo htmlspecialchars($row['company_name']); ?></h6>
                                                <small style="color: #6c757d; font-size: 12px;">ID: <?php echo htmlspecialchars($row['tenant_id']); ?></small>
                                            </div>
                                        </td>
                                        
                                        <!-- Contact Details -->
                                        <td>
                                            <div style="line-height: 1.4;">
                                                <div style="font-weight: 500; margin-bottom: 2px;"><?php echo htmlspecialchars($row['contact_person']); ?></div>
                                                <div style="font-size: 12px; color: #6c757d; margin-bottom: 2px;"><?php echo htmlspecialchars($row['email']); ?></div>
                                                <div style="font-size: 11px; color: #007bff; font-weight: 500;"><?php echo htmlspecialchars($row['phone']); ?></div>
                                            </div>
                                        </td>
                                        
                                        <!-- Status & Type -->
                                        <td>
                                            <div style="line-height: 1.4;">
                                                <?php if ($row['status'] === 'active'): ?>
                                                    <span class="status-badge pay-status-paid">Active</span>
                                                <?php else: ?>
                                                    <span class="status-badge pay-status-unpaid">Inactive</span>
                                                <?php endif; ?>
                                                
                                                <?php if ($row['is_main_admin'] == 1): ?>
                                                    <span class="status-badge" style="background: #ffc107; color: #000; margin-top: 4px; display: inline-block;">
                                                        <i class="fas fa-crown"></i> Main Admin
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        
                                        <!-- Created -->
                                        <td>
                                            <div style="font-size: 12px; line-height: 1.4;">
                                                <div style="font-weight: 500;"><?php echo date('M d, Y', strtotime($row['created_at'])); ?></div>
                                                <div style="color: #6c757d;"><?php echo date('h:i A', strtotime($row['created_at'])); ?></div>
                                            </div>
                                        </td>
                                        
                                        <!-- Action Buttons -->
                                        <td class="actions">
                                            <div class="action-buttons-group">
                                                <button type="button" class="action-btn view-btn view-tenant-btn"
                                                        data-tenant-id="<?= $row['tenant_id'] ?>"
                                                        data-company-name="<?= htmlspecialchars($row['company_name']) ?>"
                                                        data-contact-person="<?= htmlspecialchars($row['contact_person']) ?>"
                                                        data-email="<?= htmlspecialchars($row['email']) ?>"
                                                        data-phone="<?= htmlspecialchars($row['phone']) ?>"
                                                        data-status="<?= htmlspecialchars($row['status']) ?>"
                                                        data-is-main-admin="<?= $row['is_main_admin'] ?>"
                                                        data-created="<?= htmlspecialchars($row['created_at']) ?>"
                                                        data-updated="<?= htmlspecialchars($row['updated_at']) ?>"
                                                        title="View Tenant Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                
                                                <button class="action-btn dispatch-btn" title="Edit Tenant" 
                                                        onclick="editTenant(<?php echo $row['tenant_id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                          
                                                <!-- Status Toggle Button -->
                                                <button type="button" class="action-btn <?= $row['status'] == 'active' ? 'deactivate-btn' : 'activate-btn' ?> toggle-status-btn"
                                                        data-tenant-id="<?= $row['tenant_id'] ?>"
                                                        data-current-status="<?= $row['status'] ?>"
                                                        data-company-name="<?= htmlspecialchars($row['company_name']) ?>"
                                                        title="<?= $row['status'] == 'active' ? 'Deactivate Tenant' : 'Activate Tenant' ?>"
                                                        data-action="<?= $row['status'] == 'active' ? 'deactivate' : 'activate' ?>">
                                                    <i class="fas <?= $row['status'] == 'active' ? 'fa-ban' : 'fa-check-circle' ?>"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center" style="padding: 40px; text-align: center; color: #666;">
                                        <i class="fas fa-building" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                                        No tenants found
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
                            <button class="page-btn" onclick="window.location.href='?page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>&company_name_filter=<?php echo urlencode($company_name_filter); ?>&contact_person_filter=<?php echo urlencode($contact_person_filter); ?>&email_filter=<?php echo urlencode($email_filter); ?>&phone_filter=<?php echo urlencode($phone_filter); ?>&status_filter=<?php echo urlencode($status_filter); ?>&is_main_admin_filter=<?php echo urlencode($is_main_admin_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>'">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <button class="page-btn <?php echo ($i == $page) ? 'active' : ''; ?>" 
                                    onclick="window.location.href='?page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&company_name_filter=<?php echo urlencode($company_name_filter); ?>&contact_person_filter=<?php echo urlencode($contact_person_filter); ?>&email_filter=<?php echo urlencode($email_filter); ?>&phone_filter=<?php echo urlencode($phone_filter); ?>&status_filter=<?php echo urlencode($status_filter); ?>&is_main_admin_filter=<?php echo urlencode($is_main_admin_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>'">
                                <?php echo $i; ?>
                            </button>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <button class="page-btn" onclick="window.location.href='?page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>&company_name_filter=<?php echo urlencode($company_name_filter); ?>&contact_person_filter=<?php echo urlencode($contact_person_filter); ?>&email_filter=<?php echo urlencode($email_filter); ?>&phone_filter=<?php echo urlencode($phone_filter); ?>&status_filter=<?php echo urlencode($status_filter); ?>&is_main_admin_filter=<?php echo urlencode($is_main_admin_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>'">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tenant Details Modal -->
    <div id="tenantDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h4>Tenant Details</h4>
                <span class="close" onclick="closeTenantModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="customer-detail-row">
                    <span class="detail-label">Tenant ID:</span>
                    <span class="detail-value" id="modal-tenant-id"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Company Name:</span>
                    <span class="detail-value" id="modal-company-name"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Contact Person:</span>
                    <span class="detail-value" id="modal-contact-person"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Email:</span>
                    <span class="detail-value" id="modal-email"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Phone:</span>
                    <span class="detail-value" id="modal-phone"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Status:</span>
                    <span class="detail-value">
                        <span id="modal-status" class="status-badge"></span>
                    </span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Main Admin:</span>
                    <span class="detail-value" id="modal-is-main-admin"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Created:</span>
                    <span class="detail-value" id="modal-created"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Last Updated:</span>
                    <span class="detail-value" id="modal-updated"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Status Confirmation Modal -->
    <div id="statusConfirmationModal" class="modal confirmation-modal">
        <div class="modal-content confirmation-modal-content">
            <div class="modal-header">
                <h4>Are you sure?</h4>
                <span class="close" onclick="closeConfirmationModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="confirmation-icon">
                    <i class="ti ti-alert-triangle"></i>
                </div>
                <div class="confirmation-text">
                    You are about to <span class="action-highlight" id="action-text"></span> tenant:
                </div>
                <div class="confirmation-text">
                    <span class="user-name-highlight" id="confirm-company-name"></span>
                </div>
                <div class="modal-buttons">
                    <button class="btn-confirm" id="confirmActionBtn">
                        <span id="confirm-button-text">Yes, deactivate tenant!</span>
                    </button>
                    <button class="btn-cancel" onclick="closeConfirmationModal()">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php'); ?>

    <!-- Scripts -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php'); ?>

   <script>
// ===============================
// TENANT MANAGEMENT SCRIPT
// ===============================

function clearFilters() {
    window.location.href = 'tenant_list.php';
}

// ---------- TENANT VIEW MODAL ----------
function openTenantModal(button) {
    const modal = document.getElementById('tenantDetailsModal');

    document.getElementById('modal-tenant-id').textContent = button.dataset.tenantId;
    document.getElementById('modal-company-name').textContent = button.dataset.companyName;
    document.getElementById('modal-contact-person').textContent = button.dataset.contactPerson;
    document.getElementById('modal-email').textContent = button.dataset.email;
    document.getElementById('modal-phone').textContent = button.dataset.phone;

    const statusBadge = document.getElementById('modal-status');
    statusBadge.textContent = button.dataset.status === 'active' ? 'Active' : 'Inactive';
    statusBadge.className = button.dataset.status === 'active'
        ? 'status-badge pay-status-paid'
        : 'status-badge pay-status-unpaid';

    document.getElementById('modal-is-main-admin').textContent =
        button.dataset.isMainAdmin == 1 ? 'Yes' : 'No';

    document.getElementById('modal-created').textContent = formatDateTime(button.dataset.created);
    document.getElementById('modal-updated').textContent = formatDateTime(button.dataset.updated);

    modal.style.display = 'block';
}

function closeTenantModal() {
    document.getElementById('tenantDetailsModal').style.display = 'none';
}

// ---------- STATUS CONFIRMATION ----------
function openStatusConfirmation(button) {
    const tenantId = button.dataset.tenantId;
    const companyName = button.dataset.companyName;
    const currentStatus = button.dataset.currentStatus;

    const newStatus = currentStatus === 'active' ? 'inactive' : 'active';

    document.getElementById('action-text').textContent =
        newStatus === 'inactive' ? 'deactivate' : 'activate';

    document.getElementById('confirm-company-name').textContent = companyName;
    document.getElementById('confirm-button-text').textContent =
        newStatus === 'inactive' ? 'Yes, deactivate tenant!' : 'Yes, activate tenant!';

    document.getElementById('confirmActionBtn').onclick = function () {
        toggleTenantStatus(tenantId, newStatus);
    };

    document.getElementById('statusConfirmationModal').style.display = 'block';
}

function closeConfirmationModal() {
    document.getElementById('statusConfirmationModal').style.display = 'none';
}

// ---------- STATUS UPDATE ----------
function toggleTenantStatus(tenantId, newStatus) {
    fetch('toggle_tenant_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            tenant_id: tenantId,
            new_status: newStatus
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('Tenant status updated successfully');
            location.reload();
        } else {
            alert(data.message || 'Status update failed');
        }
    })
    .catch(() => alert('Server error occurred'));
}

// ---------- EDIT ----------
function editTenant(id) {
    window.location.href = `edit_tenant.php?id=${id}`;
}

// ---------- UTIL ----------
function formatDateTime(dateString) {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleString();
}

// ---------- EVENT BINDINGS ----------
document.addEventListener('DOMContentLoaded', () => {

    document.querySelectorAll('.view-tenant-btn').forEach(btn => {
        btn.addEventListener('click', () => openTenantModal(btn));
    });

    document.querySelectorAll('.toggle-status-btn').forEach(btn => {
        btn.addEventListener('click', () => openStatusConfirmation(btn));
    });

    window.onclick = e => {
        if (e.target.id === 'tenantDetailsModal') closeTenantModal();
        if (e.target.id === 'statusConfirmationModal') closeConfirmationModal();
    };

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            closeTenantModal();
            closeConfirmationModal();
        }
    });

});
</script>

</body>
</html>