<!-- ANSWER STATUS MODAL - Compact Card Style -->
<div class="call-status-backdrop" id="answerStatusModal" style="display: none;">
    <div class="call-status-panel">
        <!-- Top bar with order ID -->
        <div class="call-status-topbar">
            <div class="call-status-topbar-left">
                <i class="fas fa-phone-alt"></i>
                <span>Call Status</span>
            </div>
            <div class="call-status-topbar-right">
                <span class="call-status-order-badge" id="displayOrderId">-</span>
                <button class="call-status-close-btn" onclick="closeAnswerModal()" type="button">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>

        <form id="answer-status-form">
            <input type="hidden" name="order_id" id="answer_order_id">
            <input type="hidden" name="current_call_log" id="current_call_log">
            <input type="hidden" name="new_call_log" id="new_call_log">

            <div class="call-status-body">
                <!-- Status Toggle Cards -->
                <label class="call-status-label">Select Status</label>
                <div class="call-status-toggle-group">
                    <label class="call-status-card call-status-card-answer" for="status_answer">
                        <input class="call-status-radio" type="radio" name="call_status_select" id="status_answer" value="1" required>
                        <div class="call-status-card-icon">
                            <i class="fas fa-phone"></i>
                        </div>
                        <div class="call-status-card-text">
                            <span class="call-status-card-title">Answered</span>
                            <span class="call-status-card-desc">Customer picked up</span>
                        </div>
                    </label>
                    <label class="call-status-card call-status-card-noanswer" for="status_no_answer">
                        <input class="call-status-radio" type="radio" name="call_status_select" id="status_no_answer" value="0" required>
                        <div class="call-status-card-icon">
                            <i class="fas fa-phone-slash"></i>
                        </div>
                        <div class="call-status-card-text">
                            <span class="call-status-card-title">No Answer</span>
                            <span class="call-status-card-desc">No response</span>
                        </div>
                    </label>
                </div>

                <!-- Notes Section -->
                <div class="call-status-notes-section">
                    <label class="call-status-label" id="reasonLabel">Call Notes</label>
                    <textarea class="call-status-textarea" id="answer_reason" name="answer_reason" rows="3"
                              placeholder="Enter call notes or reason..."></textarea>
                    <span class="call-status-hint" id="reasonHelp">Please provide details about the call interaction</span>
                </div>
            </div>

            <!-- Footer -->
            <div class="call-status-footer">
                <button type="button" class="call-status-btn call-status-btn-cancel" onclick="closeAnswerModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="call-status-btn call-status-btn-submit" id="answer-submit-btn">
                    <i class="fas fa-check"></i>
                    <span id="submitButtonText">Update</span>
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* ======================================
   CALL STATUS MODAL - SYSTEM COLOR MATCH
   ====================================== */

.call-status-backdrop {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    animation: csFadeIn 0.2s ease;
}

@keyframes csFadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.call-status-panel {
    background: #ffffff;
    border-radius: 12px;
    width: 92%;
    max-width: 420px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    overflow: hidden;
    animation: csSlideUp 0.25s ease;
}

@keyframes csSlideUp {
    from { opacity: 0; transform: translateY(20px) scale(0.97); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

/* Top Bar */
.call-status-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 18px;
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    color: #fff;
}

.call-status-topbar-left {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    font-size: 15px;
}

.call-status-topbar-left i {
    font-size: 14px;
    opacity: 0.8;
}

.call-status-topbar-right {
    display: flex;
    align-items: center;
    gap: 10px;
}

.call-status-order-badge {
    background: rgba(255, 255, 255, 0.15);
    padding: 3px 10px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.3px;
    color: #fff;
}

.call-status-close-btn {
    background: rgba(255, 255, 255, 0.1);
    border: none;
    color: rgba(255, 255, 255, 0.9);
    width: 30px;
    height: 30px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.15s ease;
    font-size: 13px;
}

.call-status-close-btn:hover {
    background: rgba(255, 255, 255, 0.2);
    color: #fff;
}

/* Body */
.call-status-body {
    padding: 20px 18px;
    background: #f8fafc;
}

.call-status-label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #6c757d;
    margin-bottom: 10px;
}

/* Toggle Cards */
.call-status-toggle-group {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-bottom: 20px;
}

.call-status-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 18px 10px;
    border-radius: 10px;
    border: 2px solid #e2e8f0;
    background: #ffffff;
    cursor: pointer;
    transition: all 0.2s ease;
    text-align: center;
    position: relative;
}

.call-status-card:hover {
    border-color: #007bff;
    background: #f0f7ff;
}

.call-status-card input[type="radio"] {
    display: none;
}

.call-status-card-icon {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    transition: all 0.2s ease;
}

.call-status-card-answer .call-status-card-icon {
    background: #d4edda;
    color: #28a745;
}

.call-status-card-noanswer .call-status-card-icon {
    background: #f8d7da;
    color: #dc3545;
}

.call-status-card-text {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.call-status-card-title {
    font-size: 14px;
    font-weight: 600;
    color: #343a40;
}

.call-status-card-desc {
    font-size: 11px;
    color: #6c757d;
}

/* Selected States */
.call-status-card-answer:has(input:checked) {
    border-color: #28a745;
    background: #d4edda;
    box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.15);
}

.call-status-card-answer:has(input:checked) .call-status-card-icon {
    background: #28a745;
    color: #fff;
    transform: scale(1.05);
}

.call-status-card-noanswer:has(input:checked) {
    border-color: #dc3545;
    background: #f8d7da;
    box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.15);
}

.call-status-card-noanswer:has(input:checked) .call-status-card-icon {
    background: #dc3545;
    color: #fff;
    transform: scale(1.05);
}

/* Notes */
.call-status-notes-section {
    margin-top: 4px;
}

.call-status-textarea {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    font-size: 13.5px;
    line-height: 1.5;
    resize: vertical;
    background: #fafafa;
    color: #495057;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
    font-family: inherit;
    box-sizing: border-box;
}

.call-status-textarea:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
    background: #fff;
}

.call-status-textarea::placeholder {
    color: #adb5bd;
}

.call-status-hint {
    display: block;
    font-size: 12px;
    color: #6c757d;
    margin-top: 6px;
}

/* Footer */
.call-status-footer {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    padding: 14px 18px;
    border-top: 1px solid #e2e8f0;
    background: #f8f9fa;
}

.call-status-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: 4px;
    font-size: 13px;
    font-weight: 500;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
}

.call-status-btn-cancel {
    background: #6c757d;
    color: #fff;
}

.call-status-btn-cancel:hover {
    background: #5a6268;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
}

.call-status-btn-submit {
    background: #007bff;
    color: #fff;
}

.call-status-btn-submit:hover {
    background: #0069d9;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3);
}

.call-status-btn-submit:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

.call-status-btn i {
    font-size: 12px;
}

/* Responsive */
@media (max-width: 480px) {
    .call-status-panel {
        width: 96%;
        max-width: none;
    }

    .call-status-toggle-group {
        gap: 8px;
    }

    .call-status-card {
        padding: 14px 8px;
    }

    .call-status-card-icon {
        width: 38px;
        height: 38px;
        font-size: 16px;
    }
}
</style>
