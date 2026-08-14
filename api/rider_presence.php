<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rider_status.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$role = $_SESSION['role'] ?? $_SESSION['user_role'] ?? '';
if ($role !== 'rider') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Rider access required']);
    exit;
}

$status = strtolower(trim($_POST['status'] ?? $_GET['status'] ?? ''));

if (!in_array($status, ['online', 'offline'], true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid status']);
    exit;
}

try {
    $db = get_db_connection();
    $user_id = (string)$_SESSION['user_id'];

    if ($status === 'online') {
        mark_rider_online($db, $user_id);
    } else {
        mark_rider_offline($db, $user_id);
    }

    echo json_encode([
        'status'    => 'success',
        'is_online' => $status === 'online' ? 1 : 0,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
