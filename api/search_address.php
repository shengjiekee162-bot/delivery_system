<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../includes/functions.php';
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

$query = normalize_address_query($_GET['q'] ?? '');
if ($query === '') {
    echo json_encode(['status' => 'success', 'data' => []]);
    exit;
}

$user_lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
$user_lng = isset($_GET['lng']) ? (float)$_GET['lng'] : null;

$has_user_focus = $user_lat !== null
    && $user_lng !== null
    && $user_lat >= 0.8 && $user_lat <= 7.5
    && $user_lng >= 98.5 && $user_lng <= 119.5;

function read_search_cache(string $cacheKey): ?array
{
    $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'delivery_search_' . md5(strtolower($cacheKey)) . '.json';

    if (!is_file($cacheFile)) {
        return null;
    }

    if ((time() - filemtime($cacheFile)) > 3600) {
        return null;
    }

    $payload = json_decode((string)file_get_contents($cacheFile), true);
    return is_array($payload) ? $payload : null;
}

function write_search_cache(string $cacheKey, array $payload): void
{
    $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'delivery_search_' . md5(strtolower($cacheKey)) . '.json';
    file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE));
}

function is_malaysia_coordinate(float $lat, float $lng): bool
{
    return $lat >= 0.8 && $lat <= 7.5 && $lng >= 98.5 && $lng <= 119.5;
}

function nominatim_lookup(string $query, ?float $focusLat = null, ?float $focusLng = null, ?array $region = null): array
{
    $params = [
        'format'         => 'jsonv2',
        'q'              => $query,
        'limit'          => 10,
        'countrycodes'   => 'my',
        'addressdetails' => 1,
    ];

    if ($region) {
        $params['viewbox'] = sprintf(
            '%F,%F,%F,%F',
            $region['min_lng'],
            $region['max_lat'],
            $region['max_lng'],
            $region['min_lat']
        );
        // Keep Penang/KL/etc. as a search preference, not a hard boundary.
        // POI coordinates from public map providers can sit just outside an
        // administrative rectangle, especially for malls and large complexes.
        $params['bounded'] = 0;
    } elseif ($focusLat !== null && $focusLng !== null) {
        $padding = 0.22;
        $params['viewbox'] = sprintf(
            '%F,%F,%F,%F',
            $focusLng - $padding,
            $focusLat + $padding,
            $focusLng + $padding,
            $focusLat - $padding
        );
        $params['bounded'] = 1;
    }

    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query($params);
    $response = external_http_get($url, ['Accept-Language: en'], 15);

    if ($response['body'] === '' && $response['error'] !== '') {
        return ['ok' => false, 'busy' => false, 'places' => []];
    }

    if ($response['status'] === 429) {
        return ['ok' => false, 'busy' => true, 'places' => []];
    }

    if ($response['status'] >= 400) {
        return ['ok' => false, 'busy' => false, 'places' => []];
    }

    $places = json_decode($response['body'], true);

    return [
        'ok'     => true,
        'busy'   => false,
        'places' => is_array($places) ? $places : [],
    ];
}

function places_to_results(array $places): array
{
    $results = [];

    foreach ($places as $place) {
        if (empty($place['lat']) || empty($place['lon'])) {
            continue;
        }

        $lat = (float)$place['lat'];
        $lng = (float)$place['lon'];

        if (!is_malaysia_coordinate($lat, $lng)) {
            continue;
        }

        $displayName = trim((string)($place['display_name'] ?? ''));
        $name = trim((string)($place['name'] ?? ''));

        if ($name === '' && $displayName !== '') {
            $name = explode(',', $displayName)[0];
        }

        if ($name === '') {
            $name = 'Location';
        }

        $results[] = [
            'name'    => $name,
            'address' => $displayName !== '' ? $displayName : 'Address unavailable',
            'lat'     => $lat,
            'lng'     => $lng,
        ];
    }

    return $results;
}

function merge_unique_results(array $existing, array $incoming): array
{
    $seen = [];
    $merged = $existing;

    foreach ($merged as $item) {
        $key = round((float)$item['lat'], 5) . ',' . round((float)$item['lng'], 5);
        $seen[$key] = true;
    }

    foreach ($incoming as $item) {
        $key = round((float)$item['lat'], 5) . ',' . round((float)$item['lng'], 5);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $merged[] = $item;
    }

    return $merged;
}

function search_text_key(string $value): string
{
    $value = strtolower($value);
    return trim((string)(preg_replace('/[^a-z0-9]+/', ' ', $value) ?? ''));
}

function search_relevance_score(array $item, string $query): int
{
    $needle = search_text_key($query);
    $name = search_text_key((string)($item['name'] ?? ''));
    $address = search_text_key((string)($item['address'] ?? ''));

    if ($needle === '') return 0;
    if ($name === $needle) return 1000;
    if (str_contains($name, $needle)) return 900;
    if (str_contains($address, $needle)) return 800;

    $tokens = array_values(array_filter(explode(' ', $needle), fn($token) => strlen($token) > 2));
    $matches = 0;
    foreach ($tokens as $token) {
        if (str_contains($name, $token)) $matches++;
    }

    return $matches === count($tokens) && $matches > 0 ? 700 : $matches * 100;
}

function has_direct_name_match(array $results, string $query): bool
{
    foreach ($results as $item) {
        if (search_relevance_score($item, $query) >= 800) return true;
    }
    return false;
}

function fetch_geocode_results(string $query, ?float $userLat, ?float $userLng): array
{
    $focus = resolve_geocode_focus($query, $userLat, $userLng);
    $region = $focus['region'];
    $effectiveLat = $focus['lat'];
    $effectiveLng = $focus['lng'];
    $allResults = [];

    $allResults = merge_unique_results(
        $allResults,
        ors_geocode_malaysia($query, $effectiveLat, $effectiveLng, 10, $region)
    );

    $nominatim = nominatim_lookup($query, $effectiveLat, $effectiveLng, $region);
    if ($nominatim['ok']) {
        $allResults = merge_unique_results($allResults, places_to_results($nominatim['places']));
    }

    // If providers only return a similarly named place, retry a fuller query
    // (for example, "Gurney Plaza, Malaysia") before presenting the results.
    if (!has_direct_name_match($allResults, $query)) {
        $variants = array_slice(build_address_search_variants($query), 1, 2);
        foreach ($variants as $variant) {
            usleep(1100000); // Respect Nominatim's public rate limit.

            $allResults = merge_unique_results(
                $allResults,
                ors_geocode_malaysia($variant, $effectiveLat, $effectiveLng, 5, $region)
            );

            $variantLookup = nominatim_lookup($variant, $effectiveLat, $effectiveLng, $region);
            if ($variantLookup['ok']) {
                $allResults = merge_unique_results($allResults, places_to_results($variantLookup['places']));
            }

            if (has_direct_name_match($allResults, $query)) {
                break;
            }
        }
    }

    // Do not discard valid Malaysian POIs outside the preferred region box.
    // The region focus above still ranks nearby results first.

    usort($allResults, function (array $a, array $b) use ($query, $effectiveLat, $effectiveLng): int {
        $scoreDiff = search_relevance_score($b, $query) <=> search_relevance_score($a, $query);
        if ($scoreDiff !== 0) return $scoreDiff;
        if ($effectiveLat === null || $effectiveLng === null) return 0;
        return haversine_distance_km($effectiveLat, $effectiveLng, (float)$a['lat'], (float)$a['lng'])
            <=> haversine_distance_km($effectiveLat, $effectiveLng, (float)$b['lat'], (float)$b['lng']);
    });

    return array_slice($allResults, 0, 10);
}

try {
    $addressRegion = detect_address_region($query);
    // Version the key so unsuccessful results made under the former hard
    // regional filter are not served from the one-hour cache.
    $cacheKey = 'v3|' . $query . '|' . ($addressRegion['code'] ?? ($has_user_focus ? round($user_lat, 3) . ',' . round($user_lng, 3) : 'nofocus'));
    $cached = read_search_cache($cacheKey);
    if ($cached !== null) {
        echo json_encode([
            'status' => 'success',
            'cached' => true,
            'data'   => $cached,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $results = fetch_geocode_results(
        $query,
        $has_user_focus ? $user_lat : null,
        $has_user_focus ? $user_lng : null
    );
    write_search_cache($cacheKey, $results);

    echo json_encode([
        'status' => 'success',
        'cached' => false,
        'data'   => $results,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
