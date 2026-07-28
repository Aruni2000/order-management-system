/**
 * Shared Action Functions (API Modal Design)
 * Converted from SweetAlert2 to custom HTML modals matching the API modal pattern.
 * Auto-detects API base path by checking the current page URL.
 * Include AFTER toast.js and the modals include.
 */

// Disable SweetAlert2's built-in scrollbar padding (scrollbar-gutter: stable handles this)
if (typeof Swal !== 'undefined') {
    var _origFire = Swal.fire.bind(Swal);
    Swal.fire = function(opts) {
        if (opts && typeof opts === 'object') { opts.scrollbarPadding = false; }
        return _origFire(opts);
    };
}

// Detect API base path based on current directory
function getApiBase() {
    const path = window.location.pathname;
    if (path.includes('/leads/')) return '../orders/';
    if (path.includes('/orders/')) return '';
    return '';
}

// Ensure showAlert is available as alias for toastManager
if (typeof showAlert === 'undefined') {
    window.showAlert = function(type, message) {
        if (typeof toastManager !== 'undefined') {
            if (type === 'success') toastManager.success(message);
            else if (type === 'error') toastManager.error(message);
            else if (type === 'warning') toastManager.warning(message);
            else toastManager.info(message);
        } else {
            alert(message);
        }
    };
}

// Helper: show a modal by ID
function showModal(id) {
    var el = document.getElementById(id);
    if (el) {
        el.style.display = 'flex';
        document.body.style.overflow = 'clip';
    }
}

// Helper: hide a modal by ID
function hideModal(id) {
    var el = document.getElementById(id);
    if (el) {
        el.style.display = 'none';
        document.body.style.overflow = '';
    }
}

// =============================================
// PAYMENT METHOD CARD SELECTION
// =============================================
function selectPaymentMethod(labelEl, value) {
    // Deselect all
    var parent = labelEl.closest('.card-radio-row');
    var labels = parent.querySelectorAll('.card-radio-label');
    labels.forEach(function(l) { l.classList.remove('selected'); });
    // Select this one
    labelEl.classList.add('selected');
    var radio = labelEl.querySelector('input[type="radio"]');
    if (radio) radio.checked = true;
}

// =============================================
// ANSWER STATUS CARD SELECTION
// =============================================
function selectAnswerStatus(labelEl, value) {
    var parent = labelEl.closest('.card-radio-row');
    var labels = parent.querySelectorAll('.card-radio-label');
    labels.forEach(function(l) { l.classList.remove('selected'); });
    labelEl.classList.add('selected');
    var radio = labelEl.querySelector('input[type="radio"]');
    if (radio) radio.checked = true;

    // Update notes label and placeholder
    var notesLabel = document.getElementById('ans-notes-label');
    var notesTa = document.getElementById('ans-notes');
    var notesHelp = document.getElementById('ans-notes-help');
    if (value === '1') {
        notesLabel.textContent = 'ANSWER NOTES';
        notesTa.placeholder = 'Enter details about customer conversation...';
        notesHelp.innerHTML = '<i class="fas fa-info-circle" style="font-size:11px;margin-right:4px;"></i>Please provide details about the customer conversation';
    } else {
        notesLabel.textContent = 'NO ANSWER REASON';
        notesTa.placeholder = 'Enter reason for no answer (busy, unreachable, etc.)...';
        notesHelp.innerHTML = '<i class="fas fa-info-circle" style="font-size:11px;margin-right:4px;"></i>Please specify why the customer did not answer';
    }
}

// =============================================
// CONDITION RADIO SELECTION
// =============================================
function selectCondition(labelEl, value) {
    var parent = labelEl.closest('.condition-radio-list');
    var items = parent.querySelectorAll('.condition-radio-item label');
    items.forEach(function(l) { l.classList.remove('selected'); });
    labelEl.classList.add('selected');
    var radio = labelEl.querySelector('input[type="radio"]');
    if (radio) radio.checked = true;
}

// =============================================
// 1. MARK AS PAID
// =============================================
function markAsPaid(orderId) {
    if (!orderId || orderId.trim() === '') {
        toastManager.warning('Order ID is required to mark as paid.');
        return;
    }
    var id = orderId.trim();
    document.getElementById('mp-order-hdr').textContent = id;
    // Reset form
    var form = document.getElementById('markPaidForm');
    if (form) form.reset();
    // Reset payment method to Cash
    var cashLabel = document.getElementById('mp-label-cash');
    if (cashLabel) {
        var labels = cashLabel.closest('.card-radio-row').querySelectorAll('.card-radio-label');
        labels.forEach(function(l) { l.classList.remove('selected'); });
        cashLabel.classList.add('selected');
        var radio = cashLabel.querySelector('input[type="radio"]');
        if (radio) radio.checked = true;
    }
    showModal('markPaidModal');
}

// =============================================
// 2. UNMARK AS PAID
// =============================================
function unmarkPaid(orderId) {
    if (!orderId || orderId.trim() === '') {
        toastManager.warning('Order ID is required to unmark as paid.');
        return;
    }
    document.getElementById('up-order-hdr').textContent = orderId.trim();
    showModal('unmarkPaidModal');
}

// =============================================
// 3. CANCEL ORDER
// =============================================
function cancelOrder(orderId) {
    if (!orderId || orderId.trim() === '') {
        toastManager.warning('Order ID is required to cancel order.');
        return;
    }
    document.getElementById('co-order-hdr').textContent = orderId.trim();
    var ta = document.getElementById('co-reason');
    if (ta) ta.value = '';
    showModal('cancelOrderModal');
    // Focus textarea after modal opens
    setTimeout(function() { if (ta) ta.focus(); }, 300);
}

// =============================================
// 4. RESTORE ORDER
// =============================================
function restoreOrder(orderId) {
    if (!orderId || orderId.trim() === '') {
        toastManager.warning('Order ID is required to restore order.');
        return;
    }
    document.getElementById('ro-order-hdr').textContent = orderId.trim();
    showModal('restoreOrderModal');
}

// =============================================
// 5. UPDATE SUCCESS RATE (Condition)
// =============================================
function openConditionModal(orderId, currentCondition) {
    if (!orderId) return;

    var condNames = {0:'Excellent',1:'Good',2:'Average',3:'Bad',4:'New'};
    var condColors = {0:'#198754',1:'#0d6efd',2:'#fd7e14',3:'#dc3545',4:'#6c757d'};
    var currentName = condNames[currentCondition] || '—';

    document.getElementById('cd-order-hdr').textContent = orderId;
    var nameEl = document.getElementById('cd-current-name');
    nameEl.textContent = currentName;
    nameEl.style.color = '#fff';
    nameEl.style.background = condColors[currentCondition] || '#6c757d';

    // Build radio list
    var list = document.getElementById('cd-radio-list');
    if (!list) return;
    list.innerHTML = '';
    var keys = Object.keys(condNames);
    for (var i = 0; i < keys.length; i++) {
        var val = keys[i];
        var name = condNames[val];
        var checked = (parseInt(val) === currentCondition);
        var div = document.createElement('div');
        div.className = 'condition-radio-item';
        var label = document.createElement('label');
        if (checked) label.classList.add('selected');
        label.setAttribute('onclick', 'selectCondition(this, \'' + val + '\')');
        label.innerHTML = '<input type="radio" name="cd_condition" value="' + val + '" ' + (checked ? 'checked' : '') + '>'
                        + '<span class="condition-name">' + name + '</span>';
        div.appendChild(label);
        list.appendChild(div);
    }

    showModal('conditionModal');
}

// =============================================
// 6. CALL STATUS (Answer / No Answer)
// =============================================
function openAnswerModal(orderId, callLogStatus, existingAnswerReason, existingNoAnswerReason) {
    if (!orderId || orderId.trim() === '') {
        toastManager.error('Order ID is required to update call status.');
        return;
    }

    var currentCallLog = parseInt(callLogStatus);
    var suggestedStatus = (currentCallLog === 1 || currentCallLog === 0) ? currentCallLog : 0;
    var existingReason = suggestedStatus === 1 ? (existingAnswerReason || '') : (existingNoAnswerReason || '');

    document.getElementById('ans-order-hdr').textContent = orderId.trim();

    // Select the correct status card
    var answerLabel = document.getElementById('ans-label-answer');
    var noanswerLabel = document.getElementById('ans-label-noanswer');
    if (suggestedStatus === 1) {
        answerLabel.classList.add('selected');
        noanswerLabel.classList.remove('selected');
        var r1 = answerLabel.querySelector('input[type="radio"]');
        if (r1) r1.checked = true;
        var r0 = noanswerLabel.querySelector('input[type="radio"]');
        if (r0) r0.checked = false;
    } else {
        answerLabel.classList.remove('selected');
        noanswerLabel.classList.add('selected');
        var r1 = answerLabel.querySelector('input[type="radio"]');
        if (r1) r1.checked = false;
        var r0 = noanswerLabel.querySelector('input[type="radio"]');
        if (r0) r0.checked = true;
    }

    // Update notes label and set existing reason
    var notesLabel = document.getElementById('ans-notes-label');
    var notesTa = document.getElementById('ans-notes');
    var notesHelp = document.getElementById('ans-notes-help');
    if (suggestedStatus === 1) {
        notesLabel.textContent = 'ANSWER NOTES';
        notesTa.placeholder = 'Enter details about customer conversation...';
        notesHelp.innerHTML = '<i class="fas fa-info-circle" style="font-size:11px;margin-right:4px;"></i>Please provide details about the customer conversation';
    } else {
        notesLabel.textContent = 'NO ANSWER REASON';
        notesTa.placeholder = 'Enter reason for no answer (busy, unreachable, etc.)...';
        notesHelp.innerHTML = '<i class="fas fa-info-circle" style="font-size:11px;margin-right:4px;"></i>Please specify why the customer did not answer';
    }
    notesTa.value = existingReason;

    showModal('answerModal');
}

// =============================================
// CONSOLIDATED FORM BINDING
// =============================================
document.addEventListener('DOMContentLoaded', function() {
    // --- Mark Paid ---
    var mpForm = document.getElementById('markPaidForm');
    if (mpForm) {
        mpForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var orderId = document.getElementById('mp-order-hdr').textContent;
            var methodEl = document.querySelector('input[name="mp_method"]:checked');
            if (!methodEl) { toastManager.error('Please select a payment method.'); return; }
            var method = methodEl.value;
            var fileInput = document.getElementById('mp-payment-slip');
            var file = fileInput ? fileInput.files[0] : null;
            if (file) {
                if (file.size > 2 * 1024 * 1024) { toastManager.error('File size must be less than 2MB'); return; }
                var allowed = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
                if (!allowed.includes(file.type)) { toastManager.error('Please select a valid file format (JPG, JPEG, PNG, PDF)'); return; }
            }
            var btn = document.getElementById('mp-submit-btn');
            var origText = btn.innerHTML;
            btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            var formData = new FormData();
            formData.append('order_id', orderId);
            formData.append('payment_method', method);
            if (file) formData.append('payment_slip', file);
            formData.append('action', 'mark_paid');
            var apiBase = getApiBase();
            fetch(apiBase + 'mark_paid.php', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) { showAlert('success', 'Order marked as paid successfully!'); hideModal('markPaidModal'); setTimeout(function() { window.location.reload(); }, 1500); }
                else { showAlert('error', data.message || 'Failed to mark order as paid'); }
            })
            .catch(function(err) { console.error('Error:', err); showAlert('error', 'An error occurred while processing the payment.'); })
            .finally(function() { btn.innerHTML = origText; btn.disabled = false; });
        });
    }

    // --- Unmark Paid ---
    var upForm = document.getElementById('unmarkPaidForm');
    if (upForm) {
        upForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var orderId = document.getElementById('up-order-hdr').textContent;
            var btn = document.getElementById('up-submit-btn');
            var origText = btn.innerHTML;
            btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            var formData = new FormData();
            formData.append('order_id', orderId);
            formData.append('action', 'unmark_paid');
            var apiBase = getApiBase();
            fetch(apiBase + 'unmark_paid.php', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) { showAlert('success', 'Order unmarked as paid successfully!'); hideModal('unmarkPaidModal'); setTimeout(function() { window.location.reload(); }, 1500); }
                else { showAlert('error', data.message || 'Failed to unmark order as paid'); }
            })
            .catch(function(err) { console.error('Error:', err); showAlert('error', 'An error occurred while unmarking the payment.'); })
            .finally(function() { btn.innerHTML = origText; btn.disabled = false; });
        });
    }

    // --- Cancel Order ---
    var coForm = document.getElementById('cancelOrderForm');
    if (coForm) {
        coForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var orderId = document.getElementById('co-order-hdr').textContent;
            var reason = document.getElementById('co-reason') ? document.getElementById('co-reason').value.trim() : '';
            var btn = document.getElementById('co-submit-btn');
            var origText = btn.innerHTML;
            btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cancelling...';
            var formData = new FormData();
            formData.append('order_id', orderId);
            formData.append('cancellation_reason', reason);
            formData.append('action', 'cancel_order');
            var apiBase = getApiBase();
            fetch(apiBase + 'cancel_order.php', { method: 'POST', body: formData })
            .then(function(r) { if (!r.ok) throw new Error('HTTP error! status: ' + r.status); return r.json(); })
            .then(function(data) {
                if (data.success) { showAlert('success', 'Order cancelled successfully!'); hideModal('cancelOrderModal'); setTimeout(function() { window.location.reload(); }, 1500); }
                else { showAlert('error', data.message || 'Failed to cancel order'); }
            })
            .catch(function(err) { console.error('Error:', err); showAlert('error', 'An error occurred while cancelling the order.'); })
            .finally(function() { btn.innerHTML = origText; btn.disabled = false; });
        });
    }

    // --- Restore Order ---
    var roForm = document.getElementById('restoreOrderForm');
    if (roForm) {
        roForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var orderId = document.getElementById('ro-order-hdr').textContent;
            var btn = document.getElementById('ro-submit-btn');
            var origText = btn.innerHTML;
            btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Restoring...';
            var formData = new FormData();
            formData.append('order_id', orderId.trim());
            var apiBase = getApiBase();
            fetch(apiBase + 'restore_order.php', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) { showAlert('success', 'Order restored successfully!'); hideModal('restoreOrderModal'); setTimeout(function() { window.location.reload(); }, 1500); }
                else { showAlert('error', data.message || 'Failed to restore order'); }
            })
            .catch(function(err) { console.error('Error restoring order:', err); showAlert('error', 'An error occurred while restoring the order.'); })
            .finally(function() { btn.innerHTML = origText; btn.disabled = false; });
        });
    }

    // --- Condition ---
    var cdForm = document.getElementById('conditionForm');
    if (cdForm) {
        cdForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var orderId = document.getElementById('cd-order-hdr').textContent;
            var selected = document.querySelector('input[name="cd_condition"]:checked');
            if (!selected) { toastManager.error('Please select a success rate.'); return; }
            var btn = document.getElementById('cd-submit-btn');
            var origText = btn.innerHTML;
            btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            var formData = new FormData();
            formData.append('order_id', orderId);
            formData.append('condition', selected.value);
            var apiBase = getApiBase();
            fetch(apiBase + 'update_condition.php', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) { showAlert('success', 'Success rate updated!'); hideModal('conditionModal'); setTimeout(function() { location.reload(); }, 1500); }
                else { showAlert('error', data.message || 'Failed to update success rate'); }
            })
            .catch(function(err) { console.error('Error:', err); showAlert('error', 'An error occurred while updating the success rate'); })
            .finally(function() { btn.innerHTML = origText; btn.disabled = false; });
        });
    }

    // --- Status Change ---
    var scForm = document.getElementById('statusChangeForm');
    if (scForm) {
        scForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var coId = scForm.getAttribute('data-co-id');
            var newStatus = scForm.getAttribute('data-new-status');
            if (!coId || !newStatus) return;
            var btn = document.getElementById('sc-submit-btn');
            var origText = btn.innerHTML;
            btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
            fetch('toggle_courier_default.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ co_id: parseInt(coId), is_default: parseInt(newStatus) })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    showAlert('success', data.message);
                    hideModal('statusChangeModal');
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    showAlert('error', data.message || 'Failed to update courier status');
                    resetCourierDropdown(coId);
                }
            })
            .catch(function(err) {
                console.error('Error:', err);
                showAlert('error', 'An unexpected error occurred');
                resetCourierDropdown(coId);
            })
            .finally(function() { btn.innerHTML = origText; btn.disabled = false; });
        });
    }

    // --- Answer ---
    var ansForm = document.getElementById('answerForm');
    if (ansForm) {
        ansForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var orderId = document.getElementById('ans-order-hdr').textContent;
            var selected = document.querySelector('input[name="ans_status"]:checked');
            if (!selected) { toastManager.error('Please select a call status.'); return; }
            var callLog = selected.value;
            var reasonEl = document.getElementById('ans-notes');
            var reason = reasonEl ? reasonEl.value.trim() : '';
            var btn = document.getElementById('ans-submit-btn');
            var origText = btn.innerHTML;
            btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
            var formData = new FormData();
            formData.append('order_id', orderId);
            formData.append('call_log', callLog);
            formData.append('answer_reason', reason);
            formData.append('action', 'update_call_status');
            var apiBase = getApiBase();
            fetch(apiBase + 'update_call_status.php', { method: 'POST', body: formData })
            .then(function(r) { if (!r.ok) throw new Error('HTTP error! status: ' + r.status); return r.json(); })
            .then(function(data) {
                if (data.success) { var st = (callLog == 1) ? 'Answered' : 'No answer'; showAlert('success', 'Marked as ' + st + '!'); hideModal('answerModal'); setTimeout(function() { location.reload(); }, 1500); }
                else { showAlert('error', data.message || 'Failed to update call status'); }
            })
            .catch(function(err) { console.error('Error:', err); showAlert('error', 'An error occurred while updating call status.'); })
            .finally(function() { btn.innerHTML = origText; btn.disabled = false; });
        });
    }
});

// =============================================
// COURIER STATUS DROPDOWN RESET HELPER
// =============================================
function resetCourierDropdown(coId) {
    var dropdown = document.querySelector('[data-co-id="' + coId + '"]');
    if (dropdown) {
        var orig = dropdown.getAttribute('data-current-status');
        dropdown.value = orig;
    }
}

// =============================================
// ALIAS for my_leads.php compatibility
// =============================================
window.unmarkAsPaid = unmarkPaid;
