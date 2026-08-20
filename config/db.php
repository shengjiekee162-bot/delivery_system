<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'delivery_db');
define('DB_USER', 'root');
define('DB_PASS', '');

function get_db_connection() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("Database connection failure: " . $e->getMessage());
            die(json_encode(['status' => 'error', 'message' => 'Database connection failed.']));
        }
    }
    return $pdo;
}
