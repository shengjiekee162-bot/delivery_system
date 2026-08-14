<?php
// api/get_rider_locations.php
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header('Content-Type: application/json; charset=utf-8');

error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/http_client.php';

$possible_db_paths = [
    __DIR__ . '/../includes/db.php',
    __DIR__ . '/includes/db.php',
    $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php'
];

$db = null;
foreach ($possible_db_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        if (function_exists('get_db_connection')) {
            $db = get_db_connection();
            break;
        }
    }
}

if (!$db) {
    try {
        $db = new PDO("mysql:host=localhost;dbname=delivery_db;charset=utf8mb4", "root", "123qwe", [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'DB Connection Error']);
        exit;
    }
}

/**
 * Geocode Malaysian Addresses ONLY (Strictly restricted to countrycodes=my)
 */
function geocodeAddress($address) {
    if (empty($address)) return null;

    $cleanAddr = preg_replace('/^\d+[\s,]+/', '', $address);
    $queries = [
        $address . ", Malaysia",
        $cleanAddr . ", Malaysia"
    ];

    foreach ($queries as $q) {
        // Enforce countrycodes=my to prevent geocoding to UK/London or elsewhere
        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'format'       => 'json',
            'limit'        => 1,
            'countrycodes' => 'my',
            'q'            => trim($q),
        ]);

        $response = external_http_get($url, ['Accept-Language: en'], 15);

        if ($response['body'] !== '') {
            $data = json_decode($response['body'], true);
            if (!empty($data[0]['lat']) && !empty($data[0]['lon'])) {
                $lat = (float)$data[0]['lat'];
                $lng = (float)$data[0]['lon'];

                // Validate coordinates fall strictly inside Malaysia bounding box
                if ($lat >= 0.8 && $lat <= 7.5 && $lng >= 98.5 && $lng <= 119.5) {
                    return ['lat' => $lat, 'lng' => $lng];
                }
            }
        }
        sleep(1);
    }
    return null;
}

try {
    $sql_riders = "
        SELECT 
            r.id AS rider_id,
            r.user_id,
            COALESCE(u.name, 'Rider') AS rider_name,
            COALESCE(r.phone, '-') AS phone,
            COALESCE(r.vehicle_number, '-') AS vehicle_number,
            rl.latitude AS rider_lat,
            rl.longitude AS rider_lng
        FROM riders r
        LEFT JOIN users u ON r.user_id = u.id
        LEFT JOIN (
            SELECT rl1.* 
            FROM rider_locations rl1
            INNER JOIN (
                SELECT rider_id, MAX(id) AS max_id 
                FROM rider_locations 
                GROUP BY rider_id
            ) rl2 ON rl1.id = rl2.max_id
        ) rl ON r.id = rl.rider_id
    ";

    $stmt = $db->query($sql_riders);
    $riders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response_data = [];

    foreach ($riders as $r) {
        $r_id = $r['rider_id'];
        $u_id = $r['user_id'];

        $rider_lat = (!empty($r['rider_lat']) && (float)$r['rider_lat'] != 0) ? (float)$r['rider_lat'] : 5.3778;
        $rider_lng = (!empty($r['rider_lng']) && (float)$r['rider_lng'] != 0) ? (float)$r['rider_lng'] : 100.3996;

        $sql_parcel = "
            SELECT * FROM parcels 
            WHERE (assigned_rider_id = :r_id OR assigned_rider_id = :u_id)
              AND LOWER(TRIM(status)) NOT IN ('delivered', 'completed', 'cancelled', 'returned')
            ORDER BY id DESC LIMIT 1
        ";
        $p_stmt = $db->prepare($sql_parcel);
        $p_stmt->execute([':r_id' => $r_id, ':u_id' => $u_id]);
        $parcel = $p_stmt->fetch(PDO::FETCH_ASSOC);

        $has_active = !empty($parcel);
        $dest_lat = null;
        $dest_lng = null;

        if ($has_active) {
            $dest_lat = (float)($parcel['latitude'] ?? 0);
            $dest_lng = (float)($parcel['longitude'] ?? 0);
            $address  = trim($parcel['delivery_address'] ?? '');

            // Verify coordinates are strictly inside Malaysia
            $is_valid_malaysia = ($dest_lat >= 0.8 && $dest_lat <= 7.5 && $dest_lng >= 98.5 && $dest_lng <= 119.5);

            if ((!$is_valid_malaysia) && !empty($address)) {
                $coords = geocodeAddress($address);
                if ($coords) {
                    $dest_lat = $coords['lat'];
                    $dest_lng = $coords['lng'];

                    $uStmt = $db->prepare("UPDATE parcels SET latitude = ?, longitude = ? WHERE id = ?");
                    $uStmt->execute([$dest_lat, $dest_lng, $parcel['id']]);
                } else {
                    // Fallback to local default if geocoding fails completely
                    $dest_lat = 5.3700;
                    $dest_lng = 100.4000;
                }
            }
        }

        $valid_active = $has_active && ($dest_lat != 0 && $dest_lng != 0);

        $response_data[] = [
            'rider_id'          => $r_id,
            'rider_name'        => $r['rider_name'],
            'phone'             => $r['phone'],
            'vehicle_number'    => $r['vehicle_number'],
            'rider_lat'         => $rider_lat,
            'rider_lng'         => $rider_lng,
            'has_active_order'  => $valid_active,
            'parcel_id'         => $valid_active ? $parcel['id'] : null,
            'tracking_number'   => $valid_active ? $parcel['tracking_number'] : null,
            'recipient_name'    => $valid_active ? $parcel['recipient_name'] : null,
            'delivery_address'  => $valid_active ? $parcel['delivery_address'] : null,
            'dest_lat'          => $valid_active ? $dest_lat : null,
            'dest_lng'          => $valid_active ? $dest_lng : null
        ];
    }

    echo json_encode([
        'status' => 'success',
        'data'   => $response_data
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}