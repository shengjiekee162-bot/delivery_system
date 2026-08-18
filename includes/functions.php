<?php
// Start session if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/http_client.php';

/**
 * Get Database Connection (PDO Singleton)
 */
if (!function_exists('get_db_connection')) {
    function get_db_connection() {
        static $db = null;

        if ($db === null) {
            $host     = 'localhost';
            $dbname   = 'synergy1_keeshenjie_delivery_system';
            $username = 'synergy1_shaoxi';
            $password = 'p07e&61#5e9^c]Y}'; // Your MySQL Password

            try {
                $db = new PDO("mysql:host={$host};dbname={$dbname};charset=utf8mb4", $username, $password);
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                die("Database Connection Failed: " . $e->getMessage());
            }
        }

        return $db;
    }
}

/**
 * Generate UUID v4 String (CHAR 36)
 */
if (!function_exists('generate_uuid')) {
    function generate_uuid() {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

/**
 * Log System Activity
 * Wrapped in try-catch to prevent missing table errors from breaking app flow
 */
if (!function_exists('log_activity')) {
    function log_activity($user_id, $action) {
        try {
            $db = get_db_connection();

            $stmt = $db->prepare("
                INSERT INTO activity_logs (id, user_id, action, ip_address, user_agent)
                VALUES (:id, :user_id, :action, :ip_address, :user_agent)
            ");

            $stmt->execute([
                ':id'         => generate_uuid(),
                ':user_id'    => $user_id,
                ':action'     => $action,
                ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (PDOException $e) {
            // Silently log error to prevent interrupting workflow
            error_log("Failed to insert into activity_logs: " . $e->getMessage());
        }
    }
}

/**
 * Require User Authorization / Role Protection
 */
if (!function_exists('require_role')) {
    function require_role($role) {
        if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== $role) {
            header("Location: ../login.php");
            exit;
        }
    }
}

/**
 * HTML Escaping Helper Function
 */
if (!function_exists('sanitize')) {
    function sanitize($text) {
        return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
    }
}