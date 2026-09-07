<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) ob_end_clean();
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

// Access Control: Only Main Admin can create GRNs
if (!isset($_SESSION['is_main_admin']) || $_SESSION['is_main_admin'] != 1) {
    if (ob_get_level()) ob_end_clean();
    header("Location: /OMS/dist/dashboard/index.php");
    exit();
}

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Fetch active tenants
$tenants = [];
$tResult = $conn->query("SELECT tenant_id, company_name, is_main_admin FROM tenants WHERE status = 'active' ORDER BY is_main_admin DESC, company_name ASC");
if ($tResult) {
    while ($row = $tResult->fetch_assoc()) {
        $tenants[] = $row;
    }
}

// Fetch active suppliers (global, no tenant restriction)
$suppliers = [];
$supResult = $conn->query("SELECT id, name FROM suppliers WHERE status = 'active' ORDER BY name ASC");
if ($supResult) {
    while ($row = $supResult->fetch_assoc()) {
        $suppliers[] = $row;
    }
}

// Fetch active products with tenant_id
$products = [];
$prodResult = $conn->query("SELECT p.id, p.name, p.product_code, p.stock_quantity, p.tenant_id, t.company_name
                            FROM products p
                            LEFT JOIN tenants t ON p.tenant_id = t.tenant_id
                            WHERE p.status = 'active'
                            ORDER BY p.name ASC");
if ($prodResult) {
    while ($row = $prodResult->fetch_assoc()) {
        $products[] = $row;
    }
}

// Initial unique batch number for first item
$initial_batch_number = 'BN-' . date('Ymd') . '-' . date('His') . '-0'; // deprecated, kept for reference
?>
<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">
<head>
    <title>Create GRN | <?= htmlspecialchars($_SESSION['company_name'] ?? 'OMS') ?></title>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>
    <link rel="stylesheet" href="../assets/css/styles.css" />
    <link rel="stylesheet" href="../assets/css/orders.css" />
    <link rel="stylesheet" href="../assets/css/customers.css" />
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 10000;
        }
        .loading-spinner {
            text-align: center;
            color: white;
        }
        .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid rgba(255, 255, 255, 0.3);
            border-top: 5px solid #fff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .select2-container--default .select2-selection--single {
            height: 42px !important;
            border: 1px solid #dee2e6 !important;
            border-radius: 6px !important;
            padding: 6px 12px !important;
            display: flex;
            align-items: center;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: normal !important;
            padding-left: 0 !important;
            color: #495057;
            font-size: 14px;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 40px !important;
            right: 8px !important;
        }
        .select2-container--default .select2-selection--single:focus,
        .select2-container--default.select2-container--open .select2-selection--single {
            border-color: #1565C0 !important;
            box-shadow: 0 0 0 2px rgba(21, 101, 192, 0.15) !important;
            outline: none;
        }
        .products-table td {
            vertical-align: middle;
        }
        .products-table input.form-control,
        .products-table select.form-select {
            padding: 6px 10px;
            font-size: 13px;
        }
        .line-subtotal {
            font-weight: 600;
            color: #059669;
            font-size: 13px;
        }
        .batch-number-input[readonly] {
            background: #f1f5f9;
        }
    </style>
</head>
<body>
    <?php
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/loader.php');
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');
    ?>

    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <div class="spinner"></div>
            <h5>Processing GRN...</h5>
            <p>Please wait while we process the Goods Received Note</p>
        </div>
    </div>

    <div class="pc-container">
        <div class="pc-content">
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">Create Goods Received Note (GRN)</h5>
                    </div>
                </div>
            </div>

            <div class="order-container">
                <form method="POST" id="grnForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                    <!-- Supplier & GRN Header Card -->
                    <div class="section-card">
                        <div class="section-header">
                            <h5 class="section-title">
                                <i class="fas fa-truck" style="margin-right: 8px; color: #1565C0;"></i> Supplier & GRN Details
                            </h5>
                        </div>
                        <div class="section-body">
                            <div class="customer-info-grid">
                                <div class="form-group">
                                    <label class="form-label" for="tenant_id">
                                        Tenant Company <span class="required">*</span>
                                    </label>
                                    <select class="form-select" id="tenant_id" name="tenant_id" required>
                                        <?php foreach ($tenants as $t): ?>
                                            <option value="<?php echo $t['tenant_id']; ?>" <?php echo ($t['tenant_id'] == $_SESSION['tenant_id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($t['company_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="error-feedback" id="tenant_id-error"></div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label" for="supplier_id">
                                        Supplier <span class="required">*</span>
                                    </label>
                                    <select class="form-select" id="supplier_id" name="supplier_id" required>
                                        <option value="">-- Select Supplier --</option>
                                        <?php foreach ($suppliers as $sup): ?>
                                            <option value="<?php echo $sup['id']; ?>">
                                                <?php echo htmlspecialchars($sup['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="error-feedback" id="supplier_id-error"></div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label" for="received_date">
                                        Received Date <span class="required">*</span>
                                    </label>
                                    <input type="date" class="form-control" id="received_date" name="received_date" value="<?php echo date('Y-m-d'); ?>" required>
                                    <div class="error-feedback" id="received_date-error"></div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label" for="notes">
                                        Notes / Reference
                                    </label>
                                    <input type="text" class="form-control" id="notes" name="notes" placeholder="Optional notes, invoice #..." maxlength="500">
                                </div>

                                
                            </div>
                        </div>
                    </div>

                    <!-- Line Items Section Card -->
                    <div class="section-card">
                        <div class="section-header" style="display: flex; justify-content: space-between; align-items: center;">
                            <h5 class="section-title">
                                <i class="fas fa-boxes" style="margin-right: 8px; color: #1565C0;"></i> Received Stock Items
                            </h5>
                        </div>
                        <div class="section-body">
                            <div style="overflow-x: auto;">
                                <table class="products-table" id="grn_items_table">
                                    <thead>
                                        <tr>
                                            <th class="action-col" style="width: 50px;">Action</th>
                                            <th style="min-width: 220px;">Product <span class="required">*</span></th>
                                            <th style="width: 100px;">Qty <span class="required">*</span></th>
                                            <th style="width: 140px;">Buying Price (Rs.) <span class="required">*</span></th>
                                            <th style="width: 140px;">Selling Price (Rs.) <span class="required">*</span></th>
                                            <th style="min-width: 150px;">Batch No.</th>
                                            <th style="width: 130px;">Line Total</th>
                                        </tr>
                                    </thead>
                                    <tbody id="lineItemsBody">
                                        <tr class="line-item-row" data-index="0">
                                            <td class="action-col">
                                                <button type="button" class="btn-remove remove-row-btn" title="Remove Item">&times;</button>
                                            </td>
                                            <td>
                                                <select class="form-select product-select" name="items[0][product_id]" required>
                                                    <option value="">-- Select Product --</option>
                                                    <?php foreach ($products as $p): ?>
                                                        <option value="<?php echo $p['id']; ?>"
                                                            data-stock="<?php echo $p['stock_quantity']; ?>"
                                                            data-code="<?php echo htmlspecialchars($p['product_code']); ?>">
                                                            <?php echo htmlspecialchars($p['name']); ?> (<?php echo $p['product_code']; ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="number" name="items[0][quantity]" class="form-control qty-input" placeholder="0" min="1" step="1" required>
                                            </td>
                                            <td>
                                                <div class="input-group">
                                                    <span class="input-group-text">Rs.</span>
                                                    <input type="number" name="items[0][buying_price]" class="form-control buying-price-input" placeholder="0.00" min="0" step="0.01" required>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="input-group">
                                                    <span class="input-group-text">Rs.</span>
                                                    <input type="number" name="items[0][selling_price]" class="form-control selling-price-input" placeholder="0.00" min="0" step="0.01" required>
                                                </div>
                                            </td>
                                            <td>
                                                <input type="text" name="items[0][batch_number]" class="form-control batch-number-input" value="" maxlength="30" readonly style="font-family: monospace; font-size: 12px; background: #f1f5f9;" placeholder="Batch Number">
                                            </td>
                                            <td class="line-subtotal">
                                                Rs. 0.00
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 20px; margin-top: 15px;">
                                <button type="button" id="addRowBtn" class="btn-add-product">
                                    <span>+</span> Add Item
                                </button>

                                <div class="totals-section">
                                    <div class="totals-row">
                                        <span class="totals-label">Total Items:</span>
                                        <span class="totals-value" id="totalItems">1</span>
                                    </div>
                                    <div class="totals-row">
                                        <span class="totals-label">Total Quantity:</span>
                                        <span class="totals-value" id="totalQty">0</span>
                                    </div>
                                    <div class="totals-row">
                                        <span class="totals-label">Grand Total:</span>
                                        <span class="totals-value" style="color: #059669; font-weight: 700; font-size: 1.05rem;">
                                            Rs. <span id="grandTotal">0.00</span>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Action Section Card -->
                    <div class="section-card">
                        <div class="section-body" style="display: flex; justify-content: flex-end; align-items: center; gap: 12px; padding: 16px 20px;">
                            <a href="grn_list.php" class="btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back to List
                            </a>
                            <button type="submit" class="btn-primary" id="submitBtn">
                                <i class="fas fa-save"></i> Save GRN (Draft)
                            </button>
                            <button type="button" class="btn-primary" id="saveAndConfirmBtn" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); border-color: #059669;">
                                <i class="fas fa-check-circle"></i> Save & Confirm
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php'); ?>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php'); ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
    const suppliersData = <?php echo json_encode($suppliers); ?>;
    const productsData = <?php echo json_encode($products); ?>;
    let rowIndex = 1;

    function getFilteredProducts(selectedTenant) {
        return productsData.filter(p => !p.tenant_id || String(p.tenant_id) === String(selectedTenant));
    }

    function getFilteredSuppliers(selectedTenant) {
        // Return all suppliers without tenant filtering as per user request
        return suppliersData;
    }

    function updateSupplierOptions(selectedTenant) {
        const filtered = getFilteredSuppliers(selectedTenant);
        const currentVal = $('#supplier_id').val();
        let options = '<option value="">-- Select Supplier --</option>';
        let stillValid = false;
        filtered.forEach(s => {
            const isSel = String(s.id) === String(currentVal);
            if (isSel) stillValid = true;
            options += `<option value="${s.id}" ${isSel ? 'selected' : ''}>${s.name}</option>`;
        });
        $('#supplier_id').html(options).trigger('change');
        if (!stillValid) {
            $('#supplier_id').val('').trigger('change');
        }
    }

    function updateProductOptionsForRows(selectedTenant) {
        const filtered = getFilteredProducts(selectedTenant);
        $('#lineItemsBody .line-item-row').each(function() {
            const $select = $(this).find('.product-select');
            const currentVal = $select.val();
            let options = '<option value="">-- Select Product --</option>';
            let stillValid = false;
            filtered.forEach(p => {
                const isSel = String(p.id) === String(currentVal);
                if (isSel) stillValid = true;
                options += `<option value="${p.id}" data-stock="${p.stock_quantity}" data-code="${p.product_code}" ${isSel ? 'selected' : ''}>${p.name} (${p.product_code})</option>`;
            });
            $select.html(options);
            if (!stillValid && currentVal) {
                $select.val('').trigger('change');
                $(this).find('.batch-number-input').val('');
                $(this).find('.line-subtotal').text('Rs. 0.00');
            }
        });
        recalculateTotals();
    }

    $(document).ready(function() {
        // Initialize Select2 for supplier and tenant
        $('#supplier_id').select2({ placeholder: '-- Select Supplier --', allowClear: true, width: '100%' });
        $('#tenant_id').select2({ width: '100%' });

        // On tenant change, update supplier and product options
        $('#tenant_id').on('change', function() {
            const selectedTenant = $(this).val();
            updateSupplierOptions(selectedTenant);
            updateProductOptionsForRows(selectedTenant);
        });

        // Trigger initial filtering based on default tenant
        const initialTenant = $('#tenant_id').val();
        if (initialTenant) {
            updateSupplierOptions(initialTenant);
            updateProductOptionsForRows(initialTenant);
        }

        // Add row
        $('#addRowBtn').on('click', function() {
            addNewRow();
        });

        // Remove row
        $(document).on('click', '.remove-row-btn', function() {
            const rows = $('#lineItemsBody .line-item-row');
            if (rows.length <= 1) {
                Swal.fire({ icon: 'warning', title: 'Cannot Remove', text: 'At least one item is required.' });
                return;
            }
            $(this).closest('tr').remove();
            recalculateTotals();
            reindexRows();
        });

        // Product change - auto-fill selling price from product master and generate batch number
        $(document).on('change', '.product-select', function() {
            const $row = $(this).closest('tr');
            const selected = $(this).find(':selected');
            // Auto-generate batch number with product code (always regenerate)
            const batchInput = $row.find('.batch-number-input');
            const productCode = selected.data('code') || 'BATCH';
            batchInput.val(generateBatchNumber(productCode));
            recalculateTotals();
        });

        // Recalculate on input
        $(document).on('input', '.qty-input, .buying-price-input, .selling-price-input', function() {
            const $row = $(this).closest('tr');
            const qty = parseFloat($row.find('.qty-input').val()) || 0;
            const buying = parseFloat($row.find('.buying-price-input').val()) || 0;
            const lineTotal = qty * buying;
            $row.find('.line-subtotal').text('Rs. ' + lineTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
            recalculateTotals();
        });

        // Form submission (Draft)
        $('#grnForm').on('submit', function(e) {
            e.preventDefault();
            clearAllValidations();
            if (validateForm()) {
                submitGRN(false);
            }
        });

        // Save & Confirm
        $('#saveAndConfirmBtn').on('click', function(e) {
            e.preventDefault();
            clearAllValidations();
            if (validateForm()) {
                submitGRN(true);
            }
        });
    });

    function addNewRow() {
        const selectedTenant = $('#tenant_id').val();
        const filteredProducts = getFilteredProducts(selectedTenant);
        const productOptions = filteredProducts.map(p =>
            `<option value="${p.id}" data-stock="${p.stock_quantity}" data-code="${p.product_code}">${p.name} (${p.product_code})</option>`
        ).join('');

        const row = `
            <tr class="line-item-row" data-index="${rowIndex}">
                <td class="action-col">
                    <button type="button" class="btn-remove remove-row-btn" title="Remove Item">&times;</button>
                </td>
                <td>
                    <select class="form-select product-select" name="items[${rowIndex}][product_id]" required>
                        <option value="">-- Select Product --</option>
                        ${productOptions}
                    </select>
                </td>
                <td>
                    <input type="number" name="items[${rowIndex}][quantity]" class="form-control qty-input" placeholder="0" min="1" step="1" required>
                </td>
                <td>
                    <div class="input-group">
                        <span class="input-group-text">Rs.</span>
                        <input type="number" name="items[${rowIndex}][buying_price]" class="form-control buying-price-input" placeholder="0.00" min="0" step="0.01" required>
                    </div>
                </td>
                <td>
                    <div class="input-group">
                        <span class="input-group-text">Rs.</span>
                        <input type="number" name="items[${rowIndex}][selling_price]" class="form-control selling-price-input" placeholder="0.00" min="0" step="0.01" required>
                    </div>
                </td>
                <td>
                    <input type="text" name="items[${rowIndex}][batch_number]" class="form-control batch-number-input" value="" maxlength="30" readonly style="font-family: monospace; font-size: 12px; background: #f1f5f9;">
                </td>
                <td class="line-subtotal">
                    Rs. 0.00
                </td>
            </tr>`;

        $('#lineItemsBody').append(row);
        rowIndex++;
        reindexRows();
        recalculateTotals();
    }

    function reindexRows() {
        $('#lineItemsBody .line-item-row').each(function(i) {
            $(this).attr('data-index', i);
            $(this).find('select, input').each(function() {
                const name = $(this).attr('name');
                if (name) {
                    $(this).attr('name', name.replace(/items\[\d+\]/, 'items[' + i + ']'));
                }
            });
        });
    }

    function recalculateTotals() {
        let grandTotal = 0;
        let totalQty = 0;
        let totalItems = 0;

        $('#lineItemsBody .line-item-row').each(function() {
            const productId = $(this).find('.product-select').val();
            if (productId) totalItems++;
            const qty = parseFloat($(this).find('.qty-input').val()) || 0;
            const buying = parseFloat($(this).find('.buying-price-input').val()) || 0;
            grandTotal += qty * buying;
            totalQty += qty;
        });

        $('#totalItems').text(totalItems);
        $('#totalQty').text(totalQty);
        $('#grandTotal').text(grandTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
    }

    function validateForm() {
        let valid = true;

        if (!$('#supplier_id').val()) {
            showError('supplier_id', 'Please select a supplier');
            valid = false;
        }
        if (!$('#received_date').val()) {
            showError('received_date', 'Received date is required');
            valid = false;
        }

        let hasItems = false;
        $('#lineItemsBody .line-item-row').each(function() {
            const productId = $(this).find('.product-select').val();
            const qty = parseFloat($(this).find('.qty-input').val()) || 0;
            const buying = parseFloat($(this).find('.buying-price-input').val()) || 0;
            const selling = parseFloat($(this).find('.selling-price-input').val()) || 0;

            if (productId && qty > 0 && buying > 0 && selling > 0) {
                hasItems = true;
            } else if (productId || qty > 0 || buying > 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Incomplete Item',
                    text: 'Please fill in all required fields for each item (Product, Quantity, Buying Price, Selling Price).'
                });
                valid = false;
                return false;
            }
        });

        if (!hasItems) {
            Swal.fire({ icon: 'warning', title: 'No Items', text: 'Please add at least one valid stock item.' });
            valid = false;
        }

        return valid;
    }

    function submitGRN(autoConfirm) {
        showLoading();
        const $btn = autoConfirm ? $('#saveAndConfirmBtn') : $('#submitBtn');
        const orig = $btn.html();
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');

        const formData = new FormData($('#grnForm')[0]);
        formData.append('auto_confirm', autoConfirm ? '1' : '0');

        $.ajax({
            url: 'save_grn.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            timeout: 60000,
            success: function(resp) {
                hideLoading();
                $btn.prop('disabled', false).html(orig);
                if (resp.success) {
                    Swal.fire({
                        icon: 'success',
                        title: autoConfirm ? 'GRN Confirmed!' : 'GRN Saved!',
                        text: resp.message,
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        window.location.href = 'view_grn.php?id=' + resp.grn_id;
                    });
                } else {
                    if (resp.errors) {
                        $.each(resp.errors, function(f, m) { showError(f, m); });
                    }
                    Swal.fire({ icon: 'error', title: 'Error', text: resp.message || 'Failed to create GRN.' });
                }
            },
            error: function() {
                hideLoading();
                $btn.prop('disabled', false).html(orig);
                Swal.fire({ icon: 'error', title: 'Error', text: 'An error occurred. Please try again.' });
            }
        });
    }

    function showLoading() {
        $('#loadingOverlay').css('display', 'flex');
        $('body').css('overflow', 'clip');
    }

    function hideLoading() {
        $('#loadingOverlay').hide();
        $('body').css('overflow', 'auto');
    }

    function showError(f, m) {
        $('#' + f).addClass('is-invalid').removeClass('is-valid');
        $('#' + f + '-error').text(m).show();
    }

    function clearAllValidations() {
        $('.form-control, .form-select').removeClass('is-invalid is-valid');
        $('.error-feedback').hide().text('');
    }

    // Generate unique batch number starting with product code (short format)
    function generateBatchNumber(productCode) {
        productCode = productCode || 'BATCH';
        const now = new Date();
        const yy = String(now.getFullYear()).slice(-2);
        const mm = String(now.getMonth() + 1).padStart(2, '0');
        const dd = String(now.getDate()).padStart(2, '0');
        const randomPart = Math.random().toString(36).substring(2, 6).toUpperCase();
        return productCode + '-' + yy + mm + dd + '-' + randomPart;
    }


    </script>
</body>
</html>
<?php $conn->close(); ?>
