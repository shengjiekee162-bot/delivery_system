<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$current_role = $_SESSION['role'] ?? $_SESSION['user_role'] ?? '';
if ($current_role !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Admin access required']);
    exit;
}

$rider_id = trim($_GET['rider_id'] ?? '');
$hours = max(1, min(168, (int)($_GET['hours'] ?? 24)));

try {
    $db = get_db_connection();

    $sql = "
        SELECT
            rl.rider_id,
            rl.latitude,
            rl.longitude,
            rl.recorded_at,
            u.name AS rider_name,
            r.vehicle_number
        FROM rider_locations rl
        INNER JOIN riders r ON r.id = rl.rider_id AND r.deleted_at IS NULL
        INNER JOIN users u ON u.id = r.user_id AND u.deleted_at IS NULL
        WHERE rl.recorded_at >= DATE_SUB(NOW(), INTERVAL :hours HOUR)
          AND rl.latitude <> 0
          AND rl.longitude <> 0
    ";

    $params = [':hours' => $hours];

    if ($rider_id !== '') {
        $sql .= " AND rl.rider_id = :rider_id";
        $params[':rider_id'] = $rider_id;
    }

    $sql .= " ORDER BY rl.rider_id ASC, rl.recorded_at ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $routes = [];

    foreach ($rows as $row) {
        $id = $row['rider_id'];

        if (!isset($routes[$id])) {
            $routes[$id] = [
                'rider_id'        => $id,
                'rider_name'      => $row['rider_name'],
                'vehicle_number'  => $row['vehicle_number'],
                'point_count'     => 0,
                'started_at'      => null,
                'ended_at'        => null,
                'points'          => []
            ];
        }

        $lat = (float)$row['latitude'];
        $lng = (float)$row['longitude'];

        if ($lat < 0.8 || $lat > 7.5 || $lng < 98.5 || $lng > 119.5) {
            continue;
        }

        $routes[$id]['points'][] = [
            'lat'         => $lat,
            'lng'         => $lng,
            'recorded_at' => $row['recorded_at']
        ];
        $routes[$id]['point_count']++;

        if ($routes[$id]['started_at'] === null) {
            $routes[$id]['started_at'] = $row['recorded_at'];
        }
        $routes[$id]['ended_at'] = $row['recorded_at'];
    }

    echo json_encode([
        'status' => 'success',
        'hours'  => $hours,
        'data'   => array_values($routes)
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
