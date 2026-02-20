<!-- ANSWER STATUS MODAL -->
<div class="modal-overlay" id="answerStatusModal" style="display: none;">
    <div class="modal-container-answer">
        <div class="modal-header">
            <h3 class="modal-title fw-bold mb-2" id="answerModalTitle" style="font-size: 20px;">
    Update Call Status
</h3>

<div style="background: #2f2f30ff; padding: 8px 12px; border-radius: 6px; font-size: 14px;">
    <strong>Order ID:</strong> 
    <span id="displayOrderId" style="color: #007bff; font-weight: 600;">-</span>
</div>
            <button class="modal-close" onclick="closeAnswerModal()" type="button">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form id="answer-status-form">
            <!-- Hidden fields to store order info -->
            <input type="hidden" name="order_id" id="answer_order_id">
            <input type="hidden" name="current_call_log" id="current_call_log">
            <input type="hidden" name="new_call_log" id="new_call_log">
            
            <div class="modal-body pt-2 pb-2">
                
                <!-- Call Status Checkboxes -->
                <div class="mb-6">
                    <label class="form-label mb-1">
                        Select Call Status <span class="text-danger">*</span>
                    </label>
                    <div class="d-flex gap-3 mt-2 ">
                        <div class="form-check">
                            <input class="form-check-input call-status-radio" type="radio" name="call_status_select" id="status_answer" value="1" required>
                            <label class="form-check-label" for="status_answer">
                                Answer
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input call-status-radio" type="radio" name="call_status_select" id="status_no_answer" value="0" required>
                            <label class="form-check-label" for="status_no_answer">
                                No Answer
                            </label>
                        </div>
                    </div>
                </div>
                
                <!-- Reason/Notes textarea -->
                <div class="form-group mb-2">
                    <label for="answer_reason" class="form-label mb-1" id="reasonLabel">
                        Call Notes
                    </label>
                    <textarea class="form-control" id="answer_reason" name="answer_reason" rows="4" 
                              placeholder="Enter call notes or reason..."></textarea>
                    <small class="form-text text-muted" id="reasonHelp">
                        Please provide details about the call interaction
                    </small>
                </div>
            </div>
            
            <div class="modal-footer" style="display: flex !important; justify-content: flex-end; padding: 15px; background: #f8f9fa; border-top: 1px solid #ddd;">
                <button type="button" class="modal-btn modal-btn-secondary" onclick="closeAnswerModal()" 
                        style="display: inline-flex !important; padding: 8px 16px; background: #6c757d !important; color: white !important; border: none; border-radius: 4px; margin-right: 10px;">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button type="submit" class="modal-btn modal-btn-primary" id="answer-submit-btn"
                        style="display: inline-flex !important; padding: 8px 16px; background: #007bff !important; color: white !important; border: none; border-radius: 4px;">
                    <i class="fas fa-check me-1"></i>
                    <span id="submitButtonText">Update Status</span>
                </button>
            </div>
        </form>
    </div>
</div>