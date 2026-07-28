<!-- Required Js -->
<script src="../assets/js/toast.js"></script>
<?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/action_modals.php'); ?>
<script src="../assets/js/swal-actions.js"></script>

<!-- Force consistent backdrop on SweetAlert2 (loads after SWal to override its injected CSS) -->
<style>
html.swal2-shown, body.swal2-shown { overflow: clip !important; padding-right: 0 !important; }
html.swal2-shown { scrollbar-gutter: stable !important; }
.swal2-container.swal2-backdrop-show { background: rgba(0, 0, 0, 0.5) !important; backdrop-filter: blur(4px) !important; -webkit-backdrop-filter: blur(4px) !important; }
</style>

<script src="../assets/js/plugins/simplebar.min.js"></script>
    <script src="../assets/js/plugins/popper.min.js"></script>
    <script src="../assets/js/icon/custom-icon.js"></script>
    <script src="../assets/js/plugins/feather.min.js"></script>
    <script src="../assets/js/component.js"></script>
    <script src="../assets/js/theme.js"></script>
    <script src="../assets/js/script.js"></script>

    <div class="floting-button fixed bottom-[50px] right-[30px] z-[1030]">
    </div>