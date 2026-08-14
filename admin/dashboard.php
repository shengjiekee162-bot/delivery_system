<?php
$page_title = "Live Navigation Radar";

// -------------------------------------------------------------
// Database Connection
// -------------------------------------------------------------
if (function_exists('require_role')) {
    require_role('admin');
}

if (function_exists('get_db_connection')) {
    $db = get_db_connection();
} else {
    $db = new PDO("mysql:host=localhost;dbname=delivery_db;charset=utf8mb4", "root", "123qwe");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
}

// -------------------------------------------------------------
// AJAX Endpoint: Returns JSON of ALL ONLINE riders
// -------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'get_online_riders') {
    header('Content-Type: application/json');
    
    try {
        // Query uses a subquery for active parcel to ensure EXACTLY 1 ROW per online rider
        $sql = "
            SELECT 
                r.id AS rider_id, 
                u.name, 
                r.phone, 
                r.vehicle_number, 
                r.is_online,
                rl.latitude AS latitude, 
                rl.longitude AS longitude, 
                rl.recorded_at,
                p.id AS parcel_id,
                p.tracking_number,
                p.recipient_name,
                p.delivery_address,
                p.latitude AS dest_latitude,
                p.longitude AS dest_longitude,
                p.status AS parcel_status,
                (
                    SELECT COUNT(*) 
                    FROM parcels p_cnt 
                    WHERE p_cnt.assigned_rider_id = r.id 
                      AND p_cnt.status IN ('pending', 'out_for_delivery') 
                      AND p_cnt.deleted_at IS NULL
                ) AS active_deliveries
            FROM riders r
            JOIN users u ON r.user_id = u.id
            LEFT JOIN (
                SELECT rider_id, MAX(recorded_at) AS max_time
                FROM rider_locations
                GROUP BY rider_id
            ) latest ON r.id = latest.rider_id
            LEFT JOIN rider_locations rl ON rl.rider_id = latest.rider_id AND rl.recorded_at = latest.max_time
            LEFT JOIN parcels p ON p.id = (
                SELECT id FROM parcels 
                WHERE assigned_rider_id = r.id 
                  AND status IN ('pending', 'out_for_delivery') 
                  AND deleted_at IS NULL 
                ORDER BY created_at DESC 
                LIMIT 1
            )
            WHERE r.is_online = 1 
              AND r.deleted_at IS NULL 
              AND u.deleted_at IS NULL
            ORDER BY u.name ASC
        ";

        $stmt = $db->query($sql);
        $online_riders = $stmt->fetchAll();

        echo json_encode(['status' => 'success', 'data' => $online_riders]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------
// Initial Data Fetch
// -------------------------------------------------------------
$online_count = $db->query("SELECT COUNT(*) FROM riders WHERE is_online = 1 AND deleted_at IS NULL")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - Courier Dispatch Portal</title>
    
    <!-- FontAwesome & Leaflet CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" />

    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #f0f4f8; color: #1e293b; display: flex; flex-direction: column; min-height: 100vh; }
        
        .navbar { background-color: #1e242b; color: #ffffff; height: 56px; display: flex; align-items: center; justify-content: space-between; padding: 0 20px; position: sticky; top: 0; z-index: 1000; }
        .navbar-brand { font-size: 1.15rem; font-weight: 700; display: flex; align-items: center; gap: 10px; color: #ffffff; text-decoration: none; }
        .navbar-user { display: flex; align-items: center; gap: 16px; font-size: 14px; }
        .btn-logout { background-color: #ef4444; color: #ffffff; padding: 6px 14px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 6px; }

        .app-container { display: flex; flex: 1; }
        .sidebar { width: 220px; background-color: #ffffff; padding: 16px 12px; display: flex; flex-direction: column; gap: 4px; border-right: 1px solid #e2e8f0; min-height: calc(100vh - 56px); }
        .sidebar-item { display: flex; align-items: center; gap: 12px; padding: 10px 14px; color: #475569; text-decoration: none; font-weight: 500; font-size: 14px; border-radius: 8px; }
        .sidebar-item.active { background-color: #e0f2fe; color: #0284c7; font-weight: 600; }

        .main-content { flex: 1; padding: 24px; background-color: #f0f4f8; }

        .card { background: #ffffff; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; margin-bottom: 24px; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .card-title { font-size: 1.35rem; font-weight: 700; color: #0f172a; }
        .badge-online-fleet { background-color: #dcfce7; color: #15803d; padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 700; display: flex; align-items: center; gap: 8px; }

        .radar-status-bar { display: flex; align-items: center; justify-content: space-between; background-color: #f1f5f9; padding: 8px 14px; border-radius: 8px; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 16px; }
        .pulse-dot { width: 10px; height: 10px; background-color: #10b981; border-radius: 50%; display: inline-block; animation: pulse 1.8s infinite; }

        .route-distance-banner {
            background-color: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: #1e40af;
            font-weight: 700;
            font-size: 15px;
        }

        .route-trail-controls {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 16px;
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 16px;
            font-size: 14px;
            color: #334155;
        }

        .route-trail-controls label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            cursor: pointer;
        }

        .route-trail-controls select {
            padding: 6px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: #ffffff;
            font-size: 13px;
            color: #0f172a;
        }

        .route-trail-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-left: auto;
        }

        .route-trail-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            font-size: 12px;
            font-weight: 600;
            color: #475569;
        }

        .route-trail-chip span {
            width: 12px;
            height: 4px;
            border-radius: 999px;
            display: inline-block;
        }

        .google-search-panel {
            background: #ffffff;
            border: 1px solid #dbe3ee;
            border-radius: 16px;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.08);
            overflow: hidden;
            margin-bottom: 18px;
        }

        .google-search-bar {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 18px 18px 10px 18px;
        }

        .google-search-bar input {
            flex: 1;
            border: none;
            outline: none;
            font-size: 1.1rem;
            color: #1e293b;
            background: transparent;
        }

        .google-search-bar input::placeholder {
            color: #64748b;
        }

        .search-trigger {
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
            color: white;
            border: none;
            width: 42px;
            height: 42px;
            border-radius: 12px;
            cursor: pointer;
            font-size: 1rem;
            box-shadow: 0 8px 18px rgba(2, 132, 199, 0.22);
        }

        .search-results {
            padding: 0 0 6px 0;
            background: #fff;
            max-height: 420px;
            overflow-y: auto;
        }

        .search-result-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 16px 18px;
            cursor: pointer;
            border-top: 1px solid #edf2f7;
            transition: background 0.2s ease;
        }

        .search-result-item:hover {
            background: #f8fafc;
        }

        .search-result-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #e2e8f0;
            color: #334155;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
            margin-top: 3px;
        }

        .search-result-copy {
            flex: 1;
            min-width: 0;
        }

        .search-result-title {
            font-size: 1.02rem;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .search-result-address {
            color: #475569;
            font-size: 0.92rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .search-result-meta {
            color: #16a34a;
            font-weight: 600;
            font-size: 0.9rem;
            margin-top: 3px;
        }

        .search-bottom-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 18px 18px 18px;
            border-top: 1px solid #edf2f7;
            background: #fff;
        }

        .search-bottom-actions .home-link,
        .search-bottom-actions .set-location-link {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: #0f172a;
            font-weight: 600;
            font-size: 1.05rem;
            background: none;
            border: none;
            cursor: pointer;
        }

        .search-bottom-actions .home-link {
            color: #0f172a;
        }

        .search-bottom-actions .set-location-link {
            color: #0ea5e9;
        }

        @keyframes pulse {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        #radar-map { width: 100%; height: 580px; border-radius: 10px; border: 1px solid #cbd5e1; z-index: 1; }

        .rider-popup { font-size: 13px; line-height: 1.5; }
        .rider-popup h4 { font-size: 15px; color: #0284c7; margin-bottom: 4px; display: flex; align-items: center; gap: 6px; }
        .rider-popup .status-tag { display: inline-block; padding: 2px 6px; background-color: #e0f2fe; color: #0369a1; border-radius: 4px; font-weight: 600; font-size: 11px; margin-top: 4px; }

        /* Dispatch portal theme */
        body { background: radial-gradient(circle at 85% 0%, #dff4e9 0, transparent 28rem), #f5f2ea; color: #173b37; font-family: "DM Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        .navbar { height: 68px; padding: 0 30px; background: #153c36; box-shadow: 0 4px 20px rgba(21, 60, 54, .16); }
        .navbar-brand { color: #fffdf8; font-family: "Space Grotesk", sans-serif; font-size: 1.25rem; letter-spacing: -.035em; }
        .navbar-brand i { color: #baf4d9; }
        .navbar-user { color: #c7d9d4; }
        .btn-logout { padding: 8px 13px; border: 1px solid rgba(255,255,255,.13); border-radius: 9px; background: rgba(255,255,255,.1); color: #fff; transition: .2s ease; }
        .btn-logout:hover { background: #b94c4c; }
        .sidebar { width: 245px; min-height: calc(100vh - 68px); padding: 22px 14px; gap: 7px; border: 0; background: #fffdf8; box-shadow: 7px 0 22px rgba(42, 61, 53, .055); }
        .sidebar-item { padding: 11px 13px; border: 1px solid transparent; border-radius: 10px; color: #60736e; font-weight: 600; transition: .2s ease; }
        .sidebar-item:hover { background: #f0f8f3; color: #087e6b; }
        .sidebar-item.active { border-color: #cdebe0; background: #dff4e9; color: #076856; font-weight: 700; }
        .main-content { padding: 32px clamp(18px, 4vw, 50px); background: transparent; }
        .card { padding: 26px; border: 1px solid #e5e0d5; border-radius: 18px; background: rgba(255, 253, 248, .92); box-shadow: 0 15px 35px rgba(42, 61, 53, .08); }
        .card-header { margin-bottom: 20px; }
        .card-title { color: #173b37; font-family: "Space Grotesk", sans-serif; font-size: clamp(1.35rem, 2vw, 1.7rem); letter-spacing: -.05em; }
        .card-title i { color: #087e6b; }
        .badge-online-fleet { padding: 7px 13px; border: 1px solid #ccebdc; background: #dff5e8; color: #176b49; }
        .pulse-dot { background: #16875b; }
        .radar-status-bar { border: 1px solid #e4e6dc; border-radius: 10px; background: #f2f6ee; color: #46615b; }
        .radar-status-bar i { color: #087e6b !important; }
        .radar-status-bar span:last-child { background: #dfe9df !important; border-radius: 99px !important; color: #4e645e; }
        .route-distance-banner { border-color: #bfe4d5; border-radius: 11px; background: #e5f7ef; color: #076856; }
        .route-trail-controls { gap: 12px; border-color: #e5e0d5; border-radius: 11px; background: #f8f7f1; color: #38524d; }
        .route-trail-controls select { border-color: #d9d6ca; border-radius: 8px; color: #173b37; }
        .route-trail-chip { border-color: #dfe5db; background: #fffefb; color: #526862; }
        .google-search-panel { border-color: #e5e0d5; border-radius: 14px; box-shadow: 0 8px 22px rgba(42,61,53,.07); }
        .google-search-bar { background: #fffefb; }
        .google-search-bar input { color: #173b37; font-family: inherit; }
        .search-trigger { background: #087e6b; border-radius: 10px; box-shadow: 0 7px 15px rgba(8,126,107,.2); }
        .search-trigger:hover { background: #056454; }
        .search-result-item { border-color: #efede6; }.search-result-item:hover { background: #f3faf6; }
        .search-result-icon { background: #dff4e9; color: #087e6b; }.search-result-title { color: #173b37; }.search-result-address { color: #60736e; }.search-result-meta { color: #16875b; }
        .search-bottom-actions { border-color: #efede6; background: #fffefb; }.search-bottom-actions .home-link { color: #173b37; }.search-bottom-actions .set-location-link { color: #087e6b; }
        #radar-map { border-color: #d7ddd5; border-radius: 13px; }
        .rider-popup h4 { color: #087e6b; }.rider-popup .status-tag { background: #dff4e9; color: #076856; border-radius: 99px; }
        @media (max-width: 720px) { .navbar { height: auto; min-height: 64px; padding: 12px 16px; gap: 12px; }.navbar-user span { display:none; }.sidebar { width: 100%; min-height: 0; flex-direction: row; overflow-x: auto; padding: 10px; }.sidebar-item { white-space: nowrap; }.app-container { flex-direction: column; }.main-content { padding: 16px; }.card { padding: 16px; }.card-header { align-items: flex-start; flex-direction: column; }.route-trail-legend { width: 100%; margin-left: 0; } }
    </style>
</head>
<body>

    <header class="navbar">
        <a href="dashboard.php" class="navbar-brand">
            <i class="fa-solid fa-boxes-packing"></i>
            <span>Courier Dispatch Portal</span>
        </a>
        <div class="navbar-user">
            <span><i class="fa-solid fa-circle-user"></i> System Admin</span>
            <a href="../logout.php" class="btn-logout"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
        </div>
    </header>

    <div class="app-container">
        <aside class="sidebar">
            <a href="dashboard.php" class="sidebar-item active"><i class="fa-solid fa-map-location-dot"></i><span>Live Radar</span></a>
            <a href="parcels.php" class="sidebar-item"><i class="fa-solid fa-box"></i><span>Manage Parcels</span></a>
            <a href="completed_orders.php" class="sidebar-item"><i class="fa-solid fa-circle-check"></i><span>Completed Orders</span></a>
            <a href="riders.php" class="sidebar-item"><i class="fa-solid fa-motorcycle"></i><span>Manage Riders</span></a>
            <a href="audit_logs.php" class="sidebar-item"><i class="fa-solid fa-clock-rotate-left"></i><span>Audit Logs</span></a>
        </aside>

        <main class="main-content">
            <div class="card">
                <div class="card-header">
                    <h1 class="card-title"><i class="fa-solid fa-route"></i> Live Fleet Navigation Radar</h1>
                    <div class="badge-online-fleet">
                        <span class="pulse-dot"></span>
                        Riders Online: <span id="online-rider-counter"><?= (int)$online_count ?></span>
                    </div>
                </div>

                <div class="radar-status-bar">
                    <span><i class="fa-solid fa-radar" style="color:#0284c7;"></i> Status: Live Radar Operational (Auto-refreshing every 5s)</span>
                    <span style="background: #e2e8f0; padding: 3px 8px; border-radius: 6px; font-size: 11px;">Auto-refreshing GPS</span>
                </div>

                <div id="route-distance-card" class="route-distance-banner" style="display: none;">
                    <span><i class="fa-solid fa-route"></i> Active Route to Recipient</span>
                    <span id="route-distance-text"><i class="fa-solid fa-spinner fa-spin"></i> Calculating road distance...</span>
                </div>

                <div class="route-trail-controls">
                    <span class="route-trail-chip"><span style="background:#0284c7;"></span> Planned route</span>
                    <span class="route-trail-chip"><span style="background:#f97316;"></span> Actual path (off route)</span>
                    <label for="show-route-trail">
                        <input type="checkbox" id="show-route-trail" checked>
                        Show rider traveled route
                    </label>
                    <label for="route-hours">
                        Time range:
                        <select id="route-hours">
                            <option value="1">Last 1 hour</option>
                            <option value="6">Last 6 hours</option>
                            <option value="24" selected>Last 24 hours</option>
                            <option value="72">Last 3 days</option>
                        </select>
                    </label>
                    <span id="route-trail-summary" style="font-size: 13px; color: #64748b;">Loading route trails...</span>
                    <div id="route-trail-legend" class="route-trail-legend"></div>
                </div>

                <div class="google-search-panel">
                    <div class="google-search-bar">
                        <input id="map-search-input" type="text" value="" placeholder="Search nearby places or addresses (e.g. KFC, pharmacy, Jalan Penang)" aria-label="Search address" />
                        <button id="search-place-btn" class="search-trigger" aria-label="Search"><i class="fa-solid fa-magnifying-glass"></i></button>
                    </div>
                    <div id="search-results" class="search-results" aria-live="polite"></div>
                    <div class="search-bottom-actions">
                        <button type="button" class="home-link"><i class="fa-solid fa-house"></i> Home</button>
                        <button type="button" class="set-location-link"><i class="fa-solid fa-location-crosshairs"></i> Use my location</button>
                    </div>
                </div>

                <div id="radar-map"></div>
            </div>
        </main>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <script>
        // Covers Peninsular Malaysia, Sabah and Sarawak. Real riders/routes still zoom the map to their locations.
        const MALAYSIA_BOUNDS = [[0.85, 99.60], [7.40, 119.30]];
        const map = L.map('radar-map');

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);
        map.fitBounds(MALAYSIA_BOUNDS, { padding: [18, 18] });

        const activeMarkers = {};
        let activeDeliveryPolylines = {};
        let activeDeliveryMarkers = {};
        let activeActualPolylines = {};
        let activeRoutePolylines = {};
        let activeRouteEndpoints = {};
        let searchMarkers = [];
        let activeOnlineRiders = [];
        let lastActiveRouteKey = '';
        let radarUpdateSeq = 0;
        let radarUpdateRunning = false;
        let userLocation = null;
        let userLocationMarker = null;

        const routeTrailColors = ['#f97316', '#8b5cf6', '#059669', '#db2777', '#ca8a04', '#0891b2'];
        const ROUTE_DEVIATION_METERS = 150;

        const riderIcon = L.divIcon({
            className: 'custom-rider-icon',
            html: `<div style="
                background-color: #0284c7;
                color: #ffffff;
                width: 36px;
                height: 36px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                border: 3px solid #ffffff;
                box-shadow: 0 2px 6px rgba(0,0,0,0.3);
                font-size: 16px;">
                <i class="fa-solid fa-motorcycle"></i>
            </div>`,
            iconSize: [36, 36],
            iconAnchor: [18, 18],
            popupAnchor: [0, -18]
        });

        const recipientIcon = L.divIcon({
            className: 'custom-recipient-icon',
            html: `<div style="
                background-color: #ef4444;
                color: #ffffff;
                width: 36px;
                height: 36px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                border: 3px solid #ffffff;
                box-shadow: 0 2px 6px rgba(0,0,0,0.3);
                font-size: 18px;">
                <i class="fa-solid fa-location-dot"></i>
            </div>`,
            iconSize: [36, 36],
            iconAnchor: [18, 18],
            popupAnchor: [0, -18]
        });

        function clearSearchMarkers() {
            searchMarkers.forEach(marker => map.removeLayer(marker));
            searchMarkers = [];
        }

        function escapeHtml(text) {
            return String(text || '').replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/\"/g, "&quot;");
        }

        async function getRouteSummary(startLat, startLng, endLat, endLng) {
            try {
                const params = new URLSearchParams({
                    start_lat: startLat,
                    start_lng: startLng,
                    end_lat: endLat,
                    end_lng: endLng
                });
                const response = await fetch(`../api/get_route.php?${params.toString()}`, {
                    credentials: 'same-origin'
                });
                const result = await response.json();

                if (result.status === 'success' && result.data) {
                    return {
                        distanceKm: result.data.distance_km,
                        durationMin: result.data.duration_min
                    };
                }
            } catch (error) {
                console.warn('Route summary lookup failed:', error);
            }

            return {
                distanceKm: haversineDistanceKm(startLat, startLng, endLat, endLng).toFixed(1),
                durationMin: null
            };
        }

        function clearDeliveryRoutes() {
            Object.values(activeDeliveryPolylines).forEach(layer => map.removeLayer(layer));
            Object.values(activeDeliveryMarkers).forEach(layer => map.removeLayer(layer));
            Object.values(activeActualPolylines).forEach(layer => map.removeLayer(layer));
            activeDeliveryPolylines = {};
            activeDeliveryMarkers = {};
            activeActualPolylines = {};
        }

        function pruneDeliveryLayers(activeRiderIds) {
            Object.keys(activeDeliveryPolylines).forEach(riderId => {
                if (!activeRiderIds.has(riderId)) {
                    map.removeLayer(activeDeliveryPolylines[riderId]);
                    delete activeDeliveryPolylines[riderId];
                }
            });
            Object.keys(activeDeliveryMarkers).forEach(key => {
                const riderId = key.replace(/-dest$/, '');
                if (!activeRiderIds.has(riderId)) {
                    map.removeLayer(activeDeliveryMarkers[key]);
                    delete activeDeliveryMarkers[key];
                }
            });
            Object.keys(activeActualPolylines).forEach(riderId => {
                if (!activeRiderIds.has(riderId)) {
                    map.removeLayer(activeActualPolylines[riderId]);
                    delete activeActualPolylines[riderId];
                }
            });
        }

        function minDistanceToRouteMeters(lat, lng, routeCoords) {
            if (!Array.isArray(routeCoords) || routeCoords.length < 2) {
                return Infinity;
            }

            let minDist = Infinity;
            for (let i = 0; i < routeCoords.length - 1; i++) {
                const [lat1, lng1] = routeCoords[i];
                const [lat2, lng2] = routeCoords[i + 1];
                const dist = distancePointToSegmentMeters(lat, lng, lat1, lng1, lat2, lng2);
                minDist = Math.min(minDist, dist);
            }
            return minDist;
        }

        function distancePointToSegmentMeters(pLat, pLng, aLat, aLng, bLat, bLng) {
            const ax = aLat * 111320;
            const ay = aLng * 111320 * Math.cos(aLat * Math.PI / 180);
            const bx = bLat * 111320;
            const by = bLng * 111320 * Math.cos(bLat * Math.PI / 180);
            const px = pLat * 111320;
            const py = pLng * 111320 * Math.cos(pLat * Math.PI / 180);

            const dx = bx - ax;
            const dy = by - ay;

            if (dx === 0 && dy === 0) {
                return haversineDistanceKm(pLat, pLng, aLat, aLng) * 1000;
            }

            const t = Math.max(0, Math.min(1, ((px - ax) * dx + (py - ay) * dy) / (dx * dx + dy * dy)));
            const closestLat = (ax + t * dx) / 111320;
            const closestLng = (ay + t * dy) / (111320 * Math.cos(pLat * Math.PI / 180));

            return haversineDistanceKm(pLat, pLng, closestLat, closestLng) * 1000;
        }

        async function fetchRiderActualPath(riderId, hours = 6) {
            try {
                const response = await fetch(`../api/get_rider_route.php?rider_id=${encodeURIComponent(riderId)}&hours=${encodeURIComponent(hours)}`, {
                    credentials: 'same-origin'
                });
                const result = await response.json();
                if (result.status === 'success' && Array.isArray(result.data) && result.data[0]) {
                    return result.data[0].points || [];
                }
            } catch (error) {
                console.warn('Actual delivery path lookup failed:', error);
            }
            return [];
        }

        function plotActualDeliveryPath(riderId, points) {
            if (activeActualPolylines[riderId]) {
                map.removeLayer(activeActualPolylines[riderId]);
            }

            const latLngs = points.map(point => [point.lat, point.lng]);
            activeActualPolylines[riderId] = L.polyline(latLngs, {
                color: '#f97316',
                weight: 5,
                opacity: 0.9,
                dashArray: '10 8',
                lineJoin: 'round'
            }).addTo(map);
        }

        function clearActualDeliveryPath(riderId) {
            if (activeActualPolylines[riderId]) {
                map.removeLayer(activeActualPolylines[riderId]);
                delete activeActualPolylines[riderId];
            }
        }

        function setActiveRouteBannerLoading() {
            document.getElementById('route-distance-text').innerHTML =
                '<i class="fa-solid fa-spinner fa-spin"></i> Calculating road distance...';
        }

        function setActiveRouteBannerText(text) {
            document.getElementById('route-distance-text').textContent = text;
        }

        async function plotNavigationLine(rider, startLat, startLng, destLat, destLng, updateBanner, updateSeq) {
            const routeKey = `${rider.rider_id}:${destLat.toFixed(5)},${destLng.toFixed(5)}`;

            if (updateBanner) {
                document.getElementById('route-distance-card').style.display = 'flex';
                if (routeKey !== lastActiveRouteKey) {
                    lastActiveRouteKey = routeKey;
                    setActiveRouteBannerLoading();
                }
            }

            const params = new URLSearchParams({
                start_lat: startLat,
                start_lng: startLng,
                end_lat: destLat,
                end_lng: destLng
            });

            let routeData = null;

            try {
                const response = await fetch(`../api/get_route.php?${params.toString()}`, {
                    credentials: 'same-origin'
                });
                const result = await response.json();
                if (result.status === 'success' && result.data) {
                    routeData = result.data;
                }
            } catch (error) {
                console.warn('Delivery route lookup failed:', error);
            }

            if (updateSeq !== radarUpdateSeq) {
                return null;
            }

            if (activeDeliveryPolylines[rider.rider_id]) {
                map.removeLayer(activeDeliveryPolylines[rider.rider_id]);
            }
            if (activeDeliveryMarkers[`${rider.rider_id}-dest`]) {
                map.removeLayer(activeDeliveryMarkers[`${rider.rider_id}-dest`]);
            }

            let plannedCoords = null;

            if (routeData && Array.isArray(routeData.coordinates) && routeData.coordinates.length > 1) {
                plannedCoords = routeData.coordinates;
                activeDeliveryPolylines[rider.rider_id] = L.polyline(plannedCoords, {
                    color: '#0284c7',
                    weight: 6,
                    opacity: 0.85
                }).addTo(map);

                if (updateBanner) {
                    setActiveRouteBannerText(`${routeData.distance_km} km (${routeData.duration_min} mins away)`);
                }
            } else {
                plannedCoords = [[startLat, startLng], [destLat, destLng]];
                const fallbackKm = haversineDistanceKm(startLat, startLng, destLat, destLng).toFixed(2);
                activeDeliveryPolylines[rider.rider_id] = L.polyline(plannedCoords, {
                    color: '#0284c7',
                    weight: 4,
                    opacity: 0.65,
                    dashArray: '8 8'
                }).addTo(map);

                if (updateBanner) {
                    setActiveRouteBannerText(`~${fallbackKm} km (estimated straight line)`);
                }
            }

            activeDeliveryMarkers[`${rider.rider_id}-dest`] = L.marker([destLat, destLng], { icon: recipientIcon })
                .addTo(map)
                .bindPopup(`<b>Recipient:</b> ${escapeHtml(rider.recipient_name)}<br>${escapeHtml(rider.delivery_address)}`);

            return plannedCoords;
        }

        async function resolveDestinationCoords(rider, startLat, startLng) {
            const destLat = parseFloat(rider.dest_latitude);
            const destLng = parseFloat(rider.dest_longitude);

            if (!Number.isNaN(destLat) && !Number.isNaN(destLng) && destLat !== 0 && destLng !== 0) {
                return { lat: destLat, lng: destLng };
            }

            if (!rider.delivery_address) {
                return null;
            }

            try {
                const cleanAddress = cleanAddressQuery(rider.delivery_address);
                const geoParams = new URLSearchParams({
                    q: cleanAddress,
                    lat: String(startLat),
                    lng: String(startLng)
                });
                const geoRes = await fetch(`../api/search_address.php?${geoParams.toString()}`, {
                    credentials: 'same-origin'
                });
                const geoData = await geoRes.json();
                if (geoData.status === 'success' && geoData.data && geoData.data.length > 0) {
                    return {
                        lat: parseFloat(geoData.data[0].lat),
                        lng: parseFloat(geoData.data[0].lng)
                    };
                }
            } catch (err) {
                console.error('Geocoding address error:', err);
            }

            return null;
        }

        async function syncDeviationPath(rider, riderLat, riderLng, plannedCoords, updateSeq) {
            if (updateSeq !== radarUpdateSeq || !plannedCoords) {
                return { isDeviated: false, deviationMeters: 0 };
            }

            const actualPoints = await fetchRiderActualPath(rider.rider_id, 6);
            if (updateSeq !== radarUpdateSeq) {
                return { isDeviated: false, deviationMeters: 0 };
            }

            const deviationMeters = minDistanceToRouteMeters(riderLat, riderLng, plannedCoords);
            const isDeviated = deviationMeters > ROUTE_DEVIATION_METERS && actualPoints.length >= 2;

            if (isDeviated) {
                plotActualDeliveryPath(rider.rider_id, actualPoints);
            } else {
                clearActualDeliveryPath(rider.rider_id);
            }

            return { isDeviated, deviationMeters: Math.round(deviationMeters) };
        }

        function getRiderSearchCenter() {
            if (!Array.isArray(activeOnlineRiders) || activeOnlineRiders.length === 0) {
                return null;
            }

            let best = null;
            for (const rider of activeOnlineRiders) {
                const lat = parseFloat(rider.latitude);
                const lng = parseFloat(rider.longitude);
                if (Number.isNaN(lat) || Number.isNaN(lng)) continue;

                if (!best || (rider.active_deliveries || 0) > (best.active_deliveries || 0)) {
                    best = rider;
                }
            }

            if (!best) return null;
            return { lat: parseFloat(best.latitude), lng: parseFloat(best.longitude) };
        }

        function haversineDistanceKm(lat1, lng1, lat2, lng2) {
            const toRad = value => (value * Math.PI) / 180;
            const R = 6371;
            const dLat = toRad(lat2 - lat1);
            const dLng = toRad(lng2 - lng1);
            const a =
                Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) *
                Math.sin(dLng / 2) * Math.sin(dLng / 2);
            return 2 * R * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        }

        function isMalaysiaCoordinate(lat, lng) {
            return lat >= 0.8 && lat <= 7.5 && lng >= 98.5 && lng <= 119.5;
        }

        function cleanAddressQuery(text) {
            return String(text || '')
                .replace(/\r?\n/g, ', ')
                .replace(/\s+/g, ' ')
                .replace(/,\s*,/g, ', ')
                .trim();
        }

        function showSearchHint(message) {
            const resultsContainer = document.getElementById('search-results');
            resultsContainer.innerHTML = `
                <div class="search-result-item" style="padding: 20px; border-top: none; cursor: default;">
                    <div class="search-result-copy">
                        <div class="search-result-title" style="color: #475569; font-weight: 500;">${escapeHtml(message)}</div>
                        <div class="search-result-address">Enter a street, building, city, or landmark anywhere in Malaysia.</div>
                    </div>
                </div>
            `;
        }

        function getSearchReferenceLabel() {
            if (userLocation) return 'your location';
            if (getRiderSearchCenter()) return 'nearest rider';
            return 'map center';
        }

        function setUserLocation(lat, lng, flyToMap = false) {
            userLocation = { lat, lng };

            if (userLocationMarker) {
                map.removeLayer(userLocationMarker);
            }

            userLocationMarker = L.circleMarker([lat, lng], {
                radius: 8,
                color: '#ffffff',
                weight: 2,
                fillColor: '#2563eb',
                fillOpacity: 1
            }).addTo(map).bindPopup('<b>Your location</b>');

            if (flyToMap) {
                map.flyTo([lat, lng], 15, { duration: 1.2 });
            }
        }

        function requestUserLocation(flyToMap = false, onComplete = null) {
            if (!navigator.geolocation) {
                if (onComplete) onComplete(false);
                return;
            }

            navigator.geolocation.getCurrentPosition(
                (position) => {
                    setUserLocation(position.coords.latitude, position.coords.longitude, flyToMap);
                    if (onComplete) onComplete(true);
                },
                () => {
                    if (onComplete) onComplete(false);
                },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
            );
        }

        function showSearchResults(results) {
            const resultsContainer = document.getElementById('search-results');
            const refLabel = getSearchReferenceLabel();

            if (!results || results.length === 0) {
                showSearchHint(`No results found near ${refLabel}. Try a different keyword.`);
                return;
            }

            resultsContainer.innerHTML = results.map((result, index) => {
                const distanceLabel = result.distanceKm !== null ? `${result.distanceKm} km` : 'Distance not available';
                const timeLabel = result.durationMin !== null ? `${result.durationMin} mins drive` : 'Drive time not available';
                return `
                    <div class="search-result-item" data-lat="${result.lat}" data-lng="${result.lng}" data-index="${index}" title="Focus on ${escapeHtml(result.name)}">
                        <div class="search-result-icon"><i class="fa-solid fa-location-dot"></i></div>
                        <div class="search-result-copy">
                            <div class="search-result-title">${escapeHtml(result.name)}</div>
                            <div class="search-result-address">${escapeHtml(result.address)}</div>
                            <div class="search-result-meta">${distanceLabel} from ${refLabel} • ${timeLabel}</div>
                        </div>
                    </div>
                `;
            }).join('');

            resultsContainer.querySelectorAll('.search-result-item').forEach((item) => {
                item.addEventListener('click', () => {
                    const lat = parseFloat(item.dataset.lat);
                    const lng = parseFloat(item.dataset.lng);
                    if (!isNaN(lat) && !isNaN(lng)) {
                        map.flyTo([lat, lng], 15, { duration: 1.2 });
                    }
                });
            });
        }

        function getSearchReferenceCenter() {
            if (userLocation) {
                return userLocation;
            }

            const riderCenter = getRiderSearchCenter();
            if (riderCenter) {
                return riderCenter;
            }

            const center = map.getCenter();
            return { lat: center.lat, lng: center.lng };
        }

        async function performAddressSearch() {
            const query = cleanAddressQuery(document.getElementById('map-search-input').value);
            if (!query) {
                clearSearchMarkers();
                showSearchHint('Type at least 3 characters, or click "Use my location" first.');
                return;
            }

            if (!userLocation) {
                await new Promise(resolve => requestUserLocation(false, resolve));
            }

            const referenceCenter = getSearchReferenceCenter();
            const startLat = referenceCenter.lat;
            const startLng = referenceCenter.lng;

            try {
                const params = new URLSearchParams({
                    q: query,
                    lat: String(startLat),
                    lng: String(startLng)
                });

                const response = await fetch(`../api/search_address.php?${params.toString()}`, {
                    credentials: 'same-origin'
                });
                const result = await response.json();

                if (result.status !== 'success') {
                    showSearchHint(result.message || 'Address search failed. Please try again.');
                    return;
                }

                let matchedPlaces = result.data || [];

                if (!matchedPlaces.length) {
                    clearSearchMarkers();
                    showSearchResults([]);
                    return;
                }

                matchedPlaces = matchedPlaces
                    .map(place => {
                        const targetLat = parseFloat(place.lat);
                        const targetLng = parseFloat(place.lng);
                        const straightKm = haversineDistanceKm(startLat, startLng, targetLat, targetLng);
                        return {
                            ...place,
                            straightKm
                        };
                    })
                    .sort((a, b) => a.straightKm - b.straightKm);

                const enrichedResults = await Promise.all(matchedPlaces.slice(0, 8).map(async (place) => {
                    const targetLat = parseFloat(place.lat);
                    const targetLng = parseFloat(place.lng);
                    let route = { distanceKm: place.straightKm.toFixed(1), durationMin: null };

                    if (!Number.isNaN(targetLat) && !Number.isNaN(targetLng)) {
                        route = await getRouteSummary(startLat, startLng, targetLat, targetLng).catch(() => ({
                            distanceKm: place.straightKm.toFixed(1),
                            durationMin: null
                        }));
                    }

                    return {
                        name: place.name || 'Location',
                        address: place.address || 'Address unavailable',
                        lat: targetLat,
                        lng: targetLng,
                        distanceKm: route.distanceKm,
                        durationMin: route.durationMin
                    };
                }));

                clearSearchMarkers();
                enrichedResults.forEach((result) => {
                    if (!isNaN(result.lat) && !isNaN(result.lng)) {
                        const marker = L.marker([result.lat, result.lng]).addTo(map);
                        marker.bindPopup(`<strong>${escapeHtml(result.name)}</strong><br>${escapeHtml(result.address)}`);
                        searchMarkers.push(marker);
                    }
                });

                if (enrichedResults.length === 1) {
                    map.flyTo([enrichedResults[0].lat, enrichedResults[0].lng], 15, { duration: 1.2 });
                } else if (enrichedResults.length > 1) {
                    const bounds = L.latLngBounds(enrichedResults.map((item) => [item.lat, item.lng]));
                    map.fitBounds(bounds.pad(0.25));
                }

                showSearchResults(enrichedResults);
            } catch (error) {
                console.error('Address search failed:', error);
                showSearchHint('Address search failed. Please try again.');
            }
        }

        let searchDebounceTimer = null;

        document.getElementById('search-place-btn').addEventListener('click', performAddressSearch);
        document.getElementById('map-search-input').addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                performAddressSearch();
            }
        });
        document.getElementById('map-search-input').addEventListener('input', () => {
            clearTimeout(searchDebounceTimer);
            const query = document.getElementById('map-search-input').value.trim();
            if (query.length < 3) {
                clearSearchMarkers();
                showSearchHint('Type at least 3 characters to search.');
                return;
            }
            searchDebounceTimer = setTimeout(() => {
                performAddressSearch();
            }, 1000);
        });

        document.querySelector('.home-link').addEventListener('click', () => {
            map.flyToBounds(MALAYSIA_BOUNDS, { padding: [18, 18], duration: 1.2 });
        });

        document.querySelector('.set-location-link').addEventListener('click', () => {
            requestUserLocation(true, (success) => {
                if (!success) {
                    alert('Unable to get your current location. Please allow location access in your browser.');
                    return;
                }

                const query = cleanAddressQuery(document.getElementById('map-search-input').value);
                if (query.length >= 3) {
                    performAddressSearch();
                }
            });
        });

        requestUserLocation(false);
        showSearchHint('Click "Use my location" or allow location access, then search for nearby places.');

        function getRouteTrailColor(index) {
            return routeTrailColors[index % routeTrailColors.length];
        }

        function clearRouteTrails() {
            Object.values(activeRoutePolylines).forEach(layer => map.removeLayer(layer));
            Object.values(activeRouteEndpoints).forEach(layer => map.removeLayer(layer));
            activeRoutePolylines = {};
            activeRouteEndpoints = {};
        }

        function updateRouteTrailLegend(routes) {
            const legend = document.getElementById('route-trail-legend');
            const summary = document.getElementById('route-trail-summary');

            if (!routes.length) {
                legend.innerHTML = '';
                summary.textContent = 'No GPS trail recorded in this time range.';
                return;
            }

            summary.textContent = `${routes.length} rider route${routes.length > 1 ? 's' : ''} shown`;
            legend.innerHTML = routes.map((route, index) => `
                <span class="route-trail-chip">
                    <span style="background:${getRouteTrailColor(index)};"></span>
                    ${escapeHtml(route.rider_name)} (${route.point_count} pts)
                </span>
            `).join('');
        }

        async function updateRouteTrails() {
            const showTrail = document.getElementById('show-route-trail').checked;
            const hours = document.getElementById('route-hours').value;

            if (!showTrail) {
                clearRouteTrails();
                document.getElementById('route-trail-legend').innerHTML = '';
                document.getElementById('route-trail-summary').textContent = 'Route trail hidden';
                return;
            }

            try {
                const response = await fetch(`../api/get_rider_route.php?hours=${encodeURIComponent(hours)}`);
                const result = await response.json();

                if (result.status !== 'success') {
                    document.getElementById('route-trail-summary').textContent = 'Unable to load route trails';
                    return;
                }

                clearRouteTrails();
                const routes = result.data || [];
                updateRouteTrailLegend(routes);

                routes.forEach((route, index) => {
                    if (!Array.isArray(route.points) || route.points.length < 2) {
                        return;
                    }

                    const latLngs = route.points.map(point => [point.lat, point.lng]);
                    const color = getRouteTrailColor(index);

                    const polyline = L.polyline(latLngs, {
                        color,
                        weight: 5,
                        opacity: 0.85,
                        lineJoin: 'round'
                    }).addTo(map);

                    polyline.bindPopup(`
                        <div class="rider-popup">
                            <h4><i class="fa-solid fa-road"></i> ${escapeHtml(route.rider_name)}</h4>
                            <p><strong>Vehicle:</strong> ${escapeHtml(route.vehicle_number || 'N/A')}</p>
                            <p><strong>GPS Points:</strong> ${route.point_count}</p>
                            <p><strong>From:</strong> ${escapeHtml(route.started_at || 'N/A')}</p>
                            <p><strong>Latest:</strong> ${escapeHtml(route.ended_at || 'N/A')}</p>
                        </div>
                    `);

                    activeRoutePolylines[route.rider_id] = polyline;

                    const startMarker = L.circleMarker(latLngs[0], {
                        radius: 6,
                        color: '#ffffff',
                        weight: 2,
                        fillColor: '#22c55e',
                        fillOpacity: 1
                    }).addTo(map).bindPopup(`<b>Route start</b><br>${escapeHtml(route.rider_name)}`);

                    const endMarker = L.circleMarker(latLngs[latLngs.length - 1], {
                        radius: 6,
                        color: '#ffffff',
                        weight: 2,
                        fillColor: color,
                        fillOpacity: 1
                    }).addTo(map).bindPopup(`<b>Latest position</b><br>${escapeHtml(route.rider_name)}`);

                    activeRouteEndpoints[`${route.rider_id}-start`] = startMarker;
                    activeRouteEndpoints[`${route.rider_id}-end`] = endMarker;
                });
            } catch (error) {
                console.error('Error loading route trails:', error);
                document.getElementById('route-trail-summary').textContent = 'Route trail load failed';
            }
        }

        document.getElementById('show-route-trail').addEventListener('change', updateRouteTrails);
        document.getElementById('route-hours').addEventListener('change', updateRouteTrails);

        async function updateRadarMap() {
            if (radarUpdateRunning) {
                return;
            }

            radarUpdateRunning = true;
            const updateSeq = ++radarUpdateSeq;

            try {
                const response = await fetch('dashboard.php?action=get_online_riders');
                const result = await response.json();

                if (result.status !== 'success' || updateSeq !== radarUpdateSeq) return;

                const onlineRiders = result.data;
                activeOnlineRiders = onlineRiders;
                const activeRiderIds = new Set();
                const activeDeliveryRiderIds = new Set();

                document.getElementById('online-rider-counter').innerText = onlineRiders.length;

                let hasActiveRoute = false;
                let bannerRouteUpdated = false;

                for (let index = 0; index < onlineRiders.length; index++) {
                    if (updateSeq !== radarUpdateSeq) break;

                    const rider = onlineRiders[index];
                    const riderId = rider.rider_id;
                    
                    let lat = parseFloat(rider.latitude);
                    let lng = parseFloat(rider.longitude);

                    if (isNaN(lat) || isNaN(lng)) continue;

                    const angle = index * (2 * Math.PI / Math.max(onlineRiders.length, 1));
                    const radius = 0.0012;
                    let displayLat = lat + (Math.sin(angle) * radius);
                    let displayLng = lng + (Math.cos(angle) * radius);

                    activeRiderIds.add(riderId);

                    let routeStatusHtml = '';
                    let deviationInfo = { isDeviated: false, deviationMeters: 0 };
                    const hasActiveDelivery = Number(rider.active_deliveries) > 0;

                    if (hasActiveDelivery) {
                        activeDeliveryRiderIds.add(riderId);
                        hasActiveRoute = true;
                        const updateBanner = !bannerRouteUpdated;
                        if (updateBanner) {
                            bannerRouteUpdated = true;
                        }

                        const destination = await resolveDestinationCoords(rider, lat, lng);
                        if (destination) {
                            const plannedCoords = await plotNavigationLine(
                                rider,
                                lat,
                                lng,
                                destination.lat,
                                destination.lng,
                                updateBanner,
                                updateSeq
                            );
                            deviationInfo = await syncDeviationPath(rider, lat, lng, plannedCoords, updateSeq);
                        } else if (updateBanner) {
                            setActiveRouteBannerText('Unable to locate recipient address.');
                        }

                        if (deviationInfo.isDeviated) {
                            routeStatusHtml = `<span class="status-tag" style="background:#ffedd5;color:#c2410c;">Off Route (~${deviationInfo.deviationMeters}m away)</span>`;
                        } else if (hasActiveDelivery) {
                            routeStatusHtml = `<span class="status-tag" style="background:#dcfce7;color:#15803d;">On Planned Route</span>`;
                        }
                    }

                    const popupContent = `
                        <div class="rider-popup">
                            <h4><i class="fa-solid fa-motorcycle"></i> ${escapeHtml(rider.name)}</h4>
                            <p><strong>Vehicle:</strong> ${escapeHtml(rider.vehicle_number)}</p>
                            <p><strong>Phone:</strong> ${escapeHtml(rider.phone || 'N/A')}</p>
                            <p><strong>Active Parcels:</strong> ${rider.active_deliveries}</p>
                            ${routeStatusHtml || `<span class="status-tag">Status: ${rider.active_deliveries > 0 ? 'On Delivery' : 'Idle / Available'}</span>`}
                        </div>
                    `;

                    if (activeMarkers[riderId]) {
                        activeMarkers[riderId].setLatLng([displayLat, displayLng]);
                        activeMarkers[riderId].getPopup().setContent(popupContent);
                    } else {
                        const marker = L.marker([displayLat, displayLng], { icon: riderIcon })
                            .addTo(map)
                            .bindPopup(popupContent);

                        activeMarkers[riderId] = marker;
                    }
                }

                if (updateSeq === radarUpdateSeq) {
                    pruneDeliveryLayers(activeDeliveryRiderIds);
                }

                if (!hasActiveRoute) {
                    document.getElementById('route-distance-card').style.display = 'none';
                    lastActiveRouteKey = '';
                }

                Object.keys(activeMarkers).forEach(riderId => {
                    if (!activeRiderIds.has(riderId)) {
                        map.removeLayer(activeMarkers[riderId]);
                        delete activeMarkers[riderId];
                    }
                });

            } catch (error) {
                console.error('Error updating live radar:', error);
            } finally {
                radarUpdateRunning = false;
            }
        }

        updateRadarMap();
        updateRouteTrails();
        setInterval(updateRadarMap, 5000);
        setInterval(updateRouteTrails, 15000);
    </script>
</body>
</html>
