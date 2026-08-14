<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$currentRole = $_SESSION['role'] ?? $_SESSION['user_role'] ?? '';
if ($currentRole !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Admin access required']);
    exit;
}

$lat = filter_input(INPUT_GET, 'lat', FILTER_VALIDATE_FLOAT);
$lng = filter_input(INPUT_GET, 'lng', FILTER_VALIDATE_FLOAT);
if ($lat === false || $lng === false || $lat < 0.8 || $lat > 7.5 || $lng < 98.5 || $lng > 119.5) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'A valid Malaysia location is required']);
    exit;
}

$url = 'https://nominatim.openstreetmap.org/reverse?' . http_build_query([
    'format' => 'jsonv2',
    'lat' => $lat,
    'lon' => $lng,
    'zoom' => 18,
    'addressdetails' => 1,
]);
$response = external_http_get($url, ['Accept-Language: en'], 15);

if ($response['status'] === 429) {
    http_response_code(429);
    echo json_encode(['status' => 'error', 'message' => 'Address service is busy. Please try again shortly.']);
    exit;
}

if ($response['status'] >= 400 || $response['body'] === '') {
    http_response_code(502);
    echo json_encode(['status' => 'error', 'message' => 'Unable to identify the road for this location']);
    exit;
}

$place = json_decode($response['body'], true);
if (!is_array($place)) {
    http_response_code(502);
    echo json_encode(['status' => 'error', 'message' => 'Invalid response from address service']);
    exit;
}

$address = is_array($place['address'] ?? null) ? $place['address'] : [];
$road = trim((string)($address['road'] ?? $address['pedestrian'] ?? $address['residential'] ?? $address['footway'] ?? ''));
$name = $road !== '' ? $road : trim((string)($place['name'] ?? 'Current location'));
$displayAddress = trim((string)($place['display_name'] ?? ''));

echo json_encode([
    'status' => 'success',
    'data' => [
        'name' => $name !== '' ? $name : 'Current location',
        'address' => $displayAddress !== '' ? $displayAddress : $name,
        'road' => $road,
        'lat' => (float)$lat,
        'lng' => (float)$lng,
    ],
], JSON_UNESCAPED_UNICODE);
