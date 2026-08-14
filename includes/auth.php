<?php
// Start session if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if the user is authenticated (logged in)
 */
if (!function_exists('check_auth')) {
    function check_auth() {
        if (!isset($_SESSION['user_id'])) {
            header("Location: ../login.php");
            exit;
        }
    }
}

/**
 * Require User Authorization / Role Protection
 */
if (!function_exists('require_role')) {
    function require_role($required_role) {
        check_auth();

        // Safely check both 'role' and 'user_role' session keys
        $current_role = $_SESSION['role'] ?? $_SESSION['user_role'] ?? '';

        if ($current_role !== $required_role) {
            http_response_code(403);
            die("Access Denied: You do not have permission to view this resource.");
        }
    }
}