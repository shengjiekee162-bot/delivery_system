<?php
// api/debug_map.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/db.php';

try {
    $db = get_db_connection();

    $riders = $db->query("SELECT * FROM riders")->fetchAll(PDO::FETCH_ASSOC);
    $parcels = $db->query("SELECT id, tracking_number, status, assigned_rider_id, delivery_address, latitude, longitude FROM parcels")->fetchAll(PDO::FETCH_ASSOC);
    $locations = $db->query("SELECT * FROM rider_locations ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'debug_status' => 'OK',
        'total_riders_found' => count($riders),
        'total_parcels_found' => count($parcels),
        'riders_table' => $riders,
        'parcels_table' => $parcels,
        'recent_rider_locations' => $locations
    ], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}