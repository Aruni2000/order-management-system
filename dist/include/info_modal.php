<?php
// dist/include/info_modal.php
// Shared info modal component — outputs CSS + JS once, provides renderInfoModal() function.
// Usage:
//   include_once($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/include/info_modal.php');
//   renderInfoModal('How ... Works', 'fas fa-upload', '<h6>...</h6><ul>...</ul>', '500px');

// Output modal CSS only once per page
if (!isset($GLOBALS['_INFO_MODAL_CSS_LOADED'])) {
    $GLOBALS['_INFO_MODAL_CSS_LOADED'] = true;
    ?>
    <style>
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            padding: 20px;
            box-sizing: border-box;
            animation: fadeIn 0.2s ease;
        }
        .modal-container {
            background: #fff;
            border-radius: 12px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            display: flex;
            flex-direction: column;
            min-width: 0;
            animation: slideUp 0.3s ease;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 24px;
            border-bottom: 1px solid #e2e8f0;
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
            border-radius: 12px 12px 0 0;
            min-height: 60px;
            flex-shrink: 0;
        }
        .modal-header .modal-title {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .modal-close {
            background: rgba(255, 255, 255, 0.1);
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: #fff;
            padding: 8px;
            border-radius: 6px;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            flex-shrink: 0;
        }
        .modal-close:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: scale(1.05);
        }
        .modal-body {
            padding: 20px;
            background: #f8fafc;
        }
        .modal-body h6 {
            font-weight: 600;
            margin-bottom: 8px;
            color: #374151;
        }
        .modal-body ul {
            padding-left: 20px;
        }
        .modal-body li {
            margin-bottom: 4px;
        }
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #e2e8f0;
            background: #f8fafc;
            border-radius: 0 0 12px 12px;
            text-align: right;
            flex-shrink: 0;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
    </style>
    <?php
}

// Output modal JS only once per page
if (!isset($GLOBALS['_INFO_MODAL_JS_LOADED'])) {
    $GLOBALS['_INFO_MODAL_JS_LOADED'] = true;
    ?>
    <script>
        function openInfoModal() {
            document.getElementById('infoModal').style.display = 'flex';
            document.body.style.overflow = 'clip';
        }

        function closeInfoModal() {
            document.getElementById('infoModal').style.display = 'none';
            document.body.style.overflow = '';
        }

        document.addEventListener('DOMContentLoaded', function() {
            var infoModal = document.getElementById('infoModal');
            if (infoModal) {
                infoModal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeInfoModal();
                    }
                });
            }
        });
    </script>
    <?php
}

/**
 * Render the info modal HTML.
 *
 * @param string $title     Modal title text (e.g., "How Return Handover CSV Upload Works")
 * @param string $iconClass Font Awesome icon class (e.g., "fas fa-upload")
 * @param string $bodyHtml  Raw HTML for the modal body content
 * @param string $maxWidth  CSS max-width for the modal container (default "500px")
 */
function renderInfoModal($title, $iconClass, $bodyHtml, $maxWidth = '650px') {
    ?>
    <div id="infoModal" class="modal-overlay" style="display: none;">
        <div class="modal-container" style="max-width: <?php echo htmlspecialchars($maxWidth); ?>;">
            <div class="modal-header">
                <h5 class="modal-title"><i class="<?php echo htmlspecialchars($iconClass); ?> mr-2"></i><?php echo htmlspecialchars($title); ?></h5>
                <button class="modal-close" onclick="closeInfoModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <?php echo $bodyHtml; ?>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary" onclick="closeInfoModal()">Got it</button>
            </div>
        </div>
    </div>
    <?php
}
