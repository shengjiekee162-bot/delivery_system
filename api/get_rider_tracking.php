<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/http_client.php';
require_once __DIR__ . '/../config/services.php';

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

$rider_id  = trim($_GET['rider_id'] ?? '');
$parcel_id = trim($_GET['parcel_id'] ?? '');

if ($rider_id === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Rider ID is required.']);
    exit;
}

function resolve_parcel_destination(array $parcel): array
{
    $dest_lat = isset($parcel['latitude']) ? (float)$parcel['latitude'] : 0.0;
    $dest_lng = isset($parcel['longitude']) ? (float)$parcel['longitude'] : 0.0;

    $valid = $dest_lat >= 0.8 && $dest_lat <= 7.5 && $dest_lng >= 98.5 && $dest_lng <= 119.5;
    if ($valid) {
        return [$dest_lat, $dest_lng];
    }

    $address = normalize_address_query((string)($parcel['delivery_address'] ?? ''));
    if ($address === '') {
        return [null, null];
    }

    $region = detect_address_region($address);
    $focus = resolve_geocode_focus($address, null, null);
    $results = ors_geocode_malaysia($address, $focus['lat'], $focus['lng'], 5, $region);

    if ($region) {
        $results = filter_results_by_address_region($results, $region);
    }

    if (!empty($results)) {
        return [(float)$results[0]['lat'], (float)$results[0]['lng']];
    }

    return [null, null];
}

try {
    $db = get_db_connection();

    $stmtRider = $db->prepare("
        SELECT
            r.id AS rider_id,
            u.name AS rider_name,
            r.phone,
            r.vehicle_number,
            r.is_online,
            r.last_active_at,
            rl.latitude,
            rl.longitude,
            rl.recorded_at
        FROM riders r
        JOIN users u ON u.id = r.user_id AND u.deleted_at IS NULL
        LEFT JOIN (
            SELECT rider_id, MAX(recorded_at) AS max_time
            FROM rider_locations
            GROUP BY rider_id
        ) latest ON latest.rider_id = r.id
        LEFT JOIN rider_locations rl
            ON rl.rider_id = latest.rider_id AND rl.recorded_at = latest.max_time
        WHERE r.id = :rider_id
          AND r.deleted_at IS NULL
        LIMIT 1
    ");
    $stmtRider->execute([':rider_id' => $rider_id]);
    $rider = $stmtRider->fetch(PDO::FETCH_ASSOC);

    if (!$rider) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Rider not found.']);
        exit;
    }

    $parcel = null;
    if ($parcel_id !== '') {
        $stmtParcel = $db->prepare("
            SELECT id, tracking_number, recipient_name, delivery_address, latitude, longitude, status
            FROM parcels
            WHERE id = :parcel_id
              AND assigned_rider_id = :rider_id
              AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmtParcel->execute([
            ':parcel_id' => $parcel_id,
            ':rider_id'  => $rider_id,
        ]);
        $parcel = $stmtParcel->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!$parcel) {
        $stmtActive = $db->prepare("
            SELECT id, tracking_number, recipient_name, delivery_address, latitude, longitude, status
            FROM parcels
            WHERE assigned_rider_id = :rider_id
              AND status IN ('pending', 'out_for_delivery')
              AND deleted_at IS NULL
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmtActive->execute([':rider_id' => $rider_id]);
        $parcel = $stmtActive->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $dest_lat = null;
    $dest_lng = null;
    if ($parcel) {
        list($dest_lat, $dest_lng) = resolve_parcel_destination($parcel);
    }

    $rider_lat = isset($rider['latitude']) ? (float)$rider['latitude'] : null;
    $rider_lng = isset($rider['longitude']) ? (float)$rider['longitude'] : null;

    $has_gps = $rider_lat !== null
        && $rider_lng !== null
        && $rider_lat >= 0.8 && $rider_lat <= 7.5
        && $rider_lng >= 98.5 && $rider_lng <= 119.5;

    echo json_encode([
        'status' => 'success',
        'data'   => [
            'rider_id'        => $rider['rider_id'],
            'rider_name'      => $rider['rider_name'],
            'phone'           => $rider['phone'],
            'vehicle_number'  => $rider['vehicle_number'],
            'is_online'       => (int)$rider['is_online'],
            'last_active_at'  => $rider['last_active_at'],
            'has_gps'         => $has_gps,
            'latitude'        => $has_gps ? $rider_lat : null,
            'longitude'       => $has_gps ? $rider_lng : null,
            'recorded_at'     => $rider['recorded_at'],
            'parcel'          => $parcel ? [
                'id'               => $parcel['id'],
                'tracking_number'  => $parcel['tracking_number'],
                'recipient_name'   => $parcel['recipient_name'],
                'delivery_address' => $parcel['delivery_address'],
                'status'           => $parcel['status'],
                'dest_latitude'    => $dest_lat,
                'dest_longitude'   => $dest_lng,
            ] : null,
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
