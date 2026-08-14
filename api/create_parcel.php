<?php
// api/create_parcel.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/http_client.php';

try {
    $db = get_db_connection();

    $recipient_name  = trim($_POST['recipient_name'] ?? '');
    $recipient_phone = trim($_POST['recipient_phone'] ?? '');
    $delivery_address= trim($_POST['delivery_address'] ?? '');
    $assigned_rider  = !empty($_POST['assigned_rider_id']) ? intval($_POST['assigned_rider_id']) : null;

    if (empty($recipient_name) || empty($delivery_address)) {
        echo json_encode(['status' => 'error', 'message' => 'Please fill in required fields.']);
        exit;
    }

    // 1. Generate unique tracking number
    $tracking_number = 'TRK-' . strtoupper(substr(md5(uniqid()), 0, 8));

    // 2. Geocode real-life address into Lat/Lng coordinates
    $dest_lat = null;
    $dest_lng = null;

    $geoUrl = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'format'       => 'json',
        'limit'        => 1,
        'countrycodes' => 'my',
        'q'            => $delivery_address,
    ]);
    $geoResponse = external_http_get($geoUrl, ['Accept-Language: en'], 15);

    if ($geoResponse['body'] !== '') {
        $geoData = json_decode($geoResponse['body'], true);
        if (!empty($geoData[0]['lat']) && !empty($geoData[0]['lon'])) {
            $dest_lat = (float)$geoData[0]['lat'];
            $dest_lng = (float)$geoData[0]['lon'];
        }
    }

    // Default status when rider is assigned
    $status = $assigned_rider ? 'out_for_delivery' : 'pending';

    // 3. Insert into database
    $stmt = $db->prepare("
        INSERT INTO parcels (tracking_number, recipient_name, recipient_phone, delivery_address, latitude, longitude, status, assigned_rider_id, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$tracking_number, $recipient_name, $recipient_phone, $delivery_address, $dest_lat, $dest_lng, $status, $assigned_rider]);

    echo json_encode([
        'status' => 'success',
        'message' => 'Parcel created successfully!',
        'tracking_number' => $tracking_number
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}