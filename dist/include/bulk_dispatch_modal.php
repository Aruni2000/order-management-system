<style>
/* Clean info box - matches system design */
.info-box {
    background: #e8f4fd;
    border: 1px solid #bee5eb;
    border-radius: 8px;
    padding: 14px 18px;
    margin-bottom: 18px;
    color: #0c5460;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 10px;
    line-height: 1.5;
}

.info-box i {
    font-size: 16px;
    flex-shrink: 0;
}

.info-box--warning {
    background: #fff3cd;
    border-color: #ffeaa7;
    color: #856404;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 10px;
    padding: 16px 24px;
    background: #f8f9fa;
    border-top: 1px solid #e5e7eb;
    border-radius: 0 0 12px 12px;
}
</style>

<!-- 2. BULK DISPATCH MODAL - UPDATED WITH DYNAMIC COURIER LOADING -->
<div class="modal-overlay" id="bulkDispatchModal" style="display: none;">
    <div class="modal-container">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="fas fa-truck me-2"></i>Bulk Dispatch Orders
            </h3>
            <button class="modal-close" onclick="closeBulkDispatchModal()" type="button">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form id="bulk-dispatch-form">
            <div class="modal-body">
                <div class="info-box">
                    <i class="fas fa-info-circle"></i>
                    Dispatching these orders will assign tracking numbers and update order statuses.
                </div>

                <!-- Selected Orders Display -->
                <div class="form-group mb-3">
                    <label class="form-label">Selected Orders (<span id="bulkSelectedCount">0</span>)</label>
                    <div class="selected-orders-container" id="selectedOrdersList">
                        <!-- Selected orders will be displayed here -->
                    </div>
                </div>

                <!-- Courier Selection with Dynamic Tenant Filtering -->
                <div class="form-group mb-3">
                    <label for="bulk_carrier" class="form-label">Courier Service <span class="text-danger">*</span></label>
                    <select class="form-control" id="bulk_carrier" name="bulk_carrier" required>
                        <option value="" selected disabled>Loading couriers...</option>
                    </select>
                    <small class="form-text text-muted" id="bulk-courier-help-text">Select orders first to see available couriers</small>
                </div>
                
                <!-- Tracking Numbers Preview -->
                <div class="form-group mb-3">
                    <label class="form-label">Tracking Numbers</label>
                    <div class="tracking-preview" id="bulk_tracking_numbers_display">
                        <div class="info-box" style="margin-bottom: 0;">
                            <i class="fas fa-info-circle"></i>
                            Select a courier to see available tracking numbers
                        </div>
                    </div>
                    <small class="form-text text-muted">Available tracking numbers will be assigned to each order</small>
                </div>
                
                <!-- Bulk Dispatch Notes -->
                <div class="form-group mb-3">
                    <label for="bulk_dispatch_notes" class="form-label">Dispatch Notes</label>
                    <textarea class="form-control" id="bulk_dispatch_notes" name="bulk_dispatch_notes" rows="3" 
                              placeholder="Enter notes that will be applied to all dispatched orders (optional)"></textarea>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="modal-btn modal-btn-secondary" onclick="closeBulkDispatchModal()">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button type="submit" class="modal-btn modal-btn-primary" id="bulk-dispatch-submit-btn" disabled>
                    <i class="fas fa-truck me-1"></i>Confirm Bulk Dispatch
                </button>
            </div>
        </form>
    </div>
</div>