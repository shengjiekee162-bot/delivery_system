<?php

if (!function_exists('resolve_ca_bundle_path')) {
    function resolve_ca_bundle_path(): ?string
    {
        $candidates = [
            __DIR__ . '/../config/cacert.pem',
            ini_get('curl.cainfo') ?: null,
            ini_get('openssl.cafile') ?: null,
            'C:/xampp/php/extras/ssl/cacert.pem',
            'C:/xampp/apache/bin/curl-ca-bundle.crt',
        ];

        foreach ($candidates as $path) {
            if (is_string($path) && $path !== '' && is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}

if (!function_exists('apply_curl_ssl_options')) {
    function apply_curl_ssl_options($ch): void
    {
        $caBundle = resolve_ca_bundle_path();

        if ($caBundle) {
            curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            return;
        }

        // Common XAMPP/Windows local setup: PHP has no CA bundle configured.
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }
}

if (!function_exists('external_http_get')) {
    /**
     * Perform an HTTPS GET request with SSL settings compatible with local XAMPP.
     *
     * @return array{status:int,body:string,error:string}
     */
    function external_http_get(string $url, array $headers = [], int $timeout = 15): array
    {
        if (!function_exists('curl_init')) {
            return [
                'status' => 0,
                'body'   => '',
                'error'  => 'cURL extension is not enabled in PHP.',
            ];
        }

        $ch = curl_init($url);
        $headerLines = array_merge([
            'User-Agent: CourierDispatchApp/1.0 (delivery-system)',
        ], $headers);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        apply_curl_ssl_options($ch);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'status' => $status,
            'body'   => $body === false ? '' : (string)$body,
            'error'  => $error,
        ];
    }
}

if (!function_exists('normalize_address_query')) {
    function normalize_address_query(string $query): string
    {
        $query = str_replace(["\r\n", "\r", "\n", "\t"], ', ', $query);
        $query = preg_replace('/\s+/', ' ', $query) ?? $query;
        $query = preg_replace('/,\s*,+/', ', ', $query) ?? $query;

        return trim($query, " ,");
    }
}

if (!function_exists('build_address_search_variants')) {
    function build_address_search_variants(string $query): array
    {
        $normalized = normalize_address_query($query);
        if ($normalized === '') {
            return [];
        }

        $variants = [
            $normalized,
            $normalized . ', Malaysia',
        ];

        $withoutUnit = preg_replace('/^[A-Za-z0-9\-\/]+\s+/', '', $normalized) ?? $normalized;
        if ($withoutUnit !== $normalized && $withoutUnit !== '') {
            $variants[] = $withoutUnit;
            $variants[] = $withoutUnit . ', Malaysia';
        }

        if (preg_match('/(\d{5})/', $normalized, $postalMatch)) {
            $postal = $postalMatch[1];
            $variants[] = $postal . ', Pulau Pinang, Malaysia';
            $variants[] = $postal . ', Malaysia';
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $normalized))));
        if (count($parts) > 2) {
            $variants[] = implode(', ', array_slice($parts, 1));
            $variants[] = implode(', ', array_slice($parts, -3));
        }

        $city = '';
        $state = '';
        $postal = '';
        $street = '';

        foreach ($parts as $part) {
            if (preg_match('/(\d{5})\s*(.*)/', $part, $postalMatch)) {
                $postal = $postalMatch[1];
                $cityCandidate = trim($postalMatch[2]);
                if ($cityCandidate !== '') {
                    $city = $cityCandidate;
                }
            } elseif (preg_match('/\b(jalan|jln|lorong|persiaran|lebuh|taman|kampung)\b/i', $part)) {
                $street = trim(preg_replace('/^\d+\s+/', '', $part) ?? $part);
            } elseif (preg_match('/pulau pinang|\bpenang\b|\bpng\b|selangor|\bjohor\b|sabah|sarawak|melaka|negeri sembilan|pahang|kedah|perlis|kelantan|terengganu|putrajaya|labuan|kuala lumpur|wilayah persekutuan/i', $part)) {
                $state = $part;
            }
        }

        if ($city !== '') {
            $stateLabel = 'Penang';
            if ($state !== '') {
                if (preg_match('/selangor/i', $state)) {
                    $stateLabel = 'Selangor';
                } elseif (preg_match('/johor/i', $state)) {
                    $stateLabel = 'Johor';
                } elseif (preg_match('/kuala lumpur|wilayah persekutuan/i', $state)) {
                    $stateLabel = 'Kuala Lumpur';
                } elseif (preg_match('/pulau pinang|\bpenang\b|\bpng\b/i', $state)) {
                    $stateLabel = 'Penang';
                }
            } elseif (preg_match('/seberang|george town|georgetown|bayan lepas|bukit mertajam|gelugor|balik pulau|perai|pinang/i', $city)) {
                $stateLabel = 'Penang';
            }

            $variants[] = $city . ', ' . $stateLabel . ', Malaysia';
        }

        if ($street !== '' && $city !== '') {
            $variants[] = $street . ', ' . $city . ', Malaysia';
            if ($postal !== '') {
                $variants[] = $street . ', ' . $city . ', ' . $postal . ', Malaysia';
            }
        }

        if ($city !== '' && $state !== '') {
            $variants[] = $city . ', ' . $state . ', Malaysia';
            if ($postal !== '') {
                $variants[] = $city . ', ' . $state . ', ' . $postal . ', Malaysia';
            }
        }

        if ($city !== '' && $postal !== '') {
            $variants[] = $city . ', ' . $postal . ', Malaysia';
        }

        if (count($parts) >= 1) {
            $variants[] = end($parts) . ', Malaysia';
        }

        $unique = [];
        foreach ($variants as $variant) {
            $variant = normalize_address_query($variant);
            if ($variant !== '' && !in_array($variant, $unique, true)) {
                $unique[] = $variant;
            }
        }

        return array_slice($unique, 0, 8);
    }
}

if (!function_exists('detect_address_region')) {
    function detect_address_region(string $query): ?array
    {
        $text = strtolower(normalize_address_query($query));

        $regions = [
            'penang' => [
                'label'         => 'Penang',
                'keywords'      => [
                    'penang', 'pulau pinang', ' png', 'png,', ',png',
                    'seberang jaya', 'seberang perai', 'george town', 'georgetown',
                    'bayan lepas', 'bukit mertajam', 'gelugor', 'balik pulau', 'perai',
                    'nibong tebal', 'butterworth', 'tanjung tokong', 'air itam',
                ],
                'postal_prefix' => ['11', '12', '13', '14'],
                'min_lat'       => 5.12,
                'max_lat'       => 5.52,
                'min_lng'       => 100.12,
                'max_lng'       => 100.55,
                'focus_lat'     => 5.357,
                'focus_lng'     => 100.301,
            ],
            'kl' => [
                'label'         => 'Kuala Lumpur',
                'keywords'      => ['kuala lumpur', 'wilayah persekutuan', 'bukit bintang', 'cheras', 'kepong', ' ampang'],
                'postal_prefix' => ['50', '51', '52', '53', '54', '55', '56', '57', '58', '59', '60'],
                'min_lat'       => 2.95,
                'max_lat'       => 3.35,
                'min_lng'       => 101.55,
                'max_lng'       => 101.85,
                'focus_lat'     => 3.139,
                'focus_lng'     => 101.687,
            ],
            'selangor' => [
                'label'         => 'Selangor',
                'keywords'      => ['selangor', 'petaling jaya', 'shah alam', 'subang', 'puchong', 'klang', 'sunway'],
                'postal_prefix' => ['40', '41', '42', '43', '44', '45', '46', '47', '48'],
                'min_lat'       => 2.75,
                'max_lat'       => 3.85,
                'min_lng'       => 100.85,
                'max_lng'       => 101.95,
                'focus_lat'     => 3.073,
                'focus_lng'     => 101.607,
            ],
            'johor' => [
                'label'         => 'Johor',
                'keywords'      => ['johor', 'johor bahru', 'jb ', ' jb', 'skudai', 'iskandar'],
                'postal_prefix' => ['79', '80', '81', '82', '83', '84', '85', '86'],
                'min_lat'       => 1.20,
                'max_lat'       => 2.85,
                'min_lng'       => 102.55,
                'max_lng'       => 104.55,
                'focus_lat'     => 1.492,
                'focus_lng'     => 103.741,
            ],
        ];

        foreach ($regions as $code => $region) {
            foreach ($region['keywords'] as $keyword) {
                if (str_contains($text, $keyword)) {
                    return array_merge(['code' => $code], $region);
                }
            }
        }

        if (preg_match('/\b(\d{5})\b/', $text, $match)) {
            $prefix = substr($match[1], 0, 2);
            foreach ($regions as $code => $region) {
                if (in_array($prefix, $region['postal_prefix'], true)) {
                    return array_merge(['code' => $code], $region);
                }
            }
        }

        return null;
    }
}

if (!function_exists('coordinate_in_region')) {
    function coordinate_in_region(float $lat, float $lng, array $region): bool
    {
        return $lat >= $region['min_lat']
            && $lat <= $region['max_lat']
            && $lng >= $region['min_lng']
            && $lng <= $region['max_lng'];
    }
}

if (!function_exists('filter_results_by_address_region')) {
    function filter_results_by_address_region(array $results, array $region): array
    {
        $filtered = array_values(array_filter($results, function ($item) use ($region) {
            return coordinate_in_region((float)$item['lat'], (float)$item['lng'], $region);
        }));

        return $filtered;
    }
}

if (!function_exists('resolve_geocode_focus')) {
    function resolve_geocode_focus(string $query, ?float $userLat, ?float $userLng): array
    {
        $region = detect_address_region($query);

        if ($region) {
            return [
                'lat'    => (float)$region['focus_lat'],
                'lng'    => (float)$region['focus_lng'],
                'region' => $region,
                'source' => 'address',
            ];
        }

        if ($userLat !== null && $userLng !== null) {
            return [
                'lat'    => $userLat,
                'lng'    => $userLng,
                'region' => null,
                'source' => 'user',
            ];
        }

        return [
            'lat'    => null,
            'lng'    => null,
            'region' => null,
            'source' => 'none',
        ];
    }
}

if (!function_exists('haversine_distance_km')) {
    function haversine_distance_km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $earthRadius * atan2(sqrt($a), sqrt(1 - $a));
    }
}

if (!function_exists('sort_results_by_distance')) {
    function sort_results_by_distance(array $results, float $lat, float $lng): array
    {
        usort($results, function ($a, $b) use ($lat, $lng) {
            $distA = haversine_distance_km($lat, $lng, (float)$a['lat'], (float)$a['lng']);
            $distB = haversine_distance_km($lat, $lng, (float)$b['lat'], (float)$b['lng']);

            return $distA <=> $distB;
        });

        return $results;
    }
}

if (!function_exists('filter_results_by_radius')) {
    function filter_results_by_radius(array $results, float $lat, float $lng, float $radiusKm): array
    {
        return array_values(array_filter($results, function ($item) use ($lat, $lng, $radiusKm) {
            return haversine_distance_km($lat, $lng, (float)$item['lat'], (float)$item['lng']) <= $radiusKm;
        }));
    }
}

if (!function_exists('ors_geocode_malaysia')) {
    function ors_geocode_malaysia(string $query, ?float $focusLat = null, ?float $focusLng = null, int $size = 10, ?array $region = null): array
    {
        if (!defined('ORS_API_KEY') || ORS_API_KEY === '') {
            return [];
        }

        $params = [
            'api_key'          => ORS_API_KEY,
            'text'             => normalize_address_query($query),
            'boundary.country' => 'MY',
            'size'             => max(1, min(15, $size)),
        ];

        if ($region) {
            // A regional address should bias results to that region without
            // rejecting POIs whose provider coordinates fall just over its edge.
            $params['focus.point.lat'] = $region['focus_lat'];
            $params['focus.point.lon'] = $region['focus_lng'];
        } elseif ($focusLat !== null && $focusLng !== null) {
            $params['focus.point.lat'] = $focusLat;
            $params['focus.point.lon'] = $focusLng;
        }

        $url = 'https://api.openrouteservice.org/geocode/search?' . http_build_query($params);
        $response = external_http_get($url, ['Accept: application/json'], 12);

        if ($response['status'] >= 400 || $response['body'] === '') {
            return [];
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || empty($data['features'])) {
            return [];
        }

        $results = [];

        foreach ($data['features'] as $feature) {
            $coords = $feature['geometry']['coordinates'] ?? null;
            if (!is_array($coords) || count($coords) < 2) {
                continue;
            }

            $lng = (float)$coords[0];
            $lat = (float)$coords[1];

            if ($lat < 0.8 || $lat > 7.5 || $lng < 98.5 || $lng > 119.5) {
                continue;
            }

            $label = trim((string)($feature['properties']['label'] ?? ''));
            $name = trim((string)($feature['properties']['name'] ?? ''));

            if ($name === '' && $label !== '') {
                $name = explode(',', $label)[0];
            }

            if ($name === '') {
                $name = 'Location';
            }

            $results[] = [
                'name'    => $name,
                'address' => $label !== '' ? $label : 'Address unavailable',
                'lat'     => $lat,
                'lng'     => $lng,
            ];
        }

        return $results;
    }
}
