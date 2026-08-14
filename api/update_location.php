<?php
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

$rider_id = $input['rider_id'] ?? null;
$latitude = $input['latitude'] ?? null;
$longitude = $input['longitude'] ?? null;

if (!$rider_id || !$latitude || !$longitude) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required parameters.']);
    exit;
}

try {
    $db = new PDO("mysql:host=localhost;dbname=delivery_db;charset=utf8mb4", "root", "123qwe");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. Record new GPS ping
    $stmt = $db->prepare("
        INSERT INTO rider_locations (id, rider_id, latitude, longitude, recorded_at) 
        VALUES (UUID(), :rider_id, :lat, :lng, NOW())
    ");
    $stmt->execute([
        ':rider_id' => $rider_id,
        ':lat' => $latitude,
        ':lng' => $longitude
    ]);

    // 2. Mark rider as online & active
    $stmt_rider = $db->prepare("UPDATE riders SET is_online = 1, last_active_at = NOW() WHERE id = :rider_id");
    $stmt_rider->execute([':rider_id' => $rider_id]);

    echo json_encode(['status' => 'success', 'message' => 'Location updated successfully.']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}