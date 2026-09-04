<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) ob_end_clean();
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
$csrf_token = generateCSRFToken();

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

$grn_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($grn_id <= 0) {
    header("Location: grn_list.php");
    exit();
}

// Fetch GRN header with supplier
$grn = null;
$grnStmt = $conn->prepare("SELECT g.*, s.name as supplier_name, s.contact_person, s.phone as supplier_phone, s.email as supplier_email, s.address as supplier_address,
    u.name as created_by_name
    FROM grn g
    LEFT JOIN suppliers s ON g.supplier_id = s.id
    LEFT JOIN users u ON g.created_by = u.id
    WHERE g.grn_id = ?");
$grnStmt->bind_param("i", $grn_id);
$grnStmt->execute();
$grnResult = $grnStmt->get_result();
if ($grnResult && $grnResult->num_rows > 0) {
    $grn = $grnResult->fetch_assoc();
}
$grnStmt->close();

if (!$grn) {
    header("Location: grn_list.php");
    exit();
}

// Fetch GRN items with product info and batch remaining stock
$items = [];
$itemsStmt = $conn->prepare("SELECT gi.*, p.name as product_name, p.product_code, p.stock_quantity as product_total_stock,
    b.remaining_qty as batch_remaining_qty, b.status as batch_status
    FROM grn_items gi
    LEFT JOIN products p ON gi.product_id = p.id
    LEFT JOIN batches b ON (b.grn_item_id = gi.id OR (b.grn_id = gi.grn_id AND b.batch_number = gi.batch_number))
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
?>
<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">
<head>
    <title>View GRN <?= htmlspecialchars($grn['grn_number']) ?> | <?= htmlspecialchars($_SESSION['company_name'] ?? 'OMS') ?></title>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>
    <link rel="stylesheet" href="../assets/css/styles.css" />
    <link rel="stylesheet" href="../assets/css/orders.css" />
    <link rel="stylesheet" href="../assets/css/customers.css" />
    <link rel="stylesheet" href="../assets/css/status-badge-colors.css" />
    <style>
        .detail-item-box {
            padding: 12px 16px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        .detail-item-label {
            font-size: 11px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 4px;
        }
        .detail-item-val {
            font-size: 14px;
            font-weight: 600;
            color: #1e293b;
        }
        .grn-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }
        @media print {
            .pc-sidebar, .pc-header, .submit-section, .btn, .page-header, .no-print { display: none !important; }
            .pc-container { margin: 0 !important; padding: 0 !important; }
            .section-card { box-shadow: none !important; border: 1px solid #cbd5e1 !important; }
        }
    </style>
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
                    <div class="page-header-title" style="display: flex; justify-content: space-between; align-items: center;">
                        <h5 class="mb-0 font-medium">Goods Received Note Details</h5>
                        <?php if ($grn['status'] == 'draft'): ?>
                            <span class="status-badge status-pending" style="font-size: 0.8rem; padding: 6px 14px;">Draft</span>
                        <?php elseif ($grn['status'] == 'confirmed'): ?>
                            <span class="status-badge status-completed" style="font-size: 0.8rem; padding: 6px 14px;">Confirmed</span>
                        <?php else: ?>
                            <span class="status-badge status-cancelled" style="font-size: 0.8rem; padding: 6px 14px;">Cancelled</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="order-container">
                <!-- GRN Overview Card -->
                <div class="section-card">
                    <div class="section-header">
                        <h5 class="section-title">
                            <i class="fas fa-file-invoice" style="margin-right: 8px; color: #1565C0;"></i>
                            GRN: <strong><?= htmlspecialchars($grn['grn_number']) ?></strong>
                        </h5>
                    </div>
                    <div class="section-body">
                        <div class="grn-grid">
                            <div class="detail-item-box">
                                <div class="detail-item-label">GRN Number</div>
                                <div class="detail-item-val"><?= htmlspecialchars($grn['grn_number']) ?></div>
                            </div>
                            <div class="detail-item-box">
                                <div class="detail-item-label">Received Date</div>
                                <div class="detail-item-val"><?= date('F d, Y', strtotime($grn['received_date'])) ?></div>
                            </div>
                            <div class="detail-item-box">
                                <div class="detail-item-label">Created By</div>
                                <div class="detail-item-val"><?= htmlspecialchars($grn['created_by_name'] ?? 'System') ?></div>
                            </div>
                            <div class="detail-item-box">
                                <div class="detail-item-label">Created At</div>
                                <div class="detail-item-val"><?= date('Y-m-d H:i', strtotime($grn['created_at'])) ?></div>
                            </div>
                        </div>

                        <!-- Supplier Information Box -->
                        <div style="margin-top: 20px; padding: 16px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                            <h6 style="margin-top: 0; margin-bottom: 12px; font-weight: 600; color: #334155;">
                                <i class="fas fa-truck" style="margin-right: 6px; color: #1565C0;"></i> Supplier Information
                            </h6>
                            <div class="grn-grid">
                                <div>
                                    <div class="detail-item-label">Supplier Name</div>
                                    <div class="detail-item-val"><?= htmlspecialchars($grn['supplier_name'] ?? 'Unknown') ?></div>
                                </div>
                                <div>
                                    <div class="detail-item-label">Contact Person</div>
                                    <div class="detail-item-val"><?= htmlspecialchars($grn['contact_person'] ?? '-') ?></div>
                                </div>
                                <div>
                                    <div class="detail-item-label">Phone</div>
                                    <div class="detail-item-val"><?= htmlspecialchars($grn['supplier_phone'] ?? '-') ?></div>
                                </div>
                                <div>
                                    <div class="detail-item-label">Email</div>
                                    <div class="detail-item-val"><?= htmlspecialchars($grn['supplier_email'] ?? '-') ?></div>
                                </div>
                                <div style="grid-column: 1 / -1;">
                                    <div class="detail-item-label">Address</div>
                                    <div class="detail-item-val"><?= htmlspecialchars($grn['supplier_address'] ?? '-') ?></div>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($grn['notes'])): ?>
                        <div style="margin-top: 16px; padding: 14px 16px; background: #fffbeb; border-radius: 8px; border: 1px solid #fde68a;">
                            <div style="font-weight: 600; color: #92400e; font-size: 13px; margin-bottom: 4px;">
                                <i class="fas fa-sticky-note" style="margin-right: 6px;"></i> Notes / Reference:
                            </div>
                            <div style="color: #78350f; font-size: 14px;">
                                <?= nl2br(htmlspecialchars($grn['notes'])) ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Received Items Table Card -->
                <div class="section-card">
                    <div class="section-header">
                        <h5 class="section-title">
                            <i class="fas fa-boxes" style="margin-right: 8px; color: #1565C0;"></i> Stock Items List
                        </h5>
                    </div>
                    <div class="section-body">
                        <div class="table-wrapper" style="box-shadow: none; border: 1px solid #e2e8f0; margin-bottom: 20px;">
                            <table class="orders-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Product Name</th>
                                        <th>Product Code</th>
                                        <th>Batch No.</th>
                                        <th>Received Qty</th>
                                        <th>Buying Price</th>
                                        <th>Selling Price</th>
                                        <th>Subtotal</th>
                                        <th>Batch Remaining Stock</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($items)): ?>
                                        <?php $idx = 1; foreach ($items as $item): ?>
                                            <?php 
                                                $recQty = intval($item['quantity']);
                                                $remQty = isset($item['batch_remaining_qty']) ? intval($item['batch_remaining_qty']) : $recQty;
                                            ?>
                                            <tr>
                                                <td><?= $idx++ ?></td>
                                                <td><strong><?= htmlspecialchars($item['product_name'] ?? 'Unknown') ?></strong></td>
                                                <td><code><?= htmlspecialchars($item['product_code'] ?? '-') ?></code></td>
                                                <td><span style="font-family: monospace; font-size: 12px; font-weight: 600; color: #1e293b;"><?= htmlspecialchars($item['batch_number'] ?? '-') ?></span></td>
                                                <td><strong><?= number_format($recQty) ?></strong></td>
                                                <td>Rs. <?= number_format($item['buying_price'], 2) ?></td>
                                                <td>Rs. <?= number_format($item['selling_price'], 2) ?></td>
                                                <td><strong>Rs. <?= number_format($recQty * $item['buying_price'], 2) ?></strong></td>
                                                <td>
                                                    <?php if ($grn['status'] === 'confirmed'): ?>
                                                        <?php if ($remQty > 0): ?>
                                                            <span class="status-badge status-active" style="padding: 3px 10px; font-size: 12px; font-weight: 600;">
                                                                <?= number_format($remQty) ?> <small style="opacity: 0.8;">/ <?= number_format($recQty) ?></small>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="status-badge status-inactive" style="padding: 3px 10px; font-size: 12px; font-weight: 600;">
                                                                0 / <?= number_format($recQty) ?> (Depleted)
                                                            </span>
                                                        <?php endif; ?>
                                                    <?php elseif ($grn['status'] === 'draft'): ?>
                                                        <span class="status-badge status-pending" style="padding: 3px 10px; font-size: 12px;">
                                                            Pending (<?= number_format($recQty) ?>)
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="status-badge status-inactive" style="padding: 3px 10px; font-size: 12px;">
                                                            Cancelled
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" style="text-align: center; padding: 24px; color: #64748b;">No stock items in this GRN</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div style="display: flex; justify-content: flex-end;">
                            <div class="totals-section" style="min-width: 280px;">
                                <div class="totals-row">
                                    <span class="totals-label">Total Items:</span>
                                    <span class="totals-value"><?= count($items) ?></span>
                                </div>
                                <div class="totals-row">
                                    <span class="totals-label">Total Quantity:</span>
                                    <span class="totals-value"><?= number_format(array_sum(array_column($items, 'quantity'))) ?></span>
                                </div>
                                <div class="totals-row">
                                    <span class="totals-label">Grand Total:</span>
                                    <span class="totals-value" style="color: #059669; font-weight: 700; font-size: 1.1rem;">
                                        Rs. <?= number_format($grn['total_amount'], 2) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Actions Card -->
                <div class="section-card no-print">
                    <div class="section-body" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; padding: 16px 20px;">
                        <a href="grn_list.php" class="btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to List
                        </a>

                        <div style="display: flex; gap: 10px; align-items: center;">
                            <?php if ($grn['status'] == 'draft'): ?>
                                <button type="button" class="btn-primary" id="confirmBtn" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); border-color: #059669;">
                                    <i class="fas fa-check-circle"></i> Confirm GRN (Add Stock)
                                </button>
                                <button type="button" class="btn-secondary" id="cancelBtn" style="background: #ef4444; border-color: #dc2626;">
                                    <i class="fas fa-times-circle"></i> Cancel GRN
                                </button>
                            <?php endif; ?>
                            <button type="button" class="btn-secondary" onclick="window.open('grn_print.php?id=<?= $grn_id ?>', '_blank')" style="background: #475569;">
                                <i class="fas fa-print"></i> Print GRN
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php'); ?>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php'); ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>

    <script>
    <?php if ($grn['status'] == 'draft'): ?>
    // Confirm GRN
    $('#confirmBtn').on('click', function() {
        Swal.fire({
            title: 'Confirm GRN?',
            text: 'This will add stock quantities for all items in this GRN to product inventory.',
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
                    body: JSON.stringify({ grn_id: <?= $grn_id ?>, csrf_token: '<?= $csrf_token ?>' })
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

    // Cancel GRN
    $('#cancelBtn').on('click', function() {
        Swal.fire({
            title: 'Cancel GRN?',
            text: 'This action cannot be undone.',
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
                    body: JSON.stringify({ grn_id: <?= $grn_id ?>, csrf_token: '<?= $csrf_token ?>' })
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
    <?php endif; ?>
    </script>
</body>
</html>
<?php $conn->close(); ?>
