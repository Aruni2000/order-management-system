<?php
session_start(); // Start the session

// Include the database connection file
include($_SERVER['DOCUMENT_ROOT'] . '/orderhub_nextwave/dist/connection/db_connection.php');

// Log logout action
if (isset($_SESSION['user_id'])) {
    try {
        $log_action = 'user_logout';
        $log_details = "User successfully logged out.";
        $log_sql = "INSERT INTO user_logs (user_id, action_type, details, created_at) VALUES (?, ?, ?, NOW())";
        $log_stmt = $conn->prepare($log_sql);
        if ($log_stmt) {
            $log_stmt->bind_param("iss", $_SESSION['user_id'], $log_action, $log_details);
            $log_stmt->execute();
            $log_stmt->close();
        }
    } catch (Exception $e) {
        error_log("Failed to log logout action: " . $e->getMessage());
    }
}

// Unset all session variables
$_SESSION = array();

// If it's desired to kill the session, also delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Clear the "Remember Me" cookie if it exists
if (isset($_COOKIE['email'])) {
    setcookie("email", "", time() - 3600, "/");
}

// Destroy the session
session_destroy();

// Redirect to login page
header("Location: /orderhub_nextwave/dist/pages/login.php");
exit();
?>