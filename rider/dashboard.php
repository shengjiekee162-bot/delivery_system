<?php
// Start session if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include required core files
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rider_status.php';

// Protect page route for Riders only
require_role('rider');

$db = get_db_connection();
$user_id = $_SESSION['user_id'] ?? null;
mark_rider_online($db, (string)$user_id);

$error_message = '';
$success_message = '';

// Helper function to generate UUID v4 if not defined in functions.php
if (!function_exists('generate_uuid')) {
    function generate_uuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

// Helper function to sanitize output securely
if (!function_exists('sanitize')) {
    function sanitize($data) {
        return htmlspecialchars(trim($data ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

// -------------------------------------------------------------
// AJAX Endpoint: Update Rider Live GPS Location
// -------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'update_location') {
    header('Content-Type: application/json');
    $lat = filter_input(INPUT_POST, 'latitude', FILTER_VALIDATE_FLOAT);
    $lng = filter_input(INPUT_POST, 'longitude', FILTER_VALIDATE_FLOAT);
    $rider_id = $_POST['rider_id'] ?? '';

    if ($lat !== false && $lng !== false && !empty($rider_id)) {
        try {
            $stmtLoc = $db->prepare("
                INSERT INTO rider_locations (id, rider_id, latitude, longitude, recorded_at) 
                VALUES (:id, :rider_id, :lat, :lng, NOW())
            ");
            $stmtLoc->execute([
                ':id'       => generate_uuid(),
                ':rider_id' => $rider_id,
                ':lat'      => $lat,
                ':lng'      => $lng
            ]);

            $stmtOnline = $db->prepare("
                UPDATE riders
                SET is_online = 1, last_active_at = NOW()
                WHERE id = :rider_id AND deleted_at IS NULL
            ");
            $stmtOnline->execute([':rider_id' => $rider_id]);

            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['status' => 'invalid', 'message' => 'Invalid coordinates']);
    }
    exit;
}

// -------------------------------------------------------------
// Verify Session User Exists in Database
// -------------------------------------------------------------
if ($user_id) {
    $stmtCheckUser = $db->prepare("SELECT id FROM users WHERE id = :user_id AND deleted_at IS NULL LIMIT 1");
    $stmtCheckUser->execute([':user_id' => $user_id]);
    if (!$stmtCheckUser->fetch()) {
        session_unset();
        session_destroy();
        header("Location: ../login.php?error=invalid_session");
        exit();
    }
}

// -------------------------------------------------------------
// Handle Form Submissions: Toggle Status & Parcel Updates
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Update Parcel Delivery Status (With Camera Capture & Proof Upload to `delivery_photos`)
    if (isset($_POST['action']) && $_POST['action'] === 'update_parcel_status') {
        $parcel_id  = $_POST['parcel_id'] ?? '';
        $new_status = $_POST['status'] ?? '';
        $allowed    = ['out_for_delivery', 'delivered', 'failed'];

        if (in_array($new_status, $allowed) && !empty($parcel_id)) {
            try {
                $proof_file_path = null;

                // Handle Camera Capture / File Upload for Delivery Proof when marked as delivered
                if ($new_status === 'delivered' && isset($_FILES['proof_image']) && $_FILES['proof_image']['error'] === UPLOAD_ERR_OK) {
                    $fileTmpPath   = $_FILES['proof_image']['tmp_name'];
                    $fileName      = $_FILES['proof_image']['name'];
                    $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    
                    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
                    if (in_array($fileExtension, $allowedExtensions)) {
                        $newFileName = 'proof_' . $parcel_id . '_' . time() . '.' . $fileExtension;
                        $uploadFileDir = __DIR__ . '/../uploads/delivery_proofs/';
                        
                        if (!is_dir($uploadFileDir)) {
                            mkdir($uploadFileDir, 0755, true);
                        }
                        
                        $dest_path = $uploadFileDir . $newFileName;
                        if (move_uploaded_file($fileTmpPath, $dest_path)) {
                            $proof_file_path = 'uploads/delivery_proofs/' . $newFileName;
                        } else {
                            throw new Exception("Error saving the captured delivery proof photo.");
                        }
                    } else {
                        throw new Exception("Invalid image format. Allowed formats: JPG, PNG, WEBP.");
                    }
                }

                // Update Parcel Status
                $stmtUpdate = $db->prepare("
                    UPDATE parcels 
                    SET status = :status 
                    WHERE id = :parcel_id AND deleted_at IS NULL
                ");
                $stmtUpdate->execute([':status' => $new_status, ':parcel_id' => $parcel_id]);

                // Insert into dedicated delivery_photos table if image provided
                if ($proof_file_path) {
                    $stmtPhoto = $db->prepare("
                        INSERT INTO delivery_photos (id, parcel_id, file_path, uploaded_at)
                        VALUES (:id, :parcel_id, :file_path, NOW())
                    ");
                    $stmtPhoto->execute([
                        ':id'        => generate_uuid(),
                        ':parcel_id' => $parcel_id,
                        ':file_path' => $proof_file_path
                    ]);
                }

                // Record status history
                $stmtHistory = $db->prepare("
                    INSERT INTO parcel_status_history (id, parcel_id, status, changed_by_user_id, remarks, created_at)
                    VALUES (:id, :parcel_id, :status, :user_id, :remarks, NOW())
                ");
                $stmtHistory->execute([
                    ':id' => generate_uuid(),
                    ':parcel_id' => $parcel_id,
                    ':status' => $new_status,
                    ':user_id' => $user_id,
                    ':remarks' => $proof_file_path ? 'Delivered with camera photo proof stored' : 'Updated via Rider Dashboard'
                ]);

                $success_message = "Parcel status updated successfully!";
            } catch (Exception $e) {
                $error_message = "Failed to update parcel: " . $e->getMessage();
            }
        }
    }
}

// -------------------------------------------------------------
// Fetch Current Rider & User Details dynamically
// -------------------------------------------------------------
$rider = null;
try {
    $stmtRider = $db->prepare("
        SELECT 
            u.id AS user_id,
            u.name AS user_name,
            u.email AS user_email,
            u.profile_image AS user_profile_image,
            u.role AS user_role,
            r.id AS rider_id,
            r.phone AS vehicle_phone,
            r.vehicle_number,
            r.is_online,
            r.last_active_at
        FROM users u
        LEFT JOIN riders r ON r.user_id = u.id AND r.deleted_at IS NULL
        WHERE u.id = :user_id 
          AND u.deleted_at IS NULL
        LIMIT 1
    ");
    $stmtRider->execute([':user_id' => $user_id]);
    $rider = $stmtRider->fetch(PDO::FETCH_ASSOC);

    // Auto-create rider profile if user is a rider but lacks a riders table entry
    if ($rider && empty($rider['rider_id']) && $user_id) {
        $new_rider_id = generate_uuid();
        $stmtAutoCreate = $db->prepare("
            INSERT INTO riders (id, user_id, phone, vehicle_number, is_online, last_active_at)
            VALUES (:id, :user_id, 'N/A', 'N/A', 0, NOW())
        ");
        $stmtAutoCreate->execute([':id' => $new_rider_id, ':user_id' => $user_id]);

        $stmtRider->execute([':user_id' => $user_id]);
        $rider = $stmtRider->fetch(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $error_message = "Database query error: " . $e->getMessage();
}

// -------------------------------------------------------------
// Dynamic Profile Image & Avatar Resolution (Enhanced)
// -------------------------------------------------------------
$rider_display_name = $rider['user_name'] ?? $_SESSION['name'] ?? $_SESSION['user_name'] ?? 'Rider';
$raw_db_image = trim($rider['user_profile_image'] ?? $_SESSION['profile_image'] ?? '');

// Dynamic initial avatar generation fallback URL
$default_avatar = "https://ui-avatars.com/api/?name=" . urlencode($rider_display_name) . "&background=0284c7&color=ffffff&bold=true&rounded=true";

$profile_pic = $default_avatar;

if (!empty($raw_db_image)) {
    // If external URL or Data URI
    if (preg_match('/^(http|https|data:)/i', $raw_db_image)) {
        $profile_pic = $raw_db_image;
    } else {
        // Strip relative dots and leading slashes
        $clean_path = ltrim(preg_replace('#^(\.\./|\./)+#', '', $raw_db_image), '/\\');
        $filename   = basename($clean_path);

        // Candidate 1: Direct path from root (e.g. uploads/profile_images/pic.jpg)
        $cand1_server = __DIR__ . '/../' . $clean_path;
        $cand1_web    = '../' . $clean_path;

        // Candidate 2: Specifically inside uploads/profile_images/
        $cand2_server = __DIR__ . '/../uploads/profile_images/' . $filename;
        $cand2_web    = '../uploads/profile_images/' . $filename;

        // Candidate 3: Specifically inside uploads/
        $cand3_server = __DIR__ . '/../uploads/' . $filename;
        $cand3_web    = '../uploads/' . $filename;

        if (!empty($clean_path) && file_exists($cand1_server) && is_file($cand1_server)) {
            $profile_pic = $cand1_web;
        } elseif (file_exists($cand2_server) && is_file($cand2_server)) {
            $profile_pic = $cand2_web;
        } elseif (file_exists($cand3_server) && is_file($cand3_server)) {
            $profile_pic = $cand3_web;
        }
    }
}

// Update session variable so profile image is synced across app
$_SESSION['profile_image'] = $raw_db_image;

// -------------------------------------------------------------
// Fetch Active Assigned Parcels
// -------------------------------------------------------------
$assigned_parcels = [];
if ($rider && !empty($rider['rider_id'])) {
    try {
        $sqlParcels = "
            SELECT id, tracking_number, recipient_name, recipient_phone, 
                   delivery_address, latitude, longitude, status, created_at
            FROM parcels
            WHERE assigned_rider_id = :rider_id
              AND status IN ('pending', 'out_for_delivery')
              AND deleted_at IS NULL
            ORDER BY created_at DESC
        ";

        $stmtParcels = $db->prepare($sqlParcels);
        $stmtParcels->execute([':rider_id' => $rider['rider_id']]);
        $assigned_parcels = $stmtParcels->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $error_message = "Error fetching parcels: " . $e->getMessage();
    }
}

$nav_parcel = null;
foreach ($assigned_parcels as $parcel_item) {
    if ($parcel_item['status'] === 'out_for_delivery') {
        $nav_parcel = $parcel_item;
        break;
    }
}
if (!$nav_parcel && !empty($assigned_parcels)) {
    $nav_parcel = $assigned_parcels[0];
}

$nav_destination = null;
$last_rider_gps = null;

if ($nav_parcel && $rider && !empty($rider['rider_id'])) {
    require_once __DIR__ . '/../includes/http_client.php';
    require_once __DIR__ . '/../config/services.php';

    if (!function_exists('rider_geocode_address')) {
        function rider_geocode_address(string $address): array
        {
            $address = normalize_address_query($address);
            if ($address === '') {
                return [null, null];
            }

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
                return [(float)$results[0]['lat'], (float)$results[0]['lng']];
            }

            foreach (build_address_search_variants($address) as $variant) {
                $results = ors_geocode_malaysia($variant, $focus['lat'], $focus['lng'], 3, $region);
                if ($region) {
                    $results = filter_results_by_address_region($results, $region);
                }
                if (!empty($results)) {
                    return [(float)$results[0]['lat'], (float)$results[0]['lng']];
                }
            }

            return [null, null];
        }
    }

    $dest_lat = isset($nav_parcel['latitude']) ? (float)$nav_parcel['latitude'] : 0.0;
    $dest_lng = isset($nav_parcel['longitude']) ? (float)$nav_parcel['longitude'] : 0.0;

    if ($dest_lat < 0.8 || $dest_lat > 7.5 || $dest_lng < 98.5 || $dest_lng > 119.5) {
        list($dest_lat, $dest_lng) = rider_geocode_address((string)$nav_parcel['delivery_address']);
    }

    if ($dest_lat !== null && $dest_lng !== null && $dest_lat >= 0.8 && $dest_lat <= 7.5 && $dest_lng >= 98.5 && $dest_lng <= 119.5) {
        $nav_destination = [
            'lat' => $dest_lat,
            'lng' => $dest_lng,
        ];
    }

    try {
        $stmtLastGps = $db->prepare("
            SELECT latitude, longitude
            FROM rider_locations
            WHERE rider_id = :rider_id
            ORDER BY recorded_at DESC
            LIMIT 1
        ");
        $stmtLastGps->execute([':rider_id' => $rider['rider_id']]);
        $gps_row = $stmtLastGps->fetch(PDO::FETCH_ASSOC);

        if ($gps_row) {
            $gps_lat = (float)$gps_row['latitude'];
            $gps_lng = (float)$gps_row['longitude'];
            if ($gps_lat >= 0.8 && $gps_lat <= 7.5 && $gps_lng >= 98.5 && $gps_lng <= 119.5) {
                $last_rider_gps = [
                    'lat' => $gps_lat,
                    'lng' => $gps_lng,
                ];
            }
        }
    } catch (Exception $e) {
        // Non-fatal: map can still render destination-only view.
    }
}

$page_title = "Rider Delivery Portal";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($page_title) ?></title>

    <!-- FontAwesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <?php if ($nav_parcel): ?>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <?php endif; ?>

    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #f1f5f9; color: #0f172a; min-height: 100vh; display: flex; flex-direction: column; }

        .navbar { background-color: #0f172a; color: #ffffff; height: 60px; display: flex; align-items: center; justify-content: space-between; padding: 0 20px; }
        .navbar-brand { font-size: 1.1rem; font-weight: 700; display: flex; align-items: center; gap: 10px; color: #ffffff; text-decoration: none; }
        .btn-logout { background-color: #ef4444; color: #ffffff; padding: 6px 14px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 600; transition: background 0.2s; }
        .btn-logout:hover { background-color: #dc2626; }

        .user-profile-menu { display: flex; align-items: center; gap: 10px; color: #ffffff; text-decoration: none; font-size: 14px; font-weight: 500; }
        .nav-avatar { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 2px solid #0284c7; background-color: #0284c7; }

        .container { max-width: 900px; margin: 24px auto; padding: 0 16px; width: 100%; flex: 1; }

        .card { background: #ffffff; border-radius: 12px; padding: 24px; border: 1px solid #e2e8f0; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px; }
        .card-title { font-size: 1.25rem; font-weight: 700; }

        .alert { padding: 12px 16px; border-radius: 8px; font-size: 14px; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .alert-error { background-color: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-success { background-color: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }

        .status-box { display: flex; align-items: center; justify-content: space-between; background-color: #f8fafc; padding: 16px; border-radius: 8px; border: 1px solid #e2e8f0; }
        .status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 20px; font-weight: 700; font-size: 13px; }
        .status-online { background-color: #dcfce7; color: #15803d; }
        .status-offline { background-color: #f1f5f9; color: #64748b; }

        .btn-toggle { background-color: #0284c7; color: #ffffff; border: none; padding: 8px 16px; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 13px; }
        .btn-toggle:hover { background-color: #0369a1; }

        .parcel-item { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 12px; }
        .parcel-item h3 { font-size: 1rem; color: #0284c7; margin-bottom: 8px; display: flex; justify-content: space-between; }
        .parcel-item p { font-size: 13px; color: #475569; margin-bottom: 4px; }
        .action-btns { margin-top: 12px; display: flex; gap: 8px; flex-wrap: wrap; }
        
        .btn-action { border: none; padding: 8px 14px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; color: #fff; }
        .btn-start { background-color: #3b82f6; }
        .btn-deliver { background-color: #22c55e; }
        .btn-fail { background-color: #ef4444; }

        /* Modal for Camera/Photo Proof */
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); justify-content: center; align-items: center; z-index: 2000; padding: 16px; }
        .modal-content { background: #fff; padding: 20px; border-radius: 12px; max-width: 400px; width: 100%; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .modal-header { font-size: 1.1rem; font-weight: 700; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; }
        .close-modal { background: none; border: none; font-size: 1.2rem; cursor: pointer; color: #64748b; }

        #delivery-map { width: 100%; height: 320px; border-radius: 10px; border: 1px solid #cbd5e1; margin-top: 12px; }
        .map-legend { display: flex; gap: 16px; flex-wrap: wrap; margin-top: 10px; font-size: 12px; color: #475569; }
        .map-legend span { display: inline-flex; align-items: center; gap: 6px; }
        .map-legend i { width: 18px; height: 4px; border-radius: 999px; display: inline-block; }
    </style>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap">
    <link rel="stylesheet" href="../assets/css/portal-theme.css">
</head>
<body>

    <nav class="navbar">
        <a href="dashboard.php" class="navbar-brand">
            <i class="fa-solid fa-motorcycle"></i>
            <span>Rider Delivery Portal</span>
        </a>
        <div style="display:flex; align-items:center; gap: 16px;">
            <a href="profile.php" class="user-profile-menu">
                <img src="<?= sanitize($profile_pic) ?>" 
                     alt="<?= sanitize($rider_display_name) ?>" 
                     class="nav-avatar"
                     data-fallback="<?= sanitize($default_avatar) ?>"
                     onerror="if(this.src !== this.dataset.fallback) { this.src = this.dataset.fallback; }">
                <span><?= sanitize($rider_display_name) ?></span>
            </a>
            <a href="../logout.php" class="btn-logout"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
        </div>
    </nav>

    <div class="container">
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= sanitize($error_message) ?></div>
        <?php endif; ?>

        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= sanitize($success_message) ?></div>
        <?php endif; ?>

        <!-- Duty Status Card -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fa-solid fa-signal"></i> Duty Status</h2>
                <div>
                    <?php if (($rider['is_online'] ?? 0) == 1): ?>
                        <span class="status-badge status-online"><i class="fa-solid fa-circle"></i> Online & Radar Active</span>
                    <?php else: ?>
                        <span class="status-badge status-offline"><i class="fa-solid fa-circle"></i> Offline</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="status-box">
                <div>
                    <p style="font-size: 14px; font-weight: 600;">Vehicle Number: <?= sanitize($rider['vehicle_number'] ?? 'N/A') ?></p>
                    <p style="font-size: 13px; color: #64748b;">You are shown as online while the rider portal is open. Closing the page or logging out will set you offline.</p>
                </div>
            </div>
        </div>

        <?php if ($nav_parcel): ?>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fa-solid fa-route"></i> Delivery Navigation</h2>
            </div>
            <p style="font-size: 13px; color: #475569; margin-bottom: 8px;">
                <strong><?= sanitize($nav_parcel['tracking_number']) ?></strong> → <?= sanitize($nav_parcel['delivery_address']) ?>
            </p>
            <div id="delivery-map"></div>
            <div class="map-legend">
                <span><i style="background:#0284c7;"></i> Planned route</span>
                <span><i style="background:#22c55e;"></i> Your position</span>
                <span><i style="background:#ef4444;"></i> Recipient</span>
            </div>
            <p id="route-status-text" style="font-size: 13px; color: #64748b; margin-top: 10px;">Waiting for GPS...</p>
        </div>
        <?php endif; ?>

        <!-- Assigned Deliveries Card -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fa-solid fa-box-open"></i> Active Assigned Parcels (<?= count($assigned_parcels) ?>)</h2>
            </div>

            <?php if (empty($assigned_parcels)): ?>
                <p style="color: #64748b; font-size: 14px; text-align: center; padding: 20px 0;">No active deliveries assigned at the moment.</p>
            <?php else: ?>
                <?php foreach ($assigned_parcels as $parcel): ?>
                    <div class="parcel-item">
                        <h3>
                            <span><i class="fa-solid fa-barcode"></i> <?= sanitize($parcel['tracking_number']) ?></span>
                            <span style="font-size: 12px; padding: 4px 8px; border-radius: 4px; background: #e0f2fe; color: #0369a1;">
                                <?= sanitize(strtoupper(str_replace('_', ' ', $parcel['status']))) ?>
                            </span>
                        </h3>
                        <p><strong><i class="fa-solid fa-user"></i> Recipient:</strong> <?= sanitize($parcel['recipient_name']) ?> (<?= sanitize($parcel['recipient_phone'] ?? 'N/A') ?>)</p>
                        <p><strong><i class="fa-solid fa-location-dot"></i> Address:</strong> <?= sanitize($parcel['delivery_address']) ?></p>

                        <div class="action-btns">
                            <?php if ($parcel['status'] === 'pending'): ?>
                                <form method="POST" action="dashboard.php" style="display:inline;">
                                    <input type="hidden" name="action" value="update_parcel_status">
                                    <input type="hidden" name="parcel_id" value="<?= sanitize($parcel['id']) ?>">
                                    <input type="hidden" name="status" value="out_for_delivery">
                                    <button type="submit" class="btn-action btn-start"><i class="fa-solid fa-truck-fast"></i> Start Delivery</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($parcel['status'] === 'out_for_delivery'): ?>
                                <!-- Opens modal to capture photo or upload proof -->
                                <button type="button" class="btn-action btn-deliver" onclick="openProofModal('<?= sanitize($parcel['id']) ?>')">
                                    <i class="fa-solid fa-camera"></i> Mark Delivered & Upload Proof
                                </button>

                                <form method="POST" action="dashboard.php" style="display:inline;">
                                    <input type="hidden" name="action" value="update_parcel_status">
                                    <input type="hidden" name="parcel_id" value="<?= sanitize($parcel['id']) ?>">
                                    <input type="hidden" name="status" value="failed">
                                    <button type="submit" class="btn-action btn-fail"><i class="fa-solid fa-circle-xmark"></i> Delivery Failed</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Proof Photo Capture & Upload Modal -->
    <div id="proofModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span><i class="fa-solid fa-camera-retro"></i> Upload Delivery Proof</span>
                <button type="button" class="close-modal" onclick="closeProofModal()">&times;</button>
            </div>
            <form method="POST" action="dashboard.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_parcel_status">
                <input type="hidden" name="parcel_id" id="modalParcelId" value="">
                <input type="hidden" name="status" value="delivered">

                <p style="font-size: 13px; color: #475569; margin-bottom: 12px;">
                    Take a photo using your mobile camera or upload an image file from your device.
                </p>

                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Delivery Proof Photo:</label>
                    <!-- capture="environment" opens rear camera directly on supported mobile devices -->
                    <input type="file" name="proof_image" accept="image/*" capture="environment" required style="width: 100%; font-size: 13px; padding: 6px; border: 1px solid #cbd5e1; border-radius: 6px;">
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 8px;">
                    <button type="button" class="btn-action" style="background-color: #64748b;" onclick="closeProofModal()">Cancel</button>
                    <button type="submit" class="btn-action btn-deliver"><i class="fa-solid fa-upload"></i> Submit Proof</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Live GPS Tracking Script -->
    <?php if ($rider && !empty($rider['rider_id'])): ?>
    <?php if ($nav_parcel): ?>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <?php endif; ?>
    <script>
        const riderId = "<?= sanitize($rider['rider_id']) ?>";
        <?php if ($nav_parcel): ?>
        const navParcel = <?= json_encode([
            'tracking_number' => $nav_parcel['tracking_number'],
            'recipient_name'  => $nav_parcel['recipient_name'],
            'delivery_address'=> $nav_parcel['delivery_address'],
        ], JSON_UNESCAPED_UNICODE) ?>;
        const navDestination = <?= json_encode($nav_destination, JSON_UNESCAPED_UNICODE) ?>;
        const lastKnownGps = <?= json_encode($last_rider_gps, JSON_UNESCAPED_UNICODE) ?>;

        let deliveryMap = null;
        let riderMarker = null;
        let destMarker = null;
        let routeLine = null;
        let lastRouteKey = '';

        function initDeliveryMap() {
            const malaysiaBounds = [[0.85, 99.60], [7.40, 119.30]];
            deliveryMap = L.map('delivery-map');
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '© OpenStreetMap contributors'
            }).addTo(deliveryMap);

            if (navDestination?.lat != null && navDestination?.lng != null) {
                deliveryMap.setView([navDestination.lat, navDestination.lng], 13);
            } else {
                deliveryMap.fitBounds(malaysiaBounds, { padding: [18, 18] });
            }

            setTimeout(() => {
                if (deliveryMap) {
                    deliveryMap.invalidateSize();
                }
            }, 250);
        }

        function getDestinationCoords() {
            if (!navDestination || navDestination.lat == null || navDestination.lng == null) {
                return null;
            }
            return {
                lat: parseFloat(navDestination.lat),
                lng: parseFloat(navDestination.lng)
            };
        }

        function showDestinationOnly(statusText) {
            const destination = getDestinationCoords();
            if (!destination || !deliveryMap) {
                return;
            }

            if (destMarker) {
                destMarker.setLatLng([destination.lat, destination.lng]);
            } else {
                destMarker = L.circleMarker([destination.lat, destination.lng], {
                    radius: 8,
                    color: '#ffffff',
                    weight: 2,
                    fillColor: '#ef4444',
                    fillOpacity: 1
                }).addTo(deliveryMap).bindPopup(`Recipient: ${navParcel.recipient_name}`);
            }

            deliveryMap.setView([destination.lat, destination.lng], 14);
            document.getElementById('route-status-text').textContent = statusText;
        }

        async function drawDeliveryRoute(startLat, startLng, destLat, destLng) {
            if (!deliveryMap) {
                return;
            }

            const routeKey = `${startLat.toFixed(5)},${startLng.toFixed(5)}-${destLat.toFixed(5)},${destLng.toFixed(5)}`;
            if (routeKey === lastRouteKey && routeLine) {
                if (riderMarker) {
                    riderMarker.setLatLng([startLat, startLng]);
                }
                return;
            }
            lastRouteKey = routeKey;

            const params = new URLSearchParams({
                start_lat: startLat,
                start_lng: startLng,
                end_lat: destLat,
                end_lng: destLng
            });

            let coords = [[startLat, startLng], [destLat, destLng]];
            let statusText = 'Showing estimated straight-line route.';

            try {
                const response = await fetch(`../api/get_route.php?${params.toString()}`, {
                    credentials: 'same-origin'
                });
                const result = await response.json();
                if (result.status === 'success' && result.data && Array.isArray(result.data.coordinates) && result.data.coordinates.length > 1) {
                    coords = result.data.coordinates;
                    statusText = `Planned route: ${result.data.distance_km} km (~${result.data.duration_min} mins)`;
                } else if (result.message) {
                    statusText = `Straight-line route shown (${result.message}).`;
                }
            } catch (error) {
                console.warn('Route lookup failed:', error);
            }

            if (routeLine) {
                deliveryMap.removeLayer(routeLine);
            }
            routeLine = L.polyline(coords, {
                color: '#0284c7',
                weight: 6,
                opacity: 0.85
            }).addTo(deliveryMap);

            if (riderMarker) {
                riderMarker.setLatLng([startLat, startLng]);
            } else {
                riderMarker = L.circleMarker([startLat, startLng], {
                    radius: 8,
                    color: '#ffffff',
                    weight: 2,
                    fillColor: '#22c55e',
                    fillOpacity: 1
                }).addTo(deliveryMap).bindPopup('Your location');
            }

            if (destMarker) {
                destMarker.setLatLng([destLat, destLng]);
            } else {
                destMarker = L.circleMarker([destLat, destLng], {
                    radius: 8,
                    color: '#ffffff',
                    weight: 2,
                    fillColor: '#ef4444',
                    fillOpacity: 1
                }).addTo(deliveryMap).bindPopup(`Recipient: ${navParcel.recipient_name}`);
            }

            deliveryMap.fitBounds(routeLine.getBounds(), { padding: [30, 30] });
            document.getElementById('route-status-text').textContent = statusText;
        }

        async function refreshDeliveryMap(startLat, startLng) {
            const destination = getDestinationCoords();
            if (!destination) {
                document.getElementById('route-status-text').textContent = 'Unable to locate delivery address. Please contact admin to update the parcel address.';
                return;
            }

            await drawDeliveryRoute(startLat, startLng, destination.lat, destination.lng);
        }

        function bootDeliveryMap() {
            initDeliveryMap();

            const destination = getDestinationCoords();
            if (!destination) {
                document.getElementById('route-status-text').textContent = 'Unable to locate delivery address. Please contact admin to update the parcel address.';
                return;
            }

            if (lastKnownGps && lastKnownGps.lat != null && lastKnownGps.lng != null) {
                refreshDeliveryMap(parseFloat(lastKnownGps.lat), parseFloat(lastKnownGps.lng));
                return;
            }

            showDestinationOnly('Recipient location loaded. Allow GPS to draw your route line.');
        }

        document.addEventListener('DOMContentLoaded', bootDeliveryMap);
        <?php endif; ?>

        function sendGPSLocation() {
            if (!("geolocation" in navigator)) {
                <?php if ($nav_parcel): ?>
                showDestinationOnly('Geolocation is not supported in this browser.');
                <?php endif; ?>
                return;
            }

            navigator.geolocation.getCurrentPosition(async position => {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                const formData = new FormData();
                formData.append('rider_id', riderId);
                formData.append('latitude', lat);
                formData.append('longitude', lng);

                fetch('dashboard.php?action=update_location', {
                    method: 'POST',
                    body: formData
                }).catch(err => console.error("GPS location ping failed:", err));

                <?php if ($nav_parcel): ?>
                await refreshDeliveryMap(lat, lng);
                <?php endif; ?>
            }, err => {
                console.warn("Geolocation warning:", err.message);
                <?php if ($nav_parcel): ?>
                if (!routeLine) {
                    showDestinationOnly('Allow location access to draw your route line.');
                }
                <?php endif; ?>
            }, {
                enableHighAccuracy: true,
                timeout: 15000,
                maximumAge: 10000
            });
        }

        sendGPSLocation();
        setInterval(sendGPSLocation, 10000);
    </script>
    <?php endif; ?>

    <script>
        function openProofModal(parcelId) {
            document.getElementById('modalParcelId').value = parcelId;
            document.getElementById('proofModal').style.display = 'flex';
        }

        function closeProofModal() {
            document.getElementById('proofModal').style.display = 'none';
        }

        window.onclick = function(event) {
            const modal = document.getElementById('proofModal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        }
    </script>

    <?php require __DIR__ . '/../includes/rider_presence_script.php'; ?>
</body>
</html>
