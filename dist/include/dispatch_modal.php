<!-- 1. SINGLE DISPATCH MODAL -->
<div class="modal-overlay" id="dispatchOrderModal" style="display: none;">
    <div class="modal-container">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="fas fa-truck me-2"></i>Dispatch Order
            </h3>
            <button class="modal-close" onclick="closeDispatchModal()" type="button">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form id="dispatch-order-form">
            <input type="hidden" name="order_id" id="dispatch_order_id">
            
            <div class="modal-body">
                <div class="alert alert-info mb-3">
                    <i class="fas fa-info-circle me-2"></i>
                    Dispatching this order will assign a tracking number and update the order status.
                </div>

                <div class="form-group mb-3">
                    <label for="carrier" class="form-label">Courier Service <span class="text-danger">*</span></label>
                    <select class="form-control" id="carrier" name="carrier" required>
                
                        <option value="" selected disabled>Select courier service</option>
                        <?php
                        // Get session variables
                        $session_tenant_id = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
                        $is_main_admin = isset($_SESSION['is_main_admin']) ? (int)$_SESSION['is_main_admin'] : 0;
                        
                        // Build query - main admin sees all couriers, regular tenant sees only their couriers
                        if ($is_main_admin === 1) {
                            // Main admin sees all active couriers
                            $courier_query = "SELECT courier_id, courier_name, co_id 
                                             FROM couriers 
                                             WHERE status = 'active' 
                                             ORDER BY courier_name";
                            $stmt = $conn->prepare($courier_query);
                        } else {
                            // Regular tenant sees only their couriers
                            $courier_query = "SELECT courier_id, courier_name, co_id 
                                             FROM couriers 
                                             WHERE status = 'active' 
                                             AND tenant_id = ? 
                                             ORDER BY courier_name";
                            $stmt = $conn->prepare($courier_query);
                            $stmt->bind_param("i", $session_tenant_id);
                        }
                        
                        $stmt->execute();
                        $courier_result = $stmt->get_result();
                        
                        if ($courier_result && $courier_result->num_rows > 0) {
                            while($courier = $courier_result->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $courier['courier_id']; ?>">
                                <?php echo htmlspecialchars($courier['courier_name']); ?> (ID: <?php echo htmlspecialchars($courier['co_id']); ?>)
                            </option>
                        <?php 
                            endwhile;
                            $stmt->close();
                        } else {
                            echo '<option value="" disabled>No couriers available</option>';
                            $stmt->close();
                        }
                        ?>
                    </select>
                    <small class="form-text text-muted">Select the courier service that will deliver this order</small>
                </div>
                
                <div class="form-group mb-3">
                    <label class="form-label">Tracking Number</label>
                    <div class="tracking-preview" id="tracking_number_display">
                        <span class="text-muted">Will be generated when you confirm dispatch</span>
                    </div>
                    <small class="form-text text-muted">An available tracking number will be assigned from the selected courier</small>
                </div>
                
                <div class="form-group mb-3">
                    <label for="dispatch_notes" class="form-label">Dispatch Notes</label>
                    <textarea class="form-control" id="dispatch_notes" name="dispatch_notes" rows="3" 
                              placeholder="Enter additional notes about this dispatch (optional)"></textarea>
                </div>
            </div>
            
            <div class="modal-footer" style="display: flex !important; justify-content: flex-end; padding: 15px; background: #f8f9fa; border-top: 1px solid #ddd;">
                <button type="button" class="modal-btn modal-btn-secondary" onclick="closeDispatchModal()" 
                        style="display: inline-flex !important; padding: 8px 16px; background: #6c757d !important; color: white !important; border: none; border-radius: 4px; margin-right: 10px;">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button type="submit" class="modal-btn modal-btn-primary" id="dispatch-submit-btn" disabled
                        style="display: inline-flex !important; padding: 8px 16px; background: #007bff !important; color: white !important; border: none; border-radius: 4px;">
                    <i class="fas fa-truck me-1"></i>Confirm Dispatch
                </button>
            </div>
        </form>
    </div>
</div>