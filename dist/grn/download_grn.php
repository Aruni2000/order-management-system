<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo '<div class="modal-error"><i class="fas fa-lock"></i><h4>Unauthorized</h4><p>Please log in to view GRN details.</p></div>';
    exit();
}
if (!isset($_SESSION['is_main_admin']) || $_SESSION['is_main_admin'] != 1) {
    http_response_code(403);
    echo '<div class="modal-error"><i class="fas fa-lock"></i><h4>Access Denied</h4><p>Only main admin can view GRN details.</p></div>';
    exit();
}

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

$grn_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($grn_id <= 0) {
    http_response_code(400);
    echo '<div class="modal-error"><i class="fas fa-exclamation-triangle"></i><h4>Invalid GRN ID</h4><p>No GRN ID provided.</p></div>';
    exit();
}

// Fetch GRN header with supplier + creator + tenant
$grn = null;
$grnStmt = $conn->prepare("SELECT g.*, s.name as supplier_name, s.contact_person, s.phone as supplier_phone,
    s.email as supplier_email, s.address as supplier_address, u.name as created_by_name, t.company_name as tenant_company_name
    FROM grn g
    LEFT JOIN suppliers s ON g.supplier_id = s.id
    LEFT JOIN users u ON g.created_by = u.id
    LEFT JOIN tenants t ON g.tenant_id = t.tenant_id
    WHERE g.grn_id = ?");
$grnStmt->bind_param("i", $grn_id);
$grnStmt->execute();
$grnResult = $grnStmt->get_result();
if ($grnResult && $grnResult->num_rows > 0) {
    $grn = $grnResult->fetch_assoc();
}
$grnStmt->close();

if (!$grn) {
    http_response_code(404);
    echo '<div class="modal-error"><i class="fas fa-search"></i><h4>GRN Not Found</h4><p>GRN ID ' . $grn_id . ' does not exist.</p></div>';
    exit();
}

// Fetch GRN items with product info
$items = [];
$itemsStmt = $conn->prepare("SELECT gi.*, p.name as product_name, p.product_code,
    b.remaining_qty as batch_remaining_qty
    FROM grn_items gi
    LEFT JOIN products p ON gi.product_id = p.id
    LEFT JOIN batches b ON b.grn_item_id = gi.id
    WHERE gi.grn_id = ?
    ORDER BY gi.id ASC");
$itemsStmt->bind_param("i", $grn_id);
$itemsStmt->execute();
$itemsResult = $itemsStmt->get_result();
if ($itemsResult) {
    while ($row = $itemsResult->fetch_assoc()) {
        $items[] = $row;
    }
}
$itemsStmt->close();
$conn->close();
?>

<div class="order-details-layout">
    <!-- GRN Info Section -->
    <div class="order-section">
        <h4><i class="fas fa-file-invoice" style="margin-right: 8px; color: #1565C0;"></i> GRN Information</h4>
        <div class="order-field">
            <span class="order-field-label">GRN Number</span>
            <span class="order-field-value"><strong><?= htmlspecialchars($grn['grn_number']) ?></strong></span>
        </div>
        <div class="order-field">
            <span class="order-field-label">Tenant Company</span>
            <span class="order-field-value">
                <?= htmlspecialchars($grn['tenant_company_name'] ?? 'N/A') ?>
            </span>
        </div>
        <div class="order-field">
            <span class="order-field-label">Status</span>
            <span class="order-field-value">
                <?php if ($grn['status'] == 'draft'): ?>
                    <span class="status-badge status-pending">Draft</span>
                <?php elseif ($grn['status'] == 'confirmed'): ?>
                    <span class="status-badge status-completed">Confirmed</span>
                <?php else: ?>
                    <span class="status-badge status-cancelled">Cancelled</span>
                <?php endif; ?>
            </span>
        </div>
        <div class="order-field">
            <span class="order-field-label">Received Date</span>
            <span class="order-field-value"><?= date('F d, Y', strtotime($grn['received_date'])) ?></span>
        </div>
        <div class="order-field">
            <span class="order-field-label">Created By</span>
            <span class="order-field-value"><?= htmlspecialchars($grn['created_by_name'] ?? 'System') ?></span>
        </div>
        <div class="order-field">
            <span class="order-field-label">Created At</span>
            <span class="order-field-value"><?= date('Y-m-d H:i', strtotime($grn['created_at'])) ?></span>
        </div>
        <div class="order-field">
            <span class="order-field-label">Grand Total</span>
            <span class="order-field-value" style="color: #059669; font-weight: 700; font-size: 1.1rem;">
                Rs. <?= number_format($grn['total_amount'], 2) ?>
            </span>
        </div>
    </div>

    <!-- Supplier Info Section -->
    <div class="order-section">
        <h4><i class="fas fa-truck" style="margin-right: 8px; color: #1565C0;"></i> Supplier Information</h4>
        <div class="order-field">
            <span class="order-field-label">Supplier Name</span>
            <span class="order-field-value"><strong><?= htmlspecialchars($grn['supplier_name'] ?? 'Unknown') ?></strong></span>
        </div>
        <div class="order-field">
            <span class="order-field-label">Contact Person</span>
            <span class="order-field-value"><?= htmlspecialchars($grn['contact_person'] ?? '-') ?></span>
        </div>
        <div class="order-field">
            <span class="order-field-label">Phone</span>
            <span class="order-field-value"><?= htmlspecialchars($grn['supplier_phone'] ?? '-') ?></span>
        </div>
        <div class="order-field">
            <span class="order-field-label">Email</span>
            <span class="order-field-value"><?= htmlspecialchars($grn['supplier_email'] ?? '-') ?></span>
        </div>
        <div class="order-field">
            <span class="order-field-label">Address</span>
            <span class="order-field-value"><?= htmlspecialchars($grn['supplier_address'] ?? '-') ?></span>
        </div>
        <?php if (!empty($grn['notes'])): ?>
        <div class="order-field">
            <span class="order-field-label">Notes</span>
            <span class="order-field-value"><?= nl2br(htmlspecialchars($grn['notes'])) ?></span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Items Table Section -->
    <div class="order-section-full">
        <h4><i class="fas fa-boxes" style="margin-right: 8px; color: #1565C0;"></i> Received Items</h4>
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 5%;">#</th>
                    <th>Product Name</th>
                    <th>Code</th>
                    <th>Batch No.</th>
                    <th style="text-align: center;">Qty</th>
                    <th style="text-align: right;">Buying Price</th>
                    <th style="text-align: right;">Selling Price</th>
                    <th style="text-align: right;">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($items)): ?>
                    <?php $idx = 1; foreach ($items as $item): ?>
                        <?php $recQty = intval($item['quantity']); ?>
                        <tr>
                            <td><?= $idx++ ?></td>
                            <td><strong><?= htmlspecialchars($item['product_name'] ?? 'Unknown') ?></strong></td>
                            <td><code style="font-size: 12px;"><?= htmlspecialchars($item['product_code'] ?? '-') ?></code></td>
                            <td><span style="font-family: monospace; font-weight: 600;"><?= htmlspecialchars($item['batch_number'] ?? '-') ?></span></td>
                            <td style="text-align: center;"><strong><?= number_format($recQty) ?></strong></td>
                            <td style="text-align: right;">Rs. <?= number_format($item['buying_price'], 2) ?></td>
                            <td style="text-align: right;">Rs. <?= number_format($item['selling_price'], 2) ?></td>
                            <td style="text-align: right;"><strong>Rs. <?= number_format($recQty * $item['buying_price'], 2) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 24px; color: #64748b;">No stock items in this GRN</td>
                    </tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background: #f1f5f9; font-weight: 700;">
                    <td colspan="4" style="text-align: right;">Total Items: <?= count($items) ?> | Total Qty: <?= number_format(array_sum(array_column($items, 'quantity'))) ?></td>
                    <td colspan="4" style="text-align: right; color: #059669;">Grand Total: Rs. <?= number_format($grn['total_amount'], 2) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
