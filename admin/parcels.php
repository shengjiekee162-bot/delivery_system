<?php
$page_title = "Parcel Management";

require_once __DIR__ . '/../includes/http_client.php';
require_once __DIR__ . '/../config/services.php';

// Role & Database Connection Checks
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

$ors_api_key = 'eyJvcmciOiI1YjNjZTM1OTc4NTExMTAwMDFjZjYyNDgiLCJpZCI6ImE3YWQxMDdlOTA1YjQwM2JiYjAzNmJjZjg0MTQ5NWNhIiwiaCI6Im11cm11cjY0In0=';
$message = '';
$error = '';

// Helper function to normalize proof image paths for the admin view
function getProofImageUrl($path) {
    if (empty($path)) return '';
    if (strpos($path, '../') === 0) {
        return substr($path, 3);
    }
    return $path;
}

// Helper function for geocoding
function geocodeAddress($address, $api_key) {
    $address = normalize_address_query($address);
    $region = detect_address_region($address);
    $focus = resolve_geocode_focus($address, null, null);

    $results = ors_geocode_malaysia(
        $address,
        $focus['lat'],
        $focus['lng'],
        5,
        $region
    );

    if ($region) {
        $results = filter_results_by_address_region($results, $region);
    }

    if (!empty($results)) {
        return [$results[0]['lat'], $results[0]['lng']];
    }

    foreach (build_address_search_variants($address) as $variant) {
        $results = ors_geocode_malaysia($variant, $focus['lat'], $focus['lng'], 3, $region);
        if ($region) {
            $results = filter_results_by_address_region($results, $region);
        }
        if (!empty($results)) {
            return [$results[0]['lat'], $results[0]['lng']];
        }
    }

    return [null, null];
}

function submitted_malaysia_coordinates(): array {
    $lat = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $lng = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($lat !== false && $lng !== false && $lat >= 0.8 && $lat <= 7.5 && $lng >= 98.5 && $lng <= 119.5) {
        return [(float)$lat, (float)$lng];
    }
    return [null, null];
}

// Handle Parcel Creation Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_parcel') {
    $recipient_name   = trim($_POST['recipient_name'] ?? '');
    $recipient_phone  = trim($_POST['recipient_phone'] ?? '');
    $delivery_address = trim($_POST['delivery_address'] ?? '');
    $assigned_rider_id = $_POST['assigned_rider_id'] ?? null;

    if (!empty($recipient_name) && !empty($recipient_phone) && !empty($delivery_address)) {
        $tracking_number = 'TRK-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        
        list($lat, $lng) = submitted_malaysia_coordinates();
        if ($lat === null || $lng === null) {
            list($lat, $lng) = geocodeAddress($delivery_address, $ors_api_key);
        }

        $status = 'out_for_delivery';

        $stmt = $db->prepare("
            INSERT INTO parcels 
            (id, tracking_number, recipient_name, recipient_phone, delivery_address, latitude, longitude, status, assigned_rider_id) 
            VALUES (UUID(), :tracking, :name, :phone, :address, :lat, :lng, :status, :rider_id)
        ");
        
        $stmt->execute([
            ':tracking' => $tracking_number,
            ':name'     => $recipient_name,
            ':phone'    => $recipient_phone,
            ':address'  => $delivery_address,
            ':lat'      => $lat,
            ':lng'      => $lng,
            ':status'   => $status,
            ':rider_id' => !empty($assigned_rider_id) ? $assigned_rider_id : null
        ]);

        $message = "Parcel {$tracking_number} created! Address converted to map coordinates.";
    } else {
        $error = "Please fill in all required fields.";
    }
}

// Handle Parcel Update Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_parcel') {
    $parcel_id        = $_POST['parcel_id'] ?? '';
    $recipient_name   = trim($_POST['recipient_name'] ?? '');
    $recipient_phone  = trim($_POST['recipient_phone'] ?? '');
    $delivery_address = trim($_POST['delivery_address'] ?? '');
    $assigned_rider_id = $_POST['assigned_rider_id'] ?? null;
    $status           = $_POST['status'] ?? 'out_for_delivery';

    if (!empty($parcel_id) && !empty($recipient_name) && !empty($recipient_phone) && !empty($delivery_address)) {
        list($lat, $lng) = submitted_malaysia_coordinates();
        if ($lat === null || $lng === null) {
            list($lat, $lng) = geocodeAddress($delivery_address, $ors_api_key);
        }

        $stmt = $db->prepare("
            UPDATE parcels 
            SET recipient_name = :name,
                recipient_phone = :phone,
                delivery_address = :address,
                latitude = :lat,
                longitude = :lng,
                status = :status,
                assigned_rider_id = :rider_id
            WHERE id = :id AND deleted_at IS NULL
        ");

        $stmt->execute([
            ':name'     => $recipient_name,
            ':phone'    => $recipient_phone,
            ':address'  => $delivery_address,
            ':lat'      => $lat,
            ':lng'      => $lng,
            ':status'   => $status,
            ':rider_id' => !empty($assigned_rider_id) ? $assigned_rider_id : null,
            ':id'       => $parcel_id
        ]);

        $message = "Parcel updated successfully!";
    } else {
        $error = "Failed to update parcel. Please ensure all required fields are filled.";
    }
}

// Handle Parcel Soft Delete Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_parcel') {
    $parcel_id = $_POST['parcel_id'] ?? '';

    if (!empty($parcel_id)) {
        $stmt = $db->prepare("UPDATE parcels SET deleted_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => $parcel_id]);
        $message = "Parcel deleted successfully!";
    } else {
        $error = "Invalid parcel selected for deletion.";
    }
}

// Fetch Active Riders
$riders = $db->query("
    SELECT r.id AS rider_id, u.name, r.vehicle_number 
    FROM riders r 
    JOIN users u ON r.user_id = u.id 
    WHERE u.deleted_at IS NULL
")->fetchAll();

// Handle Search Parcel Logic
$search = trim($_GET['search'] ?? '');

$sql = "
    SELECT p.*, u.name AS rider_name, MAX(dp.file_path) AS proof_image 
    FROM parcels p 
    LEFT JOIN riders r ON p.assigned_rider_id = r.id 
    LEFT JOIN users u ON r.user_id = u.id 
    LEFT JOIN delivery_photos dp ON p.id = dp.parcel_id
    WHERE p.deleted_at IS NULL AND p.status <> 'delivered'
";

$params = [];

if (!empty($search)) {
    $sql .= " AND (
        p.tracking_number LIKE :search 
        OR p.recipient_name LIKE :search 
        OR p.recipient_phone LIKE :search 
        OR p.delivery_address LIKE :search 
        OR u.name LIKE :search
    )";
    $params[':search'] = "%{$search}%";
}

$sql .= " GROUP BY p.id ORDER BY p.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$parcels = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - Courier Dispatch Portal</title>
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #f0f4f8;
            color: #1e293b;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* TOP NAVBAR */
        .navbar {
            background-color: #1e242b;
            color: #ffffff;
            height: 56px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .navbar-brand {
            font-size: 1.15rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #ffffff;
            text-decoration: none;
        }

        .navbar-user {
            display: flex;
            align-items: center;
            gap: 16px;
            font-size: 14px;
        }

        .navbar-user .user-name {
            color: #ffffff;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-logout {
            background-color: #ef4444;
            color: #ffffff;
            padding: 6px 14px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: background 0.15s ease;
        }

        .btn-logout:hover {
            background-color: #dc2626;
        }

        /* APP LAYOUT */
        .app-container {
            display: flex;
            flex: 1;
        }

        /* SIDEBAR NAVIGATION */
        .sidebar {
            width: 220px;
            background-color: #ffffff;
            padding: 16px 12px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            border-right: 1px solid #e2e8f0;
            min-height: calc(100vh - 56px);
        }

        .sidebar-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            color: #475569;
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            border-radius: 8px;
            transition: all 0.15s ease;
        }

        .sidebar-item:hover {
            background-color: #f8fafc;
            color: #0f172a;
        }

        /* Active Sidebar Tab Style */
        .sidebar-item.active {
            background-color: #e0f2fe;
            color: #0284c7;
            font-weight: 600;
        }

        .sidebar-item.active i {
            color: #0284c7;
        }

        /* MAIN CONTENT AREA */
        .main-content {
            flex: 1;
            padding: 24px;
            background-color: #f0f4f8;
        }

        /* CARD STYLES */
        .card {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            padding: 24px;
            border: 1px solid #e2e8f0;
            margin-bottom: 24px;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .card-title {
            font-size: 1.35rem;
            font-weight: 700;
            color: #0f172a;
        }

        .card-badge {
            background-color: #e2e8f0;
            color: #475569;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
        }

        /* SEARCH BAR STYLES */
        .search-form {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .search-input {
            padding: 8px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 14px;
            color: #1e293b;
            outline: none;
            transition: border-color 0.15s ease;
            min-width: 250px;
        }

        .search-input:focus {
            border-color: #0284c7;
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15);
        }

        .btn-clear {
            color: #ef4444;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            padding: 8px 12px;
            border-radius: 6px;
            background-color: #fee2e2;
            transition: background 0.15s ease;
        }

        .btn-clear:hover {
            background-color: #fecaca;
        }

        /* ALERT MESSAGES */
        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert-success {
            background-color: #dcfce7;
            color: #15803d;
            border: 1px solid #bbf7d0;
        }

        .alert-error {
            background-color: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        /* FORM ELEMENTS */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 16px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-group.full-width {
            grid-column: span 3;
        }

        .form-group label {
            font-size: 13px;
            font-weight: 600;
            color: #334155;
        }

        .form-group input[type="text"], 
        .form-group select, 
        .form-group textarea {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 14px;
            color: #1e293b;
            outline: none;
            transition: border-color 0.15s ease;
        }

        .form-group input[type="text"]:focus, 
        .form-group select:focus, 
        .form-group textarea:focus {
            border-color: #0284c7;
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15);
        }

        .btn-primary {
            background-color: #0284c7;
            color: #ffffff;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background 0.15s ease;
        }

        .btn-primary:hover {
            background-color: #0369a1;
        }

        /* TABLE STYLES */
        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 14px;
        }

        th {
            background-color: #f8fafc;
            color: #475569;
            font-weight: 700;
            padding: 12px 16px;
            border-bottom: 1px solid #e2e8f0;
        }

        td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
        }

        tr:hover {
            background-color: #f8fafc;
        }

        .phone-subtext {
            display: block;
            font-size: 12px;
            color: #94a3b8;
            margin-top: 2px;
        }

        .status-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            display: inline-block;
        }

        .status-out_for_delivery {
            background-color: #feebc8;
            color: #744210;
        }

        .status-delivered {
            background-color: #dcfce7;
            color: #15803d;
        }

        .text-green { color: #16a34a; font-weight: 600; }
        .text-pending { color: #dc2626; font-weight: 600; }

        /* PROOF IMAGE THUMBNAIL */
        .proof-thumb {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            cursor: pointer;
            transition: transform 0.15s ease;
        }
        .proof-thumb:hover {
            transform: scale(1.08);
        }

        /* ACTION BUTTONS */
        .action-btns {
            display: flex;
            gap: 6px;
            align-items: center;
        }

        .btn-edit {
            background-color: #f59e0b;
            color: #ffffff;
            border: none;
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: background 0.15s ease;
        }

        .btn-edit:hover {
            background-color: #d97706;
        }

        .btn-delete {
            background-color: #ef4444;
            color: #ffffff;
            border: none;
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: background 0.15s ease;
        }

        .btn-delete:hover {
            background-color: #dc2626;
        }

        .btn-locate {
            background-color: #0284c7;
            color: #ffffff;
            border: none;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-top: 6px;
            transition: background 0.15s ease;
        }

        .btn-locate:hover {
            background-color: #0369a1;
        }

        .rider-cell-name {
            font-weight: 600;
            color: #0f172a;
        }

        /* MODAL STYLES FOR EDIT */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(15, 23, 42, 0.5);
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background-color: #ffffff;
            padding: 24px;
            border-radius: 12px;
            width: 100%;
            max-width: 650px;
            position: relative;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
            margin: 20px;
        }

        .close-modal {
            position: absolute;
            right: 20px;
            top: 18px;
            font-size: 20px;
            font-weight: bold;
            color: #64748b;
            cursor: pointer;
        }

        .close-modal:hover {
            color: #0f172a;
        }

        .modal-content.map-modal {
            max-width: 920px;
        }

        #rider-locate-map {
            width: 100%;
            height: 420px;
            border-radius: 10px;
            border: 1px solid #cbd5e1;
            margin-top: 12px;
        }

        #address-picker-map { width: 100%; height: 300px; border: 1px solid #d7ddd5; border-radius: 11px; }
        .address-picker-results { display: grid; gap: 6px; max-height: 145px; margin-bottom: 10px; overflow-y: auto; }
        .address-picker-result { width: 100%; padding: 9px 10px; border: 1px solid #e5e0d5; border-radius: 9px; background: #fffefb; color: #173b37; cursor: pointer; font: inherit; text-align: left; }
        .address-picker-result:hover, .address-picker-result.selected { border-color: #087e6b; background: #e8f7ef; }
        .address-picker-result small { display: block; margin-top: 2px; color: #687d77; }

        .locate-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px 18px;
            font-size: 13px;
            color: #475569;
            margin-top: 8px;
        }

        .locate-meta strong {
            color: #0f172a;
        }

        .locate-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }

        .locate-status.online {
            background: #dcfce7;
            color: #15803d;
        }

        .locate-status.offline {
            background: #f1f5f9;
            color: #64748b;
        }

        @media (max-width: 900px) {
            .form-grid { grid-template-columns: 1fr; }
            .form-group.full-width { grid-column: span 1; }
        }
    </style>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap">
    <link rel="stylesheet" href="../assets/css/portal-theme.css">
</head>
<body>

    <!-- TOP NAVBAR -->
    <header class="navbar">
        <a href="dashboard.php" class="navbar-brand">
            <i class="fa-solid fa-boxes-packing"></i>
            <span>Courier Dispatch Portal</span>
        </a>
        <div class="navbar-user">
            <span class="user-name"><i class="fa-solid fa-circle-user"></i> System Admin</span>
            <a href="../logout.php" class="btn-logout"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
        </div>
    </header>

    <!-- APP WRAPPER -->
    <div class="app-container">

        <!-- SIDEBAR -->
        <aside class="sidebar">
            <a href="dashboard.php" class="sidebar-item">
                <i class="fa-solid fa-map-location-dot"></i>
                <span>Live Radar</span>
            </a>
            <a href="parcels.php" class="sidebar-item active">
                <i class="fa-solid fa-box"></i>
                <span>Manage Parcels</span>
            </a>
            <a href="completed_orders.php" class="sidebar-item">
                <i class="fa-solid fa-circle-check"></i>
                <span>Completed Orders</span>
            </a>
            <a href="riders.php" class="sidebar-item">
                <i class="fa-solid fa-motorcycle"></i>
                <span>Manage Riders</span>
            </a>
            <a href="reports.php" class="sidebar-item">
                <i class="fa-solid fa-chart-line"></i>
                <span>Reports</span>
            </a>
            <a href="audit_logs.php" class="sidebar-item">
                <i class="fa-solid fa-clock-rotate-left"></i>
                <span>Audit Logs</span>
            </a>
        </aside>

        <!-- MAIN CONTENT -->
        <main class="main-content">

            <?php if ($message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- CREATE PARCEL CARD -->
            <div class="card">
                <div class="card-header">
                    <h1 class="card-title">Manage & Dispatch Parcels</h1>
                    <span class="card-badge">Status: Dispatch System Active</span>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="create_parcel">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Recipient Name *</label>
                            <input type="text" name="recipient_name" required placeholder="Enter recipient name">
                        </div>

                        <div class="form-group">
                            <label>Recipient Phone *</label>
                            <input type="text" name="recipient_phone" required placeholder="Enter recipient phone">
                        </div>

                        <div class="form-group">
                            <label>Assign Delivery Rider</label>
                            <select name="assigned_rider_id">
                                <option value="">-- Select Rider --</option>
                                <?php foreach ($riders as $rider): ?>
                                    <option value="<?= $rider['rider_id'] ?>"><?= htmlspecialchars($rider['name'] . " (" . $rider['vehicle_number'] . ")") ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group full-width">
                            <label>Delivery Address *</label>
                            <textarea name="delivery_address" id="create_delivery_address" required rows="2" placeholder="e.g. 2828, Jln Baru, Bandar Perai Jaya, 13700 Perai, Pulau Pinang"></textarea>
                            <input type="hidden" name="latitude" id="create_latitude">
                            <input type="hidden" name="longitude" id="create_longitude">
                            <button type="button" class="btn-locate" onclick="openAddressPicker('create')"><i class="fa-solid fa-map-location-dot"></i> Locate Address</button>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary">
                        <i class="fa-solid fa-plus"></i> Create & Generate Tracking
                    </button>
                </form>
            </div>

            <!-- RECENT ORDERS CARD -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Recent Delivery Orders</h2>

                    <!-- SEARCH PARCEL FORM -->
                    <form method="GET" class="search-form">
                        <input type="text" name="search" class="search-input" value="<?= htmlspecialchars($search) ?>" placeholder="Search tracking, recipient, phone...">
                        <button type="submit" class="btn-primary" style="padding: 8px 14px;">
                            <i class="fa-solid fa-magnifying-glass"></i> Search
                        </button>
                        <?php if (!empty($search)): ?>
                            <a href="parcels.php" class="btn-clear"><i class="fa-solid fa-xmark"></i> Clear</a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Tracking #</th>
                                <th>Recipient</th>
                                <th>Address</th>
                                <th>Status</th>
                                <th>Assigned Rider</th>
                                <th>GPS Geocoded</th>
                                <th>Proof Image</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($parcels)): ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; color: #94a3b8; padding: 20px;">
                                        <?= !empty($search) ? 'No parcels found matching "<strong>' . htmlspecialchars($search) . '</strong>".' : 'No parcel orders found.' ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($parcels as $p): 
                                    $proof_url = getProofImageUrl($p['proof_image'] ?? '');
                                ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($p['tracking_number']) ?></strong></td>
                                    <td>
                                        <?= htmlspecialchars($p['recipient_name']) ?>
                                        <span class="phone-subtext"><?= htmlspecialchars($p['recipient_phone']) ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($p['delivery_address']) ?></td>
                                    <td>
                                        <span class="status-badge status-<?= htmlspecialchars($p['status']) ?>">
                                            <?= strtoupper(str_replace('_', ' ', $p['status'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($p['assigned_rider_id']) && !empty($p['rider_name'])): ?>
                                            <div class="rider-cell-name"><?= htmlspecialchars($p['rider_name']) ?></div>
                                            <button type="button" class="btn-locate" onclick='openRiderLocateModal(<?= json_encode([
                                                'rider_id'         => $p['assigned_rider_id'],
                                                'parcel_id'        => $p['id'],
                                                'rider_name'       => $p['rider_name'],
                                                'tracking_number'  => $p['tracking_number'],
                                                'recipient_name'   => $p['recipient_name'],
                                                'delivery_address' => $p['delivery_address'],
                                            ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                                <i class="fa-solid fa-location-crosshairs"></i> Locate Rider
                                            </button>
                                        <?php else: ?>
                                            <span style="color:#94a3b8;">Unassigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($p['latitude'] && $p['longitude']): ?>
                                            <span class="text-green"><i class="fa-solid fa-check"></i> Yes</span>
                                        <?php else: ?>
                                            <span class="text-pending"><i class="fa-solid fa-xmark"></i> Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($proof_url)): ?>
                                            <a href="../<?= htmlspecialchars($proof_url) ?>" target="_blank" title="View Full Proof Image">
                                                <img src="../<?= htmlspecialchars($proof_url) ?>" alt="Proof" class="proof-thumb">
                                            </a>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-size: 12px; font-style: italic;">No upload</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-btns">
                                            <button type="button" class="btn-edit" onclick='openEditModal(<?= json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                                <i class="fa-solid fa-pen-to-square"></i> Edit
                                            </button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this parcel record?');">
                                                <input type="hidden" name="action" value="delete_parcel">
                                                <input type="hidden" name="parcel_id" value="<?= htmlspecialchars($p['id']) ?>">
                                                <button type="submit" class="btn-delete">
                                                    <i class="fa-solid fa-trash"></i> Delete
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

    <!-- RIDER LOCATE MODAL -->
    <div id="riderLocateModal" class="modal">
        <div class="modal-content map-modal">
            <span class="close-modal" onclick="closeRiderLocateModal()">&times;</span>
            <h2 style="margin-bottom: 4px; font-size: 1.25rem; color: #0f172a;">
                <i class="fa-solid fa-motorcycle"></i> <span id="locate-rider-title">Rider Location</span>
            </h2>
            <p id="locate-parcel-text" style="font-size: 13px; color: #64748b; margin-bottom: 4px;"></p>
            <div class="locate-meta">
                <span id="locate-online-badge" class="locate-status offline"><i class="fa-solid fa-circle"></i> Offline</span>
                <span><strong>Last GPS:</strong> <span id="locate-gps-time">—</span></span>
                <span><strong>Vehicle:</strong> <span id="locate-vehicle">—</span></span>
            </div>
            <div id="rider-locate-map"></div>
            <p id="locate-map-status" style="font-size: 13px; color: #64748b; margin-top: 10px;">Loading rider location...</p>
        </div>
    </div>

    <!-- ADDRESS LOCATION PICKER -->
    <div id="addressPickerModal" class="modal">
        <div class="modal-content map-modal">
            <span class="close-modal" onclick="closeAddressPicker()">&times;</span>
            <h2 style="margin-bottom:6px; font-size:1.25rem;"><i class="fa-solid fa-location-dot"></i> Confirm Delivery Location</h2>
            <p id="address-picker-status" style="font-size:13px; color:#64748b; margin-bottom:10px;">Searching address...</p>
            <button type="button" class="btn-locate" onclick="locateCurrentRoad()"><i class="fa-solid fa-crosshairs"></i> Locate Current Road</button>
            <div id="address-picker-results" class="address-picker-results"></div>
            <div id="address-picker-map"></div>
            <div style="text-align:right; margin-top:12px;"><button type="button" class="btn-primary" onclick="confirmAddressLocation()"><i class="fa-solid fa-check"></i> Use This Location</button></div>
        </div>
    </div>

    <!-- EDIT PARCEL MODAL -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeEditModal()">&times;</span>
            <h2 style="margin-bottom: 16px; font-size: 1.25rem; color: #0f172a;">Edit Parcel Details</h2>
            <form method="POST">
                <input type="hidden" name="action" value="update_parcel">
                <input type="hidden" name="parcel_id" id="edit_parcel_id">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Recipient Name *</label>
                        <input type="text" name="recipient_name" id="edit_recipient_name" required>
                    </div>

                    <div class="form-group">
                        <label>Recipient Phone *</label>
                        <input type="text" name="recipient_phone" id="edit_recipient_phone" required>
                    </div>

                    <div class="form-group">
                        <label>Delivery Status</label>
                        <select name="status" id="edit_status">
                            <option value="out_for_delivery">OUT FOR DELIVERY</option>
                            <option value="delivered">DELIVERED</option>
                        </select>
                    </div>

                    <div class="form-group full-width">
                        <label>Assign Delivery Rider</label>
                        <select name="assigned_rider_id" id="edit_assigned_rider_id">
                            <option value="">-- Select Rider --</option>
                            <?php foreach ($riders as $rider): ?>
                                <option value="<?= $rider['rider_id'] ?>"><?= htmlspecialchars($rider['name'] . " (" . $rider['vehicle_number'] . ")") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full-width">
                        <label>Delivery Address *</label>
                        <textarea name="delivery_address" id="edit_delivery_address" required rows="3"></textarea>
                        <input type="hidden" name="latitude" id="edit_latitude">
                        <input type="hidden" name="longitude" id="edit_longitude">
                        <button type="button" class="btn-locate" onclick="openAddressPicker('edit')"><i class="fa-solid fa-map-location-dot"></i> Locate Address</button>
                    </div>

                    <div class="form-group full-width" id="edit_proof_container" style="display: none;">
                        <label>Proof of Delivery Image</label>
                        <div style="margin-top: 4px;">
                            <a id="edit_proof_link" href="#" target="_blank">
                                <img id="edit_proof_img" src="" alt="Proof Image" style="max-height: 120px; border-radius: 6px; border: 1px solid #cbd5e1;">
                            </a>
                        </div>
                    </div>
                </div>

                <div style="text-align: right; margin-top: 12px;">
                    <button type="submit" class="btn-primary">
                        <i class="fa-solid fa-floppy-disk"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL & AUTO SEARCH JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        let locateMap = null;
        let locateLayers = { rider: null, dest: null, route: null };
        let locateRefreshTimer = null;
        let locateContext = null;
        let addressPickerMap = null;
        let addressPickerMarker = null;
        let addressPickerMode = 'create';
        let selectedAddressLocation = null;

        function addressPickerField(name) {
            return document.getElementById(`${addressPickerMode}_${name}`);
        }

        function showAddressPickerLocation(location, zoom = true) {
            selectedAddressLocation = location;
            if (addressPickerMarker) addressPickerMap.removeLayer(addressPickerMarker);
            addressPickerMarker = L.marker([location.lat, location.lng]).addTo(addressPickerMap);
            if (zoom) addressPickerMap.setView([location.lat, location.lng], 16);
            document.getElementById('address-picker-status').textContent = `Selected: ${location.name || 'Pinned location'}`;
            document.querySelectorAll('.address-picker-result').forEach((button, index) => button.classList.toggle('selected', index === location.index));
        }

        async function locateCurrentRoad() {
            const status = document.getElementById('address-picker-status');
            if (!navigator.geolocation) {
                status.textContent = 'This browser does not support GPS location. Please pin the point on the map.';
                return;
            }

            status.textContent = 'Getting your high-accuracy GPS location...';
            navigator.geolocation.getCurrentPosition(async position => {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                const accuracy = Math.round(position.coords.accuracy || 0);
                showAddressPickerLocation({ name: 'Current location', address: '', lat, lng, index: -1 });
                status.textContent = `Location found (accuracy about ${accuracy} m). Identifying current road...`;

                try {
                    const response = await fetch(`../api/reverse_geocode.php?lat=${encodeURIComponent(lat)}&lng=${encodeURIComponent(lng)}`);
                    const payload = await response.json();
                    if (payload.status !== 'success') throw new Error(payload.message || 'Unable to identify road');
                    showAddressPickerLocation({ ...payload.data, index: -1 });
                    status.textContent = payload.data.road
                        ? `Current road: ${payload.data.road} (GPS accuracy about ${accuracy} m).`
                        : `Current location found (GPS accuracy about ${accuracy} m).`;
                } catch (error) {
                    status.textContent = `Current location found (GPS accuracy about ${accuracy} m), but the road name could not be identified. You can still use this point.`;
                }
            }, error => {
                const message = error.code === error.PERMISSION_DENIED
                    ? 'Location permission was denied. Allow location access, then try again.'
                    : 'Unable to get your location. Check GPS/network and try again.';
                status.textContent = message;
            }, {
                enableHighAccuracy: true,
                timeout: 15000,
                maximumAge: 0,
            });
        }

        async function openAddressPicker(mode) {
            addressPickerMode = mode;
            const address = addressPickerField('delivery_address').value.trim();
            if (!address) { alert('Please enter a delivery address first.'); return; }
            selectedAddressLocation = null;

            document.getElementById('addressPickerModal').style.display = 'flex';
            document.getElementById('address-picker-status').textContent = 'Searching address...';
            const resultsBox = document.getElementById('address-picker-results');
            resultsBox.innerHTML = '';

            if (!addressPickerMap) {
                addressPickerMap = L.map('address-picker-map');
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap contributors' }).addTo(addressPickerMap);
                addressPickerMap.on('click', event => showAddressPickerLocation({ name: 'Pinned location', address: addressPickerField('delivery_address').value.trim(), lat: event.latlng.lat, lng: event.latlng.lng, index: -1 }));
            }
            addressPickerMap.fitBounds([[0.85, 99.60], [7.40, 119.30]], { padding: [18, 18] });
            setTimeout(() => addressPickerMap.invalidateSize(), 180);

            try {
                let currentLocation = null;
                if (navigator.geolocation) {
                    try {
                        currentLocation = await new Promise((resolve, reject) => navigator.geolocation.getCurrentPosition(resolve, reject, {
                            enableHighAccuracy: true, timeout: 8000, maximumAge: 30000
                        }));
                    } catch (error) { /* Search still works without location permission. */ }
                }
                const nearby = currentLocation
                    ? `&lat=${encodeURIComponent(currentLocation.coords.latitude)}&lng=${encodeURIComponent(currentLocation.coords.longitude)}`
                    : '';
                const response = await fetch(`../api/search_address.php?q=${encodeURIComponent(address)}${nearby}`);
                const payload = await response.json();
                const results = payload.status === 'success' ? payload.data : [];
                if (!results.length) {
                    document.getElementById('address-picker-status').textContent = 'No matching address found. Click the map to pin the exact location.';
                    return;
                }
                document.getElementById('address-picker-status').textContent = 'Choose the correct result, or click the map to pin it.';
                results.forEach((result, index) => {
                    const button = document.createElement('button');
                    button.type = 'button'; button.className = 'address-picker-result';
                    const title = document.createElement('strong'); title.textContent = result.name;
                    const detail = document.createElement('small'); detail.textContent = result.address;
                    button.append(title, detail);
                    button.addEventListener('click', () => showAddressPickerLocation({ ...result, index }));
                    resultsBox.appendChild(button);
                });
                showAddressPickerLocation({ ...results[0], index: 0 });
            } catch (error) {
                document.getElementById('address-picker-status').textContent = 'Unable to search now. Click the map to pin the exact location.';
            }
        }

        function confirmAddressLocation() {
            if (!selectedAddressLocation) { alert('Choose a search result or click a point on the map.'); return; }
            addressPickerField('latitude').value = selectedAddressLocation.lat;
            addressPickerField('longitude').value = selectedAddressLocation.lng;
            if (selectedAddressLocation.address) addressPickerField('delivery_address').value = selectedAddressLocation.address;
            closeAddressPicker();
        }

        function closeAddressPicker() { document.getElementById('addressPickerModal').style.display = 'none'; }

        function openRiderLocateModal(context) {
            locateContext = context;
            document.getElementById('locate-rider-title').textContent = context.rider_name || 'Rider Location';
            document.getElementById('locate-parcel-text').textContent =
                `Parcel ${context.tracking_number || ''} → ${context.recipient_name || ''} (${context.delivery_address || ''})`;
            document.getElementById('locate-map-status').textContent = 'Loading rider location...';
            document.getElementById('riderLocateModal').style.display = 'flex';

            if (!locateMap) {
                const malaysiaBounds = [[0.85, 99.60], [7.40, 119.30]];
                locateMap = L.map('rider-locate-map').fitBounds(malaysiaBounds, { padding: [18, 18] });
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '© OpenStreetMap contributors'
                }).addTo(locateMap);
            }

            setTimeout(() => {
                if (locateMap) {
                    locateMap.invalidateSize();
                }
                refreshRiderLocateMap();
            }, 250);

            if (locateRefreshTimer) {
                clearInterval(locateRefreshTimer);
            }
            locateRefreshTimer = setInterval(refreshRiderLocateMap, 10000);
        }

        function closeRiderLocateModal() {
            document.getElementById('riderLocateModal').style.display = 'none';
            if (locateRefreshTimer) {
                clearInterval(locateRefreshTimer);
                locateRefreshTimer = null;
            }
        }

        function clearLocateLayers() {
            Object.keys(locateLayers).forEach(key => {
                if (locateLayers[key] && locateMap) {
                    locateMap.removeLayer(locateLayers[key]);
                    locateLayers[key] = null;
                }
            });
        }

        async function refreshRiderLocateMap() {
            if (!locateContext || !locateMap) {
                return;
            }

            try {
                const params = new URLSearchParams({
                    rider_id: locateContext.rider_id,
                    parcel_id: locateContext.parcel_id || ''
                });
                const response = await fetch(`../api/get_rider_tracking.php?${params.toString()}`, {
                    credentials: 'same-origin'
                });
                const result = await response.json();

                if (result.status !== 'success' || !result.data) {
                    document.getElementById('locate-map-status').textContent =
                        result.message || 'Unable to load rider location.';
                    return;
                }

                const data = result.data;
                const onlineBadge = document.getElementById('locate-online-badge');
                if (data.is_online === 1) {
                    onlineBadge.className = 'locate-status online';
                    onlineBadge.innerHTML = '<i class="fa-solid fa-circle"></i> Online';
                } else {
                    onlineBadge.className = 'locate-status offline';
                    onlineBadge.innerHTML = '<i class="fa-solid fa-circle"></i> Offline';
                }

                document.getElementById('locate-gps-time').textContent = data.recorded_at || 'No GPS yet';
                document.getElementById('locate-vehicle').textContent = data.vehicle_number || 'N/A';

                clearLocateLayers();

                if (!data.has_gps) {
                    document.getElementById('locate-map-status').textContent =
                        'Rider has no GPS ping yet. Ask rider to open the rider portal and allow location.';
                    if (data.parcel && data.parcel.dest_latitude && data.parcel.dest_longitude) {
                        locateLayers.dest = L.circleMarker(
                            [data.parcel.dest_latitude, data.parcel.dest_longitude],
                            { radius: 8, color: '#fff', weight: 2, fillColor: '#ef4444', fillOpacity: 1 }
                        ).addTo(locateMap).bindPopup('Recipient location');
                        locateMap.setView([data.parcel.dest_latitude, data.parcel.dest_longitude], 14);
                    }
                    return;
                }

                const riderLat = parseFloat(data.latitude);
                const riderLng = parseFloat(data.longitude);

                locateLayers.rider = L.marker([riderLat, riderLng], {
                    icon: L.divIcon({
                        className: 'custom-rider-icon',
                        html: '<div style="background:#0284c7;color:#fff;width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.3);"><i class="fa-solid fa-motorcycle"></i></div>',
                        iconSize: [34, 34],
                        iconAnchor: [17, 17]
                    })
                }).addTo(locateMap).bindPopup(`<b>${data.rider_name}</b><br>${data.vehicle_number || ''}`);

                const bounds = L.latLngBounds([[riderLat, riderLng]]);

                if (data.parcel && data.parcel.dest_latitude && data.parcel.dest_longitude) {
                    const destLat = parseFloat(data.parcel.dest_latitude);
                    const destLng = parseFloat(data.parcel.dest_longitude);

                    locateLayers.dest = L.circleMarker([destLat, destLng], {
                        radius: 8,
                        color: '#ffffff',
                        weight: 2,
                        fillColor: '#ef4444',
                        fillOpacity: 1
                    }).addTo(locateMap).bindPopup(`Recipient: ${data.parcel.recipient_name}`);

                    bounds.extend([destLat, destLng]);

                    document.getElementById('locate-map-status').textContent = 'Rider and recipient locations shown.';
                } else {
                    document.getElementById('locate-map-status').textContent = 'Rider location shown. No recipient location is saved for this parcel.';
                }

                locateMap.fitBounds(bounds, { padding: [40, 40] });
            } catch (error) {
                console.error('Locate rider failed:', error);
                document.getElementById('locate-map-status').textContent = 'Failed to load rider location.';
            }
        }

        function openEditModal(parcel) {
            document.getElementById('edit_parcel_id').value = parcel.id;
            document.getElementById('edit_recipient_name').value = parcel.recipient_name;
            document.getElementById('edit_recipient_phone').value = parcel.recipient_phone;
            document.getElementById('edit_delivery_address').value = parcel.delivery_address;
            document.getElementById('edit_latitude').value = parcel.latitude || '';
            document.getElementById('edit_longitude').value = parcel.longitude || '';
            document.getElementById('edit_assigned_rider_id').value = parcel.assigned_rider_id || '';
            document.getElementById('edit_status').value = parcel.status;
            
            // Show proof image in modal if available
            let proofContainer = document.getElementById('edit_proof_container');
            if (parcel.proof_image) {
                let cleanPath = parcel.proof_image.startsWith('../') ? parcel.proof_image.substring(3) : parcel.proof_image;
                document.getElementById('edit_proof_img').src = '../' + cleanPath;
                document.getElementById('edit_proof_link').href = '../' + cleanPath;
                proofContainer.style.display = 'block';
            } else {
                proofContainer.style.display = 'none';
            }
            
            document.getElementById('editModal').style.display = 'flex';
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        window.onclick = function(event) {
            let modal = document.getElementById('editModal');
            if (event.target === modal) {
                closeEditModal();
            }

            let locateModal = document.getElementById('riderLocateModal');
            if (event.target === locateModal) {
                closeRiderLocateModal();
            }
        };

        // Instant Auto-Search Script
        const searchInput = document.querySelector('.search-input');
        if (searchInput) {
            if (searchInput.value) {
                searchInput.focus();
                searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
            }
            searchInput.addEventListener('input', function() {
                this.form.submit();
            });
        }
    </script>

</body>
</html>
