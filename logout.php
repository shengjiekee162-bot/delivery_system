<?php
// Start session if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include core functions (for log_activity)
require_once __DIR__ . '/includes/functions.php';

// Safely retrieve user details before clearing session
$user_id = $_SESSION['user_id'] ?? null;
$role    = $_SESSION['role'] ?? $_SESSION['user_role'] ?? 'User';

// Log activity if user was logged in
if ($user_id && function_exists('log_activity')) {
    log_activity($user_id, "User Logout ({$role})");
}

if ($user_id && $role === 'rider') {
    require_once __DIR__ . '/includes/functions.php';
    require_once __DIR__ . '/includes/rider_status.php';
    try {
        mark_rider_offline(get_db_connection(), (string)$user_id);
    } catch (Exception $e) {
        error_log('Failed to mark rider offline on logout: ' . $e->getMessage());
    }
}

// Unset all session variables
$_SESSION = array();

// Destroy session cookie if set
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), 
        '', 
        time() - 42000,
        $params["path"], 
        $params["domain"],
        $params["secure"], 
        $params["httponly"]
    );
}

// Destroy session
session_destroy();

// Redirect to login page
header("Location: login.php");
exit;