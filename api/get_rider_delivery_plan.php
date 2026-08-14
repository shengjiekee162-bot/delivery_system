<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/rider_status.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$current_role = $_SESSION['role'] ?? $_SESSION['user_role'] ?? '';
$riderId = trim($_GET['rider_id'] ?? '');

if ($current_role === 'rider') {
    $db = get_db_connection();
    $rider = ensure_rider_profile($db, (string)$_SESSION['user_id']);
    $riderId = (string)($rider['rider_id'] ?? '');
} elseif ($current_role !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

if ($riderId === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Rider ID is required.']);
    exit;
}

function valid_malaysia_point($lat, $lng): bool {
    return is_numeric($lat) && is_numeric($lng)
        && (float)$lat >= 0.8 && (float)$lat <= 7.5
        && (float)$lng >= 98.5 && (float)$lng <= 119.5;
}

function point_distance(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $r = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 2 * $r * atan2(sqrt($a), sqrt(1 - $a));
}

try {
    $db = get_db_connection();
    $riderStmt = $db->prepare("SELECT r.id, u.name, rl.latitude, rl.longitude
        FROM riders r JOIN users u ON u.id = r.user_id AND u.deleted_at IS NULL
        LEFT JOIN rider_locations rl ON rl.rider_id = r.id AND rl.recorded_at = (SELECT MAX(recorded_at) FROM rider_locations WHERE rider_id = r.id)
        WHERE r.id = :id AND r.deleted_at IS NULL LIMIT 1");
    $riderStmt->execute([':id' => $riderId]);
    $rider = $riderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$rider) throw new RuntimeException('Rider not found.');

    $parcelStmt = $db->prepare("SELECT id, tracking_number, recipient_name, delivery_address, latitude, longitude, status, created_at
        FROM parcels WHERE assigned_rider_id = :id AND status IN ('pending', 'out_for_delivery') AND deleted_at IS NULL
        ORDER BY CASE WHEN status = 'out_for_delivery' THEN 0 ELSE 1 END, created_at ASC");
    $parcelStmt->execute([':id' => $riderId]);
    $allParcels = $parcelStmt->fetchAll(PDO::FETCH_ASSOC);

    $stops = [];
    $unlocated = 0;
    foreach ($allParcels as $parcel) {
        if (!valid_malaysia_point($parcel['latitude'], $parcel['longitude'])) { $unlocated++; continue; }
        $parcel['latitude'] = (float)$parcel['latitude'];
        $parcel['longitude'] = (float)$parcel['longitude'];
        $stops[] = $parcel;
    }

    $hasGps = valid_malaysia_point($rider['latitude'], $rider['longitude']);
    $start = $hasGps ? ['lat' => (float)$rider['latitude'], 'lng' => (float)$rider['longitude']] : null;

    // Nearest-neighbour order makes the displayed plan useful when a rider has several parcels.
    if ($start && count($stops) > 1) {
        $ordered = [];
        $current = $start;
        while ($stops) {
            $nearestIndex = 0;
            $nearestDistance = INF;
            foreach ($stops as $index => $stop) {
                $distance = point_distance($current['lat'], $current['lng'], $stop['latitude'], $stop['longitude']);
                if ($distance < $nearestDistance) { $nearestDistance = $distance; $nearestIndex = $index; }
            }
            $next = $stops[$nearestIndex];
            $next['estimated_straight_km'] = round($nearestDistance, 2);
            $ordered[] = $next;
            $current = ['lat' => $next['latitude'], 'lng' => $next['longitude']];
            array_splice($stops, $nearestIndex, 1);
        }
        $stops = $ordered;
    }

    foreach ($stops as $index => &$stop) $stop['sequence'] = $index + 1;
    unset($stop);

    echo json_encode(['status' => 'success', 'data' => [
        'rider_name' => $rider['name'], 'start' => $start, 'stops' => array_values($stops),
        'unlocated_count' => $unlocated, 'total_active' => count($allParcels),
    ]], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
