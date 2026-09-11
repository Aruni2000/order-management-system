<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) ob_end_clean();
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

$grn_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($grn_id <= 0) {
    die("GRN ID is required");
}

// Access control
$is_main_admin = isset($_SESSION['is_main_admin']) ? (int)$_SESSION['is_main_admin'] : 0;
$role_id = isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : 0;
if ($is_main_admin !== 1 || !in_array($role_id, [1, 3], true)) {
    die("Access Denied: Only main admin (admin or store role) can print GRN");
}
$session_tenant_id = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;

// Fetch GRN header with supplier + creator
$grn = null;
$grnSql = "SELECT g.*, s.name as supplier_name, s.contact_person, s.phone as supplier_phone,
                  s.email as supplier_email, s.address as supplier_address, u.name as created_by_name
            FROM grn g
            LEFT JOIN suppliers s ON g.supplier_id = s.id
            LEFT JOIN users u ON g.created_by = u.id
            WHERE g.grn_id = ?";
$grnStmt = $conn->prepare($grnSql);
if (!$grnStmt) die("Prepare failed: " . $conn->error);
$grnStmt->bind_param("i", $grn_id);
$grnStmt->execute();
$grnResult = $grnStmt->get_result();
if ($grnResult && $grnResult->num_rows > 0) {
    $grn = $grnResult->fetch_assoc();
}
$grnStmt->close();

if (!$grn) {
    die("GRN not found or access denied.");
}

$grn_tenant_id = isset($grn['tenant_id']) ? (int)$grn['tenant_id'] : 0;

// Tenant / company letterhead info
$company_name = "";
$company_address = "";
$company_email = "";
$company_phone = "";
$company_logo = "";

$tenantSql = "SELECT company_name, address, phone, email, logo_url FROM tenants WHERE tenant_id = ? AND status = 'active' LIMIT 1";
$tenantStmt = $conn->prepare($tenantSql);
if ($tenantStmt) {
    $tenantStmt->bind_param("i", $grn_tenant_id);
    $tenantStmt->execute();
    $tenantResult = $tenantStmt->get_result();
    $tenant_data = ($tenantResult && $tenantResult->num_rows > 0) ? $tenantResult->fetch_assoc() : [];
    $company_name = $tenant_data['company_name'] ?? '';
    $company_address = $tenant_data['address'] ?? '';
    $company_email = $tenant_data['email'] ?? '';
    $company_phone = $tenant_data['phone'] ?? '';
    if (!empty($tenant_data['logo_url'])) {
        $logo = $tenant_data['logo_url'];
        if (strpos($logo, 'http') === 0) {
            $company_logo = $logo;
        } elseif (strpos($logo, '/OMS/') === 0) {
            $company_logo = $logo;
        } else {
            $company_logo = '/OMS/dist/' . ltrim($logo, '/');
        }
    }
    $tenantStmt->close();
}

// Fetch GRN items
$items = [];
$itemsSql = "SELECT gi.*, p.name as product_name, p.product_code, p.stock_quantity as product_total_stock,
                    b.remaining_qty as batch_remaining_qty, b.status as batch_status
             FROM grn_items gi
             LEFT JOIN products p ON gi.product_id = p.id
             LEFT JOIN batches b ON b.grn_item_id = gi.id
             WHERE gi.grn_id = ?
             ORDER BY gi.id ASC";
$itemsStmt = $conn->prepare($itemsSql);
if ($itemsStmt) {
    $itemsStmt->bind_param("i", $grn_id);
    $itemsStmt->execute();
    $itemsResult = $itemsStmt->get_result();
    if ($itemsResult) {
        while ($row = $itemsResult->fetch_assoc()) {
            $items[] = $row;
        }
    }
    $itemsStmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GRN Print - <?php echo htmlspecialchars($grn['grn_number']); ?></title>
    <?php
    $favicon_url = '';
    if (isset($conn) && $conn) {
        try {
            $user_tenant_id = $_SESSION['tenant_id'] ?? null;
            if ($user_tenant_id) {
                $fav_query = "SELECT fav_icon_url FROM tenants WHERE tenant_id = " . (int)$user_tenant_id . " AND status = 'active' AND fav_icon_url IS NOT NULL AND fav_icon_url != '' LIMIT 1";
            } else {
                $fav_query = "SELECT fav_icon_url FROM tenants WHERE status = 'active' AND fav_icon_url IS NOT NULL AND fav_icon_url != '' LIMIT 1";
            }
            $fav_result = $conn->query($fav_query);
            if ($fav_result && $fav_result->num_rows > 0) {
                $fav_data = $fav_result->fetch_assoc();
                $favicon_url = $fav_data['fav_icon_url'];
            }
        } catch (Throwable $e) {}
    }
    if ($favicon_url) echo '<link rel="icon" href="' . htmlspecialchars($favicon_url) . '" type="image/x-icon" />';
    else echo '<link rel="icon" href="../assets/images/enterprise.png" type="image/x-icon" />';
    ?>
    <style>
        /* ===== Base Reset ===== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #000;
            background: #fff;
            padding: 15px;
            max-width: 210mm;
            margin: 0 auto;
        }

        /* ===== GRN Container ===== */
        .grn-container {
            width: 100%;
            max-width: 210mm;
            margin: 0 auto;
            background: #fff;
        }

        /* ===== Header / Company Info (no box) ===== */
        .main-table {
            width: 100%;
            border-collapse: collapse;
            border: none;
            font-size: 12px;
        }

        .main-table td {
            border: none;
            padding: 12px;
            vertical-align: top;
        }

        .header-section {
            padding: 5px;
            text-align: center;
            vertical-align: top;
        }

        .company-logo img {
            max-height: 55px;
            width: auto;
        }

        .company-name {
            font-size: 14px;
            margin-bottom: 5px;
        }

        .company-info {
            font-size: 10px;
            margin-bottom: 2px;
        }

        /* ===== Document Title ===== */
        .grn-doc-title {
            text-align: center;
            font-size: 18px;
            font-weight: bold;
            letter-spacing: 2px;
            border-bottom: 2px solid #000;
            padding-bottom: 6px;
            margin-top: 8px;
        }

        /* ===== GRN Badges ===== */
        .grn-badges-row {
            text-align: center;
            margin: 10px 0;
        }

        .grn-badge {
            font-size: 14px;
            font-weight: bold;
            text-align: center;
            margin: 8px 0 12px;
            border: 2px solid #000;
            display: inline-block;
            padding: 4px 16px;
        }

        /* ===== Info Table (Supplier / Dates) ===== */
        .grn-info-table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
            font-size: 12px;
        }

        .grn-info-table td {
            border: 1px solid #000;
            padding: 8px 10px;
            vertical-align: top;
        }

        .grn-info-label {
            font-weight: bold;
            width: 30%;
            background: #f0f0f0;
        }

        /* ===== Items Table ===== */
        .grn-items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
            font-size: 12px;
        }

        .grn-items-table th,
        .grn-items-table td {
            border: 1px solid #000;
            padding: 7px 8px;
            text-align: left;
        }

        .grn-items-table th {
            background: #e0e0e0;
            font-weight: bold;
        }

        .grn-items-table .num {
            text-align: right;
        }

        .grn-items-table .ctr {
            text-align: center;
        }

        .grn-grand-total td {
            font-weight: bold;
            font-size: 13px;
        }

        /* ===== Signature Section ===== */
        .grn-signature-section {
            width: 100%;
            border-collapse: collapse;
            margin-top: 40px;
            font-size: 12px;
        }

        .grn-signature-section td {
            width: 33%;
            padding: 0 10px;
        }

        .grn-sig-line {
            border-top: 1px solid #000;
            margin-top: 40px;
            padding-top: 6px;
            text-align: center;
            font-weight: bold;
            font-size: 11px;
        }

        /* ===== Footer Note ===== */
        .grn-footer-note {
            text-align: center;
            font-size: 10px;
            color: #555;
            margin-top: 20px;
        }

        /* ===== Print Button ===== */
        .grn-print-btn {
            display: block;
            margin: 20px auto;
            background: #1565C0;
            color: white;
            border: none;
            padding: 10px 30px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }

        .grn-print-btn:hover {
            background: #0d47a1;
        }

        /* ===== Print Media Queries ===== */
        @media print {
            body {
                padding: 0;
                margin: 0;
                font-size: 11px;
                max-width: none;
            }

            .grn-container {
                max-width: none;
                width: 100%;
            }

            .grn-print-btn {
                display: none !important;
            }

            .main-table {
                page-break-inside: avoid;
            }

            .grn-signature-section {
                page-break-inside: avoid;
            }

            .grn-items-table {
                page-break-inside: auto;
            }

            .grn-items-table tr {
                page-break-inside: avoid;
            }

            @page {
                size: A4;
                margin: 1cm;
            }
        }
    </style>
</head>
<body>
    <!-- Print Button (hidden on print) -->
    <button class="grn-print-btn" onclick="window.print()">🖨️ Print GRN</button>

    <div class="grn-container">
        <table class="main-table">
            <tr>
                <td class="header-section">
                    <div class="company-logo">
                        <?php if (!empty($company_logo)): ?>
                            <img src="<?php echo htmlspecialchars($company_logo); ?>" alt="<?php echo htmlspecialchars($company_name); ?> Logo">
                        <?php else: ?>
                            <div style="font-weight: bold; font-size: 16px; color: #333;">
                                <?php echo htmlspecialchars($company_name ?: 'Company'); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="company-name"><?php echo htmlspecialchars($company_name ?: 'Company'); ?></div>
                    <?php if (!empty($company_address)): ?>
                        <div class="company-info">Address: <?php echo htmlspecialchars($company_address); ?></div>
                    <?php endif; ?>
                    <div class="company-info">
                        <?php if (!empty($company_phone)): ?>
                            Hotline: <?php echo htmlspecialchars($company_phone); ?>
                        <?php endif; ?>
                        <?php if (!empty($company_email)): ?>
                            <?php if (!empty($company_phone)): ?> | <?php endif; ?> Email: <?php echo htmlspecialchars($company_email); ?>
                        <?php endif; ?>
                    </div>
                    <div class="grn-doc-title">GOODS RECEIVED NOTE</div>
                </td>
            </tr>
        </table>

        <div class="grn-badges-row">
            <span class="grn-badge">GRN No: <?php echo htmlspecialchars($grn['grn_number']); ?></span>
            &nbsp;&nbsp;
            <span class="grn-badge">Status: <?php echo htmlspecialchars(ucfirst($grn['status'])); ?></span>
        </div>

        <table class="grn-info-table">
            <tr>
                <td class="grn-info-label">Supplier Name</td>
                <td><?php echo htmlspecialchars($grn['supplier_name'] ?? 'Unknown'); ?></td>
                <td class="grn-info-label">Received Date</td>
                <td><?php echo date('F d, Y', strtotime($grn['received_date'])); ?></td>
            </tr>
            <tr>
                <td class="grn-info-label">Contact Person</td>
                <td><?php echo htmlspecialchars($grn['contact_person'] ?? '-'); ?></td>
                <td class="grn-info-label">Created By</td>
                <td><?php echo htmlspecialchars($grn['created_by_name'] ?? 'System'); ?></td>
            </tr>
            <tr>
                <td class="grn-info-label">Phone</td>
                <td><?php echo htmlspecialchars($grn['supplier_phone'] ?? '-'); ?></td>
                <td class="grn-info-label">Created At</td>
                <td><?php echo date('Y-m-d H:i', strtotime($grn['created_at'])); ?></td>
            </tr>
            <tr>
                <td class="grn-info-label">Supplier Address</td>
                <td colspan="3"><?php echo htmlspecialchars($grn['supplier_address'] ?? '-'); ?></td>
            </tr>
            <?php if (!empty($grn['notes'])): ?>
            <tr>
                <td class="grn-info-label">Notes / Reference</td>
                <td colspan="3"><?php echo nl2br(htmlspecialchars($grn['notes'])); ?></td>
            </tr>
            <?php endif; ?>
        </table>

        <table class="grn-items-table">
            <thead>
                <tr>
                    <th class="ctr" style="width:5%">#</th>
                    <th>Product Name</th>
                    <th style="width:14%">Product Code</th>
                    <th style="width:14%">Batch No.</th>
                    <th class="ctr" style="width:9%">Received Qty</th>
                    <th class="num" style="width:12%">Buying Price</th>
                    <th class="num" style="width:12%">Selling Price</th>
                    <th class="num" style="width:13%">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($items)): ?>
                    <?php $idx = 1; foreach ($items as $item): ?>
                        <?php $recQty = intval($item['quantity']); ?>
                        <tr>
                            <td class="ctr"><?= $idx++ ?></td>
                            <td><?= htmlspecialchars($item['product_name'] ?? 'Unknown') ?></td>
                            <td><?= htmlspecialchars($item['product_code'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($item['batch_number'] ?? '-') ?></td>
                            <td class="ctr"><?= number_format($recQty) ?></td>
                            <td class="num"><?= number_format($item['buying_price'], 2) ?></td>
                            <td class="num"><?= number_format($item['selling_price'], 2) ?></td>
                            <td class="num"><?= number_format($recQty * $item['buying_price'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="8" class="ctr">No stock items in this GRN</td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr class="grn-grand-total">
                    <td colspan="4" style="text-align:right;">Total Items: <?= count($items) ?> &nbsp;|&nbsp; Total Qty: <?= number_format(array_sum(array_column($items, 'quantity'))) ?></td>
                    <td colspan="4" class="num" style="border-left:1px solid #000;">Grand Total: <?= number_format($grn['total_amount'], 2) ?> LKR</td>
                </tr>
            </tfoot>
        </table>

        <table class="grn-signature-section">
            <tr>
                <td><div class="grn-sig-line">Prepared By</div></td>
                <td><div class="grn-sig-line">Received By</div></td>
                <td><div class="grn-sig-line">Approved By</div></td>
            </tr>
        </table>

        <div class="grn-footer-note">
            This is a system generated Goods Received Note. Signature confirms the goods listed above were received in good condition.
        </div>
    </div>
</body>
</html>
<?php $conn->close(); ?>
