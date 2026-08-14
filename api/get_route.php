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
if (!in_array($current_role, ['admin', 'rider'], true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied']);
    exit;
}

$start_lat = isset($_GET['start_lat']) ? (float)$_GET['start_lat'] : 0;
$start_lng = isset($_GET['start_lng']) ? (float)$_GET['start_lng'] : 0;
$end_lat = isset($_GET['end_lat']) ? (float)$_GET['end_lat'] : 0;
$end_lng = isset($_GET['end_lng']) ? (float)$_GET['end_lng'] : 0;

function is_valid_coordinate(float $lat, float $lng): bool
{
    return $lat >= 0.8 && $lat <= 7.5 && $lng >= 98.5 && $lng <= 119.5;
}

if (!is_valid_coordinate($start_lat, $start_lng) || !is_valid_coordinate($end_lat, $end_lng)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid coordinates for routing.']);
    exit;
}

function read_route_cache(float $start_lat, float $start_lng, float $end_lat, float $end_lng): ?array
{
    $key = md5(sprintf('%.5f,%.5f-%.5f,%.5f', $start_lat, $start_lng, $end_lat, $end_lng));
    $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'delivery_route_' . $key . '.json';

    if (!is_file($cacheFile)) {
        return null;
    }

    if ((time() - filemtime($cacheFile)) > 1800) {
        return null;
    }

    $payload = json_decode((string)file_get_contents($cacheFile), true);
    return is_array($payload) ? $payload : null;
}

function write_route_cache(float $start_lat, float $start_lng, float $end_lat, float $end_lng, array $payload): void
{
    $key = md5(sprintf('%.5f,%.5f-%.5f,%.5f', $start_lat, $start_lng, $end_lat, $end_lng));
    $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'delivery_route_' . $key . '.json';
    file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE));
}

function decode_polyline(string $encoded): array
{
    $coordinates = [];
    $index = 0;
    $length = strlen($encoded);
    $lat = 0;
    $lng = 0;

    while ($index < $length) {
        $shift = 0;
        $result = 0;

        do {
            $byte = ord($encoded[$index++]) - 63;
            $result |= ($byte & 0x1f) << $shift;
            $shift += 5;
        } while ($byte >= 0x20);

        $deltaLat = ($result & 1) ? ~($result >> 1) : ($result >> 1);
        $lat += $deltaLat;

        $shift = 0;
        $result = 0;

        do {
            $byte = ord($encoded[$index++]) - 63;
            $result |= ($byte & 0x1f) << $shift;
            $shift += 5;
        } while ($byte >= 0x20);

        $deltaLng = ($result & 1) ? ~($result >> 1) : ($result >> 1);
        $lng += $deltaLng;

        $coordinates[] = [$lat / 1e5, $lng / 1e5];
    }

    return $coordinates;
}

function fetch_osrm_route(float $start_lat, float $start_lng, float $end_lat, float $end_lng): array
{
    $url = sprintf(
        'https://router.project-osrm.org/route/v1/driving/%F,%F;%F,%F?overview=full&geometries=polyline&steps=false',
        $start_lng,
        $start_lat,
        $end_lng,
        $end_lat
    );

    if (!function_exists('external_http_get')) {
        throw new RuntimeException('Routing service unavailable on server.');
    }

    $response = external_http_get($url, [], 12);

    if ($response['body'] === '' && $response['error'] !== '') {
        throw new RuntimeException('Routing request failed: ' . $response['error']);
    }

    if ($response['status'] === 429) {
        throw new RuntimeException('Routing service is busy. Please try again shortly.');
    }

    if ($response['status'] >= 400) {
        throw new RuntimeException('Routing service returned HTTP ' . $response['status']);
    }

    $data = json_decode($response['body'], true);
    if (!is_array($data) || ($data['code'] ?? '') !== 'Ok' || empty($data['routes'][0])) {
        throw new RuntimeException('No driving route found between these points.');
    }

    $route = $data['routes'][0];
    $coordinates = [];

    if (!empty($route['geometry'])) {
        $coordinates = decode_polyline($route['geometry']);
    }

    return [
        'distance_km'  => round(((float)$route['distance']) / 1000, 2),
        'duration_min' => max(1, (int)round(((float)$route['duration']) / 60)),
        'coordinates'  => $coordinates,
    ];
}

try {
    $cached = read_route_cache($start_lat, $start_lng, $end_lat, $end_lng);
    if ($cached !== null) {
        echo json_encode([
            'status' => 'success',
            'cached' => true,
            'data'   => $cached,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $route = fetch_osrm_route($start_lat, $start_lng, $end_lat, $end_lng);
    write_route_cache($start_lat, $start_lng, $end_lat, $end_lng, $route);

    echo json_encode([
        'status' => 'success',
        'cached' => false,
        'data'   => $route,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(502);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
