<?php
// Start session at the very beginning
// Start session
session_start();

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // If not logged in, redirect to login page
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /order_management/dist/pages/login.php");
    exit();
} else {
    // If logged in, redirect to dashboard
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /order_management/dist/dashboard/index.php");
    exit();
}
?>