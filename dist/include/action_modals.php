<style>
/* Action modal body spacing */
.action-modal-body {
  padding: 20px 24px;
  background: #f8fafc;
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
  overflow-x: hidden;
  -webkit-overflow-scrolling: touch;
}
.api-modal > form {
  display: flex;
  flex-direction: column;
  flex: 1 1 auto;
  min-height: 0;
}
.action-modal-body .info-bar {
  margin-bottom: 20px;
  padding: 12px 14px;
  background: #f8f9fa;
  border-radius: 6px;
  border-left: 4px solid #0d6efd;
  font-size: 14px;
}
.action-modal-body .info-bar strong {
  color: #1f2937;
}
.action-modal-body .info-bar .info-sub {
  color: #6c757d;
  font-size: 13px;
  margin-top: 2px;
}

/* Compact form labels matching SweetAlert2 style */
.action-modal-body .form-label-compact {
  display: block;
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: #6c757d;
  margin-bottom: 8px;
}

/* Card-style radio labels */
.card-radio-row {
  display: flex;
  gap: 10px;
  margin-bottom: 18px;
}
.card-radio-label {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 14px 10px;
  border-radius: 10px;
  border: 2px solid #e2e8f0;
  background: #fff;
  cursor: pointer;
  transition: all 0.2s ease;
}
.card-radio-label:hover {
  border-color: #93c5fd;
  background: #f0f7ff;
}
.card-radio-label.selected {
  border-color: #0d6efd;
  background: #f0f7ff;
}
.card-radio-label .radio-icon {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  margin-bottom: 8px;
  background: #e8f5e9;
  color: #28a745;
  transition: all 0.2s ease;
}
.card-radio-label.selected .radio-icon {
  background: #28a745;
  color: #fff;
}
.card-radio-label .radio-title {
  font-weight: 600;
  font-size: 14px;
  color: #343a40;
}
.card-radio-label .radio-sub {
  font-size: 11px;
  color: #6c757d;
  margin-top: 2px;
}

/* Danger card variant */
.card-radio-label.danger-variant .radio-icon {
  background: #fce4ec;
  color: #dc3545;
}
.card-radio-label.danger-variant.selected .radio-icon {
  background: #dc3545;
  color: #fff;
}

/* Condition radio list */
.condition-radio-list {
  margin-top: 6px;
}
.condition-radio-item {
  margin-bottom: 6px;
}
.condition-radio-item label {
  display: flex;
  align-items: center;
  gap: 10px;
  cursor: pointer;
  padding: 10px 14px;
  border-radius: 6px;
  border: 1px solid #dee2e6;
  background: #fff;
  transition: all 0.15s ease;
}
.condition-radio-item label:hover {
  border-color: #93c5fd;
  background: #f0f7ff;
}
.condition-radio-item label.selected {
  border-color: #0d6efd;
  background: #f0f8ff;
}
.condition-radio-item input[type="radio"] {
  accent-color: #0d6efd;
  width: 16px;
  height: 16px;
}
.condition-radio-item .condition-name {
  font-size: 14px;
  color: #1f2937;
}

/* File input styling */
.file-input-wrapper {
  position: relative;
}
.file-input-wrapper input[type="file"] {
  width: 100%;
  padding: 10px 12px;
  border: 2px solid #e5e7eb;
  border-radius: 6px;
  font-size: 13px;
  background: #fff;
  transition: border-color 0.2s ease;
  cursor: pointer;
}
.file-input-wrapper input[type="file"]:hover {
  border-color: #93c5fd;
}
.file-input-wrapper .file-hint {
  font-size: 12px;
  color: #6c757d;
  margin-top: 6px;
}

/* Action modal footer - center cancel/confirm buttons */
.action-modal-body + .api-modal-footer {
  justify-content: center !important;
  padding: 14px 24px !important;
  gap: 16px !important;
  flex-shrink: 0;
}

/* Action modal textarea */
.action-modal-body textarea.form-control {
  width: 100%;
  padding: 10px 12px;
  border: 2px solid #e5e7eb;
  border-radius: 6px;
  font-size: 13.5px;
  font-family: inherit;
  resize: vertical;
  transition: border-color 0.2s ease;
  box-sizing: border-box;
}
.action-modal-body textarea.form-control:focus {
  outline: none;
  border-color: #3b82f6;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}
@media screen and (max-width: 768px) {
  .api-modal-overlay {
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    padding: 12px;
  }

  .api-modal {
    margin: auto;
    max-height: calc(100vh - 24px);
    max-height: calc(100dvh - 24px);
    border-radius: 16px;
  }

  .action-modal-body {
    padding: 16px;
  }

  .action-modal-body + .api-modal-footer {
    flex-shrink: 0;
    padding: 14px 16px !important;
    gap: 10px !important;
  }

  .api-modal-header h4 {
    font-size: 1rem;
  }
}
</style>

<!-- ============================================= -->
<!-- 1. MARK AS PAID MODAL                         -->
<!-- ============================================= -->
<div id="markPaidModal" class="api-modal-overlay" style="display: none;">
  <div class="api-modal">
    <div class="api-modal-header">
      <h4><i class="fas fa-credit-card text-white"></i> <span class="text-white">Mark Order as Paid - #<span id="mp-order-hdr">—</span></span></h4>
      <button type="button" class="close-btn" onclick="closeActionModal('markPaidModal')">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <form id="markPaidForm" enctype="multipart/form-data">
      <div class="action-modal-body">
        <!-- Payment Method cards -->
        <div style="margin-bottom:18px;">
          <label class="form-label-compact">Payment Method <span style="color:#dc3545;">*</span></label>
          <div class="card-radio-row">
            <label id="mp-label-cash" class="card-radio-label selected" data-value="Cash" onclick="selectPaymentMethod(this, 'Cash')">
              <input type="radio" name="mp_method" value="Cash" checked style="display:none;">
              <div class="radio-icon"><i class="fas fa-money-bill-wave"></i></div>
              <span class="radio-title">Cash</span>
            </label>
            <label id="mp-label-bank" class="card-radio-label" data-value="bank_transfer" onclick="selectPaymentMethod(this, 'bank_transfer')">
              <input type="radio" name="mp_method" value="bank_transfer" style="display:none;">
              <div class="radio-icon" style="background:#e8f5e9;color:#28a745;"><i class="fas fa-university"></i></div>
              <span class="radio-title">Bank Transfer</span>
            </label>
          </div>
        </div>

        <!-- File upload -->
        <div style="margin-bottom:4px;">
          <label class="form-label-compact">Payment Slip <span style="color:#6c757d;font-weight:400;text-transform:none;">(optional)</span></label>
          <div class="file-input-wrapper">
            <input type="file" id="mp-payment-slip" name="payment_slip" accept=".jpg,.jpeg,.png,.pdf">
          </div>
          <div class="file-hint"><i class="fas fa-info-circle" style="font-size:11px;margin-right:4px;"></i>JPG, PNG, PDF (Max: 2MB)</div>
        </div>
      </div>

      <div class="api-modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeActionModal('markPaidModal')">
          <i class="fas fa-times"></i> Cancel
        </button>
        <button type="submit" class="btn btn-primary" id="mp-submit-btn">
          <i class="fas fa-check"></i> Mark as Paid
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================= -->
<!-- 2. UNMARK AS PAID MODAL                       -->
<!-- ============================================= -->
<div id="unmarkPaidModal" class="api-modal-overlay" style="display: none;">
  <div class="api-modal">
    <div class="api-modal-header">
      <h4><i class="fas fa-undo text-white"></i> <span class="text-white">Unmark as Paid - #<span id="up-order-hdr">—</span></span></h4>
      <button type="button" class="close-btn" onclick="closeActionModal('unmarkPaidModal')">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <form id="unmarkPaidForm">
      <div class="action-modal-body">
        <div style="text-align:center;padding:16px 0;">
          <i class="fas fa-exclamation-triangle" style="font-size:2.5rem;color:#ffc107;margin-bottom:12px;"></i>
          <div style="font-size:15px;font-weight:600;color:#374151;margin-bottom:6px;">Are you sure?</div>
          <div style="font-size:13px;color:#6c757d;">Unmark this order as paid? The payment record will be removed.</div>
        </div>
      </div>
      <div class="api-modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeActionModal('unmarkPaidModal')">
          <i class="fas fa-times"></i> Cancel
        </button>
        <button type="submit" class="btn btn-primary" id="up-submit-btn" style="background:#dc3545;background:linear-gradient(135deg,#dc3545 0%,#b02a37 100%);">
          <i class="fas fa-undo"></i> Yes, unmark it!
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================= -->
<!-- 3. CANCEL ORDER MODAL                         -->
<!-- ============================================= -->
<div id="cancelOrderModal" class="api-modal-overlay" style="display: none;">
  <div class="api-modal">
    <div class="api-modal-header">
      <h4><i class="fas fa-times-circle text-white"></i> <span class="text-white">Cancel Order - #<span id="co-order-hdr">—</span></span></h4>
      <button type="button" class="close-btn" onclick="closeActionModal('cancelOrderModal')">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <form id="cancelOrderForm">
      <div class="action-modal-body">
        <div style="margin-bottom:4px;">
          <label class="form-label-compact">Cancellation Reason <span style="color:#6c757d;font-weight:400;text-transform:none;">(optional)</span></label>
          <textarea id="co-reason" class="form-control" rows="3" placeholder="Optional: Please provide a reason for cancellation..." style="width:100%;padding:10px 12px;border:2px solid #e5e7eb;border-radius:6px;font-size:13.5px;font-family:inherit;resize:vertical;box-sizing:border-box;"></textarea>
        </div>
      </div>
      <div class="api-modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeActionModal('cancelOrderModal')">
          <i class="fas fa-times"></i> No, keep order
        </button>
        <button type="submit" class="btn btn-primary" id="co-submit-btn" style="background:#dc3545;background:linear-gradient(135deg,#dc3545 0%,#b02a37 100%);">
          <i class="fas fa-check"></i> Yes, cancel it!
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================= -->
<!-- 4. RESTORE ORDER MODAL                        -->
<!-- ============================================= -->
<div id="restoreOrderModal" class="api-modal-overlay" style="display: none;">
  <div class="api-modal">
    <div class="api-modal-header">
      <h4><i class="fas fa-undo-alt text-white"></i> <span class="text-white">Restore Order - #<span id="ro-order-hdr">—</span></span></h4>
      <button type="button" class="close-btn" onclick="closeActionModal('restoreOrderModal')">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <form id="restoreOrderForm">
      <div class="action-modal-body">
        <div style="text-align:center;padding:16px 0;">
          <i class="fas fa-question-circle" style="font-size:2.5rem;color:#0d6efd;margin-bottom:12px;"></i>
          <div style="font-size:15px;font-weight:600;color:#374151;margin-bottom:6px;">Restore Order?</div>
          <div style="font-size:13px;color:#6c757d;">Are you sure you want to restore this cancelled order?</div>
        </div>
      </div>
      <div class="api-modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeActionModal('restoreOrderModal')">
          <i class="fas fa-times"></i> Cancel
        </button>
        <button type="submit" class="btn btn-primary" id="ro-submit-btn" style="background:#17a2b8;background:linear-gradient(135deg,#17a2b8 0%,#138496 100%);">
          <i class="fas fa-check"></i> Yes, restore it!
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================= -->
<!-- 5. UPDATE SUCCESS RATE MODAL                  -->
<!-- ============================================= -->
<div id="conditionModal" class="api-modal-overlay" style="display: none;">
  <div class="api-modal">
    <div class="api-modal-header">
      <h4><i class="fas fa-user-shield text-white"></i> <span class="text-white">Update Success Rate - #<span id="cd-order-hdr">—</span></span></h4>
      <button type="button" class="close-btn" onclick="closeActionModal('conditionModal')">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <form id="conditionForm">
      <div class="action-modal-body">
        <div style="margin-bottom:14px;font-size:13px;color:#6c757d;">Current Rate: <span id="cd-current-name" style="font-weight:600;color:#374151;background:#e9ecef;padding:2px 8px;border-radius:10px;font-size:12px;">—</span></div>
        <div style="margin-bottom:4px;">
          <label class="form-label-compact">Select New Success Rate:</label>
          <div class="condition-radio-list" id="cd-radio-list">
            <!-- Populated by JS -->
          </div>
        </div>
      </div>
      <div class="api-modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeActionModal('conditionModal')">
          <i class="fas fa-times"></i> Cancel
        </button>
        <button type="submit" class="btn btn-primary" id="cd-submit-btn">
          <i class="fas fa-save"></i> Save Changes
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================= -->
<!-- 6. COURIER STATUS CHANGE MODAL                -->
<!-- ============================================= -->
<div id="statusChangeModal" class="api-modal-overlay" style="display: none;">
  <div class="api-modal">
    <div class="api-modal-header">
      <h4><i class="fas fa-exchange-alt text-white"></i> <span class="text-white">Change Courier Status - <span id="sc-courier-hdr">—</span></span></h4>
      <button type="button" class="close-btn" onclick="closeActionModal('statusChangeModal')">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <form id="statusChangeForm">
      <div class="action-modal-body">
        <div style="text-align:center;padding:16px 0;">
          <i class="fas fa-exchange-alt" style="font-size:2.5rem;color:#0d6efd;margin-bottom:12px;"></i>
          <div style="font-size:15px;font-weight:600;color:#374151;margin-bottom:6px;">Change Courier Status?</div>
          <div style="font-size:13px;color:#6c757d;margin-bottom:12px;">Are you sure you want to change the courier status?</div>
          <div style="font-size:13px;color:#6c757d;"><span id="sc-current-status" style="font-weight:600;color:#fff;background:#6c757d;padding:2px 8px;border-radius:10px;font-size:12px;">—</span> <span style="margin:0 4px;">→</span> <span id="sc-new-status" style="font-weight:600;color:#fff;background:#0d6efd;padding:2px 8px;border-radius:10px;font-size:12px;">—</span></div>
        </div>
      </div>
      <div class="api-modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeActionModal('statusChangeModal')">
          <i class="fas fa-times"></i> Cancel
        </button>
        <button type="submit" class="btn btn-primary" id="sc-submit-btn" style="background:#28a745;background:linear-gradient(135deg,#28a745 0%,#1e7e34 100%);">
          <i class="fas fa-check"></i> Yes, change status!
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================= -->
<!-- 7. CALL STATUS (ANSWER/NO ANSWER) MODAL       -->
<!-- ============================================= -->
<div id="answerModal" class="api-modal-overlay" style="display: none;">
  <div class="api-modal">
    <div class="api-modal-header">
      <h4><i class="fas fa-phone-alt text-white"></i> <span class="text-white">Call Status - #<span id="ans-order-hdr">—</span></span></h4>
      <button type="button" class="close-btn" onclick="closeActionModal('answerModal')">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <form id="answerForm">
      <div class="action-modal-body">
        <!-- Status selection cards -->
        <div style="margin-bottom:18px;">
          <label class="form-label-compact">Select Status</label>
          <div class="card-radio-row">
            <label id="ans-label-answer" class="card-radio-label selected" data-value="1" onclick="selectAnswerStatus(this, '1')">
              <input type="radio" name="ans_status" value="1" checked style="display:none;">
              <div class="radio-icon"><i class="fas fa-phone"></i></div>
              <span class="radio-title">Answered</span>
              <span class="radio-sub">Customer picked up</span>
            </label>
            <label id="ans-label-noanswer" class="card-radio-label danger-variant" data-value="0" onclick="selectAnswerStatus(this, '0')">
              <input type="radio" name="ans_status" value="0" style="display:none;">
              <div class="radio-icon" style="background:#fce4ec;color:#dc3545;"><i class="fas fa-phone-slash"></i></div>
              <span class="radio-title">No Answer</span>
              <span class="radio-sub">No response</span>
            </label>
          </div>
        </div>

        <!-- Notes textarea -->
        <div style="margin-bottom:4px;">
          <label id="ans-notes-label" class="form-label-compact">ANSWER NOTES</label>
          <textarea id="ans-notes" class="form-control" rows="3" placeholder="Enter details about customer conversation..." style="width:100%;padding:10px 12px;border:2px solid #e5e7eb;border-radius:6px;font-size:13.5px;font-family:inherit;resize:vertical;box-sizing:border-box;"></textarea>
          <div id="ans-notes-help" class="file-hint"><i class="fas fa-info-circle" style="font-size:11px;margin-right:4px;"></i>Please provide details about the customer conversation</div>
        </div>
      </div>
      <div class="api-modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeActionModal('answerModal')">
          <i class="fas fa-times"></i> Cancel
        </button>
        <button type="submit" class="btn btn-primary" id="ans-submit-btn">
          <i class="fas fa-check"></i> Update
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================= -->
<!-- 8. ORDER CREATED SUCCESS MODAL                -->
<!-- ============================================= -->
<div id="orderSuccessModal" class="api-modal-overlay" style="display: none;">
  <div class="api-modal" style="max-width: 480px;">
    <div class="api-modal-header" style="background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);">
      <h4>
        <i class="fas fa-check-circle text-white"></i>
        <span class="text-white">Order Created Successfully</span>
      </h4>
      <button type="button" class="close-btn" onclick="dismissOrderSuccessModal()">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <div class="action-modal-body" style="display: flex; flex-direction: column; align-items: center; text-align: center; padding: 35px 25px;">
      <div style="font-size: 3rem; color: #28a745; margin-bottom: 12px;">
        <i class="fas fa-check-circle"></i>
      </div>
      <p style="font-size: 1.05rem; color: #495057; margin: 0 0 6px;">Your order has been created successfully.</p>
      <p style="font-size: 0.95rem; color: #6c757d; margin: 0 0 22px;">Order ID: <strong id="successOrderId" style="color: #1565C0;">-</strong></p>
    </div>
    <div class="api-modal-footer" style="flex-wrap: wrap;">
      <button type="button" class="btn btn-primary" onclick="viewCreatedOrder()">
        <i class="fas fa-print"></i> View / Print Order
      </button>
      <button type="button" class="btn btn-secondary" onclick="createAnotherOrder()">
        <i class="fas fa-plus"></i> Create Another
      </button>
      <button type="button" class="btn btn-secondary" onclick="goToAllOrders()" style="background: #6c757d;">
        <i class="fas fa-list"></i> Done
      </button>
    </div>
  </div>
</div>

<!-- ============================================= -->
<!-- 9. COURIER API CONFIGURATION MODAL            -->
<!-- ============================================= -->
<div id="apiModal" class="api-modal-overlay" style="display: none;">
  <div class="api-modal">
    <div class="api-modal-header">
      <h4>
        <i class="fas fa-cog text-white"></i>
        <span id="modalTitle" class="text-white">Configure API Settings</span>
      </h4>
      <button type="button" class="close-btn" onclick="closeApiModal()">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <form id="apiSettingsForm" method="POST">
      <div class="action-modal-body">
        <input type="hidden" id="courier_id" name="co_id" value="">
        <input type="hidden" name="csrf_token" value="demo_token">

        <div class="api-form-row" style="display: flex; gap: 20px;">
          <div class="form-group" style="flex: 1;">
            <label for="client_id" class="form-label">
              <i class="fas fa-id-badge"></i>
              Client ID
            </label>
            <input type="text" class="form-control" id="client_id" name="client_id" placeholder="Enter your Client ID">
            <div class="error-feedback" id="client_id-error"></div>
            <div class="form-hint">
              <i class="fas fa-info-circle"></i>
              Unique identifier provided by the courier service
            </div>
          </div>

          <div class="form-group" style="flex: 1;">
            <label for="api_key" class="form-label">
              <i class="fas fa-key"></i>
              API Key<span class="required">*</span>
            </label>
            <div class="password-input-group">
              <input type="password" class="form-control" id="api_key" name="api_key" placeholder="Enter your API Key" required>
              <button type="button" class="password-toggle" id="toggleApiKey">
                <i class="fas fa-eye"></i>
              </button>
            </div>
            <div class="error-feedback" id="api_key-error"></div>
            <div class="form-hint">
              <i class="fas fa-shield-alt"></i>
              Secret key for API authentication - keep this secure
            </div>
          </div>
        </div>

        <div id="originFields" style="display: none; margin-top: 20px;">
          <div class="api-form-row" style="display: flex; gap: 20px;">
            <div class="form-group" style="flex: 1;">
              <label for="origin_city_name" class="form-label">
                <i class="fas fa-city"></i>
                Origin City
              </label>
              <input type="text" class="form-control" id="origin_city_name" name="origin_city_name" placeholder="Enter origin city">
              <div class="error-feedback" id="origin_city_error"></div>
              <div class="form-hint">
                <i class="fas fa-info-circle"></i>
                Enter the courier's main city of origin or pickup location
              </div>
            </div>

            <div class="form-group" style="flex: 1;">
              <label for="origin_state_name" class="form-label">
                <i class="fas fa-map-marked-alt"></i>
                Origin State
              </label>
              <input type="text" class="form-control" id="origin_state_name" name="origin_state_name" placeholder="Enter origin state">
              <div class="error-feedback" id="origin_state_error"></div>
              <div class="form-hint">
                <i class="fas fa-info-circle"></i>
                Enter the courier's origin state or region name
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="api-modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeApiModal()">
          <i class="fas fa-times"></i>
          Cancel
        </button>
        <button type="submit" class="btn btn-primary" id="saveApiBtn">
          <i class="fas fa-save"></i>
          Save API Settings
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================= -->
<!-- 10. DOWNLOAD WAYBILLS MODAL                   -->
<!-- ============================================= -->
<div id="waybillsModal" class="api-modal-overlay" style="display: none;">
  <div class="api-modal">
    <div class="api-modal-header">
      <h4>
        <i class="fas fa-download text-white"></i>
        <span id="waybillsModalTitle" class="text-white">Download Waybills - Koombiyo</span>
      </h4>
      <button type="button" class="close-btn" onclick="closeWaybillsModal()">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <form id="waybillsDownloadForm" method="POST" action="/OMS/dist/api/koombiyo_get_waybills.php">
      <div class="action-modal-body">
        <input type="hidden" id="waybills_courier_id" name="courier_id" value="">
        <input type="hidden" name="csrf_token" value="demo_token">

        <div class="form-group">
          <label for="waybills_count" class="form-label">
            <i class="fas fa-sort-numeric-up"></i>
            Number of Waybills
          </label>
          <input type="number" class="form-control" id="waybills_count" name="waybills_count" placeholder="Enter number of waybills" min="1" max="100" step="1" required>
          <div class="error-feedback" id="waybills_count-error"></div>
          <div class="form-hint">
            <i class="fas fa-info-circle"></i>
            Maximum waybills count: 100 (Enter value between 1-100)
          </div>
        </div>
      </div>

      <div class="api-modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeWaybillsModal()">
          <i class="fas fa-times"></i>
          Cancel
        </button>
        <button type="submit" class="btn btn-primary" id="downloadWaybillsBtn">
          <i class="fas fa-download"></i>
          Download Waybills
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================= -->
<!-- 11. RETURN FEE MODAL                          -->
<!-- ============================================= -->
<div id="returnFeeModal" class="api-modal-overlay" style="display: none;">
  <div class="api-modal">
    <div class="api-modal-header">
      <h4>
        <i class="fas fa-dollar-sign text-white"></i>
        <span id="returnFeeModalTitle" class="text-white">Set Return Fee</span>
      </h4>
      <button type="button" class="close-btn" onclick="closeReturnFeeModal()">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <form id="returnFeeForm" method="POST" action="update_return_fee.php">
      <div class="action-modal-body">
        <input type="hidden" id="returnFeeCourierId" name="co_id" value="">
        <input type="hidden" name="csrf_token" value="demo_token">

        <div class="form-group">
          <label for="returnFeeValue" class="form-label">
            <i class="fas fa-percentage"></i>
            Return Fee Value
          </label>
          <input type="number" class="form-control" id="returnFeeValue" name="return_fee_value" placeholder="Enter return fee (0 = no fee)" step="0.01" min="0" required>
          <div class="error-feedback" id="return_fee_value-error"></div>
          <div class="form-hint">
            <i class="fas fa-info-circle"></i>
            Enter a percentage. Enter 0 for no fee.
          </div>
        </div>
      </div>

      <div class="api-modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeReturnFeeModal()">
          <i class="fas fa-times"></i> Cancel
        </button>
        <button type="submit" class="btn btn-primary" id="saveReturnFeeBtn">
          <i class="fas fa-save"></i> Save Fee
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================= -->
<!-- GLOBAL MODAL CLOSE HELPER                     -->
<!-- ============================================= -->
<script>
// Global function to close any api-modal-overlay (unique name to avoid conflict with page-level closeModal)
function closeActionModal(modalId) {
  var el = document.getElementById(modalId);
  if (el) {
    el.style.display = 'none';
    document.body.style.overflow = '';
    if (modalId === 'statusChangeModal') {
      var scForm = document.getElementById('statusChangeForm');
      if (scForm && typeof resetCourierDropdown === 'function') {
        resetCourierDropdown(scForm.getAttribute('data-co-id'));
      }
    }
  }
}

// Click overlay to close
document.addEventListener('click', function(e) {
  if (e.target.classList.contains('api-modal-overlay') && e.target.style.display === 'flex') {
    var overlayId = e.target.id;
    e.target.style.display = 'none';
    document.body.style.overflow = '';
    if (overlayId === 'statusChangeModal') {
      var scForm = document.getElementById('statusChangeForm');
      if (scForm && typeof resetCourierDropdown === 'function') {
        resetCourierDropdown(scForm.getAttribute('data-co-id'));
      }
    }
  }
});

// Escape key to close
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    var overlays = document.querySelectorAll('.api-modal-overlay');
    overlays.forEach(function(el) {
      if (el.style.display === 'flex') {
        var overlayId = el.id;
        el.style.display = 'none';
    document.body.style.overflow = '';
        if (overlayId === 'statusChangeModal') {
          var scForm = document.getElementById('statusChangeForm');
          if (scForm && typeof resetCourierDropdown === 'function') {
            resetCourierDropdown(scForm.getAttribute('data-co-id'));
          }
        }
      }
    });
  }
});
</script>
