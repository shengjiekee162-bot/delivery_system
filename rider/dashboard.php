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
                SET last_active_at = NOW()
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
    if (isset($_POST['action']) && $_POST['action'] === 'update_rider_status') {
        $new_rider_status = $_POST['rider_status'] ?? '';

        if (in_array($new_rider_status, ['online', 'offline'], true)) {
            try {
                set_rider_online_status($db, (string)$user_id, $new_rider_status === 'online' ? 1 : 0);
                $success_message = $new_rider_status === 'online'
                    ? 'You are now online and available for delivery assignments.'
                    : 'You are now offline. GPS tracking and the rider radar are paused.';
            } catch (Exception $e) {
                $error_message = 'Unable to update rider status: ' . $e->getMessage();
            }
        } else {
            $error_message = 'Invalid rider status selected.';
        }
    }

    // 1. Update Parcel Delivery Status (With Camera Capture & Proof Upload to `delivery_photos`)
    if (isset($_POST['action']) && $_POST['action'] === 'update_parcel_status') {
        $parcel_id  = $_POST['parcel_id'] ?? '';
        $new_status = $_POST['status'] ?? '';
        $allowed    = ['out_for_delivery', 'delivered', 'failed'];

        if (in_array($new_status, $allowed) && !empty($parcel_id)) {
            try {
                $proof_file_paths = [];

                // A delivery can have several proof photos (up to five images, 8 MB each).
                if ($new_status === 'delivered') {
                    $uploads = $_FILES['proof_images'] ?? null;
                    if (!$uploads || !is_array($uploads['name'] ?? null)) {
                        throw new Exception('Please upload at least one delivery proof photo.');
                    }

                    $fileCount = count($uploads['name']);
                    if ($fileCount < 1 || $fileCount > 5) {
                        throw new Exception('Please upload between 1 and 5 delivery proof photos.');
                    }

                    $uploadFileDir = __DIR__ . '/../uploads/delivery_proofs/';
                    if (!is_dir($uploadFileDir) && !mkdir($uploadFileDir, 0755, true) && !is_dir($uploadFileDir)) {
                        throw new Exception('Unable to create the delivery proof upload folder.');
                    }

                    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
                    foreach ($uploads['name'] as $index => $fileName) {
                        $uploadError = $uploads['error'][$index] ?? UPLOAD_ERR_NO_FILE;
                        $fileTmpPath = $uploads['tmp_name'][$index] ?? '';
                        $fileSize = (int)($uploads['size'][$index] ?? 0);
                        $fileExtension = strtolower(pathinfo((string)$fileName, PATHINFO_EXTENSION));

                        if ($uploadError !== UPLOAD_ERR_OK) {
                            throw new Exception('One of the delivery proof photos could not be uploaded.');
                        }
                        if (!in_array($fileExtension, $allowedExtensions, true) || $fileSize < 1 || $fileSize > 8 * 1024 * 1024) {
                            throw new Exception('Each proof photo must be JPG, PNG, or WEBP and no larger than 8 MB.');
                        }

                        $newFileName = 'proof_' . $parcel_id . '_' . bin2hex(random_bytes(8)) . '.' . $fileExtension;
                        if (!move_uploaded_file($fileTmpPath, $uploadFileDir . $newFileName)) {
                            throw new Exception('Error saving one of the delivery proof photos.');
                        }
                        $proof_file_paths[] = 'uploads/delivery_proofs/' . $newFileName;
                    }
                }

                // Update Parcel Status
                $stmtUpdate = $db->prepare("
                    UPDATE parcels 
                    SET status = :status 
                    WHERE id = :parcel_id AND deleted_at IS NULL
                ");
                $stmtUpdate->execute([':status' => $new_status, ':parcel_id' => $parcel_id]);

                // Insert every proof image into the dedicated delivery_photos table.
                if ($proof_file_paths) {
                    $stmtPhoto = $db->prepare("
                        INSERT INTO delivery_photos (id, parcel_id, file_path, uploaded_at)
                        VALUES (:id, :parcel_id, :file_path, NOW())
                    ");
                    foreach ($proof_file_paths as $proof_file_path) {
                        $stmtPhoto->execute([
                            ':id'        => generate_uuid(),
                            ':parcel_id' => $parcel_id,
                            ':file_path' => $proof_file_path
                        ]);
                    }
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
                    ':remarks' => $proof_file_paths
                        ? 'Delivered with ' . count($proof_file_paths) . ' proof photo(s) stored'
                        : 'Updated via Rider Dashboard'
                ]);

                $success_message = "Parcel status updated successfully!";

                if ($new_status === 'delivered') {
                    header('Location: completed_orders.php?completed=1');
                    exit;
                }
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
$all_assigned_parcels = [];
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

        $stmtAllParcels = $db->prepare("
            SELECT id, tracking_number, recipient_name, recipient_phone,
                   delivery_address, status, created_at, updated_at
            FROM parcels
            WHERE assigned_rider_id = :rider_id
              AND deleted_at IS NULL
              AND status <> 'delivered'
            ORDER BY updated_at DESC, created_at DESC
        ");
        $stmtAllParcels->execute([':rider_id' => $rider['rider_id']]);
        $all_assigned_parcels = $stmtAllParcels->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $error_message = "Error fetching parcels: " . $e->getMessage();
    }
}

$last_rider_gps = null;
if ($rider && !empty($rider['rider_id']) && !empty($assigned_parcels)) {
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
        // Non-fatal: planned route can still load from delivery plan API.
    }
}

$show_delivery_plan = !empty($assigned_parcels) && $rider && !empty($rider['rider_id']);

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
    <?php if ($show_delivery_plan): ?>
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
        .address-route-trigger { color: #0369a1; text-decoration: underline; text-decoration-style: dotted; cursor: pointer; font: inherit; font-weight: 600; border: 0; background: transparent; padding: 0; text-align: left; }
        .address-route-trigger:hover { color: #075985; }

        /* Modal for Camera/Photo Proof */
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); justify-content: center; align-items: center; z-index: 2000; padding: 16px; }
        .modal-content { background: #fff; padding: 20px; border-radius: 12px; max-width: 400px; width: 100%; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .modal-content.route-modal-content { max-width: 900px; }
        .modal-header { font-size: 1.1rem; font-weight: 700; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; }
        .close-modal { background: none; border: none; font-size: 1.2rem; cursor: pointer; color: #64748b; }

        #delivery-map { width: 100%; height: 360px; border-radius: 10px; border: 1px solid #cbd5e1; margin-top: 12px; }
        #parcel-route-map { width: 100%; height: 420px; border-radius: 10px; border: 1px solid #cbd5e1; margin-top: 12px; }
        .route-options { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
        .route-option { border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; color: #334155; padding: 8px 10px; cursor: pointer; font: inherit; font-size: 12px; text-align: left; }
        .route-option:hover, .route-option.is-active { border-color: #0284c7; background: #e0f2fe; color: #075985; }
        .map-legend { display: flex; gap: 16px; flex-wrap: wrap; margin-top: 10px; font-size: 12px; color: #475569; }
        .map-legend span { display: inline-flex; align-items: center; gap: 6px; }
        .map-legend i { width: 18px; height: 4px; border-radius: 999px; display: inline-block; }
        .plan-stops { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        .plan-stop-chip { padding: 6px 10px; border-radius: 999px; background: #e0f2fe; color: #0369a1; font-size: 12px; font-weight: 700; }
        .order-status-list { display: grid; gap: 10px; }
        .order-status-item { display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 13px 14px; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc; }
        .order-status-meta { min-width: 0; }
        .order-status-meta strong { color: #0284c7; }
        .order-status-meta p { margin-top: 4px; color: #64748b; font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .parcel-status { display: inline-flex; flex-shrink: 0; padding: 5px 9px; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .parcel-status-pending { background: #fef3c7; color: #92400e; }
        .parcel-status-out_for_delivery { background: #dbeafe; color: #1d4ed8; }
        .parcel-status-delivered { background: #dcfce7; color: #15803d; }
        .parcel-status-failed, .parcel-status-failed_delivery { background: #fee2e2; color: #b91c1c; }
        @media (max-width: 560px) { .order-status-item { align-items: flex-start; flex-direction: column; gap: 8px; } }
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
            <a href="completed_orders.php" class="user-profile-menu"><i class="fa-solid fa-circle-check"></i><span>Completed Orders</span></a>
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
                    <p style="font-size: 13px; color: #64748b;">Choose whether you are available for delivery assignments. Offline riders are hidden from the live rider radar.</p>
                </div>
                <form method="POST" action="dashboard.php">
                    <input type="hidden" name="action" value="update_rider_status">
                    <?php if (($rider['is_online'] ?? 0) == 1): ?>
                        <input type="hidden" name="rider_status" value="offline">
                        <button type="submit" class="btn-toggle"><i class="fa-solid fa-toggle-off"></i> Go Offline</button>
                    <?php else: ?>
                        <input type="hidden" name="rider_status" value="online">
                        <button type="submit" class="btn-toggle"><i class="fa-solid fa-toggle-on"></i> Go Online</button>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <?php if ($show_delivery_plan): ?>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fa-solid fa-route"></i> Planned Delivery Route</h2>
            </div>
            <p style="font-size: 13px; color: #475569; margin-bottom: 8px;">
                Suggested stop order based on your current location and active parcels.
            </p>
            <div id="plan-stops" class="plan-stops"></div>
            <div id="delivery-map"></div>
            <div class="map-legend">
                <span><i style="background:#0284c7;"></i> Planned road route</span>
                <span><i style="background:#22c55e;"></i> Your position</span>
                <span><i style="background:#ef4444;"></i> Delivery stops</span>
            </div>
            <p id="route-status-text" style="font-size: 13px; color: #64748b; margin-top: 10px;">Loading planned route...</p>
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
                        <p>
                            <strong><i class="fa-solid fa-location-dot"></i> Address:</strong>
                            <button type="button" class="address-route-trigger" onclick='openParcelRoute(<?= json_encode([
                                'tracking_number' => $parcel['tracking_number'],
                                'recipient_name' => $parcel['recipient_name'],
                                'delivery_address' => $parcel['delivery_address'],
                                'latitude' => $parcel['latitude'],
                                'longitude' => $parcel['longitude'],
                            ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG) ?>)'><?= sanitize($parcel['delivery_address']) ?></button>
                        </p>

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
        <!-- All Order Status Card -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fa-solid fa-clipboard-list"></i> Current Order Status (<?= count($all_assigned_parcels) ?>)</h2>
            </div>

            <?php if (empty($all_assigned_parcels)): ?>
                <p style="color: #64748b; font-size: 14px; text-align: center; padding: 20px 0;">No current or failed orders to show.</p>
            <?php else: ?>
                <div class="order-status-list">
                    <?php foreach ($all_assigned_parcels as $parcel): ?>
                        <div class="order-status-item">
                            <div class="order-status-meta">
                                <strong><i class="fa-solid fa-barcode"></i> <?= sanitize($parcel['tracking_number']) ?></strong>
                                <p><?= sanitize($parcel['recipient_name']) ?> · <?= sanitize($parcel['delivery_address']) ?></p>
                            </div>
                            <span class="parcel-status parcel-status-<?= sanitize($parcel['status']) ?>">
                                <?= sanitize(ucwords(str_replace('_', ' ', $parcel['status']))) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
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
                    Take up to 5 photos using your mobile camera or upload image files from your device.
                </p>

                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Delivery Proof Photos (1–5):</label>
                    <input type="file" name="proof_images[]" accept="image/jpeg,image/png,image/webp" capture="environment" multiple required style="width: 100%; font-size: 13px; padding: 6px; border: 1px solid #cbd5e1; border-radius: 6px;">
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 8px;">
                    <button type="button" class="btn-action" style="background-color: #64748b;" onclick="closeProofModal()">Cancel</button>
                    <button type="submit" class="btn-action btn-deliver"><i class="fa-solid fa-upload"></i> Submit Proof</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Single Parcel Route Modal -->
    <div id="parcelRouteModal" class="modal" aria-hidden="true">
        <div class="modal-content route-modal-content" role="dialog" aria-modal="true" aria-labelledby="parcel-route-title">
            <div class="modal-header">
                <span id="parcel-route-title"><i class="fa-solid fa-route"></i> Route to Delivery Address</span>
                <button type="button" class="close-modal" onclick="closeParcelRoute()" aria-label="Close route map">&times;</button>
            </div>
            <p id="parcel-route-details" style="font-size:13px; color:#475569;"></p>
            <div id="parcel-route-options" class="route-options" aria-label="Available route options"></div>
            <div id="parcel-route-map"></div>
            <p id="parcel-route-status" style="font-size:13px; color:#64748b; margin-top:10px;">Preparing route...</p>
        </div>

    </div>

    <!-- Live GPS Tracking Script -->
    <?php if ($rider && !empty($rider['rider_id'])): ?>
    <?php if ($show_delivery_plan): ?>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <?php endif; ?>
    <script>
        const riderId = "<?= sanitize($rider['rider_id']) ?>";
        const riderIsOnline = <?= (($rider['is_online'] ?? 0) == 1) ? 'true' : 'false' ?>;
        <?php if ($show_delivery_plan): ?>
        const lastKnownGps = <?= json_encode($last_rider_gps, JSON_UNESCAPED_UNICODE) ?>;

        let deliveryMap = null;
        let planLayers = [];
        let parcelRouteMap = null;
        let parcelRouteLayers = [];
        let parcelRouteOptionLayers = [];
        let currentGps = lastKnownGps ? {
            lat: parseFloat(lastKnownGps.lat),
            lng: parseFloat(lastKnownGps.lng)
        } : null;

        function clearPlanLayers() {
            if (!deliveryMap) return;
            planLayers.forEach(layer => deliveryMap.removeLayer(layer));
            planLayers = [];
        }

        function validMalaysiaPoint(lat, lng) {
            return Number.isFinite(lat) && Number.isFinite(lng) && lat >= 0.8 && lat <= 7.5 && lng >= 98.5 && lng <= 119.5;
        }

        function escapeHtml(value) {
            const element = document.createElement('div');
            element.textContent = value == null ? '' : String(value);
            return element.innerHTML;
        }

        function clearParcelRouteLayers() {
            if (!parcelRouteMap) return;
            parcelRouteLayers.forEach(layer => parcelRouteMap.removeLayer(layer));
            parcelRouteLayers = [];
            parcelRouteOptionLayers = [];
            document.getElementById('parcel-route-options').innerHTML = '';
        }

        function showParcelRouteOption(routes, selectedIndex, status) {
            parcelRouteOptionLayers.forEach(layer => {
                const isSelected = layer.routeIndex === selectedIndex;
                layer.setStyle({
                    color: isSelected ? '#0284c7' : '#94a3b8',
                    weight: isSelected ? 5 : 4,
                    opacity: isSelected ? 0.9 : 0.55
                });
                if (isSelected) layer.bringToFront();
            });

            const route = routes[selectedIndex];
            status.textContent = `${selectedIndex === 0 ? 'Main route' : `Backup route ${selectedIndex}`}: ${Number(route.distance_km).toFixed(1)} km · about ${route.duration_min} min`;
            document.querySelectorAll('#parcel-route-options .route-option').forEach((button, index) => {
                button.classList.toggle('is-active', index === selectedIndex);
                button.setAttribute('aria-pressed', index === selectedIndex ? 'true' : 'false');
            });
        }

        function initParcelRouteMap() {
            if (parcelRouteMap) return;
            parcelRouteMap = L.map('parcel-route-map');
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '© OpenStreetMap contributors'
            }).addTo(parcelRouteMap);
        }

        async function getRouteStartPoint() {
            if (currentGps && validMalaysiaPoint(currentGps.lat, currentGps.lng)) return currentGps;

            if (!('geolocation' in navigator)) return lastKnownGps;

            return new Promise(resolve => {
                navigator.geolocation.getCurrentPosition(position => {
                    const point = { lat: position.coords.latitude, lng: position.coords.longitude };
                    if (validMalaysiaPoint(point.lat, point.lng)) {
                        currentGps = point;
                        resolve(point);
                    } else {
                        resolve(lastKnownGps);
                    }
                }, () => resolve(lastKnownGps), {
                    enableHighAccuracy: true,
                    timeout: 6000,
                    maximumAge: 10000
                });
            });
        }

        async function openParcelRoute(parcel) {
            const destination = { lat: parseFloat(parcel.latitude), lng: parseFloat(parcel.longitude) };
            const modal = document.getElementById('parcelRouteModal');
            const status = document.getElementById('parcel-route-status');

            document.getElementById('parcel-route-details').textContent =
                `${parcel.tracking_number || 'Parcel'} · ${parcel.recipient_name || ''} · ${parcel.delivery_address || ''}`;
            modal.style.display = 'flex';
            modal.setAttribute('aria-hidden', 'false');
            initParcelRouteMap();
            clearParcelRouteLayers();
            setTimeout(() => parcelRouteMap.invalidateSize(), 150);

            if (!validMalaysiaPoint(destination.lat, destination.lng)) {
                status.textContent = 'This parcel does not have a valid saved map location yet.';
                return;
            }

            // Always show the saved delivery location first. This keeps the map
            // useful while the browser asks for a fresh GPS location.
            parcelRouteMap.setView([destination.lat, destination.lng], 15);
            const destinationMarker = L.marker([destination.lat, destination.lng])
                .addTo(parcelRouteMap)
                .bindPopup(`<b>${escapeHtml(parcel.tracking_number || 'Delivery address')}</b><br>${escapeHtml(parcel.delivery_address)}`)
                .openPopup();
            parcelRouteLayers.push(destinationMarker);

            status.textContent = 'Getting your current location and planning the driving route...';
            const start = await getRouteStartPoint();
            if (!start || !validMalaysiaPoint(start.lat, start.lng)) {
                status.textContent = 'Unable to get your location. Please allow location access and try again.';
                return;
            }

            const riderMarker = L.circleMarker([start.lat, start.lng], {
                radius: 8, color: '#ffffff', weight: 2, fillColor: '#22c55e', fillOpacity: 1
            }).addTo(parcelRouteMap).bindPopup('Your location');
            parcelRouteLayers.push(riderMarker);

            let routes = [{
                distance_km: null,
                duration_min: null,
                coordinates: [[start.lat, start.lng], [destination.lat, destination.lng]]
            }];
            try {
                const params = new URLSearchParams({
                    start_lat: start.lat, start_lng: start.lng,
                    end_lat: destination.lat, end_lng: destination.lng
                });
                const response = await fetch(`../api/get_route.php?${params.toString()}`, { credentials: 'same-origin' });
                const result = await response.json();
                if (result.status !== 'success' || !Array.isArray(result.data?.coordinates) || result.data.coordinates.length < 2) {
                    throw new Error(result.message || 'Unable to plan driving route.');
                }
                routes = Array.isArray(result.data.routes) && result.data.routes.length
                    ? result.data.routes
                    : [result.data];
                routes = routes.filter(route => Array.isArray(route.coordinates) && route.coordinates.length >= 2);
                if (!routes.length) throw new Error('Unable to plan driving route.');
            } catch (error) {
                status.textContent = `${error.message || 'Driving route unavailable.'} Showing the destination direction instead.`;
            }

            const routeOptions = document.getElementById('parcel-route-options');
            routes.forEach((route, index) => {
                const routeLine = L.polyline(route.coordinates, {
                    color: index === 0 ? '#0284c7' : '#94a3b8', weight: index === 0 ? 5 : 4, opacity: index === 0 ? 0.9 : 0.55
                }).addTo(parcelRouteMap);
                routeLine.routeIndex = index;
                routeLine.on('click', () => showParcelRouteOption(routes, index, status));
                parcelRouteOptionLayers.push(routeLine);

                if (routes.length > 1) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = `route-option${index === 0 ? ' is-active' : ''}`;
                    button.innerHTML = `<strong>${index === 0 ? 'Main route' : `Backup route ${index}`}</strong><br>${Number(route.distance_km).toFixed(1)} km · ${route.duration_min} min`;
                    button.setAttribute('aria-pressed', index === 0 ? 'true' : 'false');
                    button.addEventListener('click', () => showParcelRouteOption(routes, index, status));
                    routeOptions.appendChild(button);
                }
            });
            parcelRouteLayers.push(...parcelRouteOptionLayers);
            if (routes[0].distance_km !== null) {
                showParcelRouteOption(routes, 0, status);
            }
            parcelRouteMap.fitBounds(L.latLngBounds(routes.flatMap(route => route.coordinates)), { padding: [36, 36] });
        }

        function closeParcelRoute() {
            const modal = document.getElementById('parcelRouteModal');
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
        }

        function initDeliveryMap() {
            deliveryMap = L.map('delivery-map');
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '© OpenStreetMap contributors'
            }).addTo(deliveryMap);
            deliveryMap.fitBounds([[0.85, 99.60], [7.40, 119.30]], { padding: [18, 18] });

            setTimeout(() => {
                if (deliveryMap) {
                    deliveryMap.invalidateSize();
                }
            }, 250);
        }

        async function loadPlannedDeliveryRoute(startLat = null, startLng = null) {
            if (!deliveryMap) return;

            document.getElementById('route-status-text').textContent = 'Building planned delivery route...';
            document.getElementById('plan-stops').innerHTML = '';
            clearPlanLayers();

            try {
                const response = await fetch('../api/get_rider_delivery_plan.php', {
                    credentials: 'same-origin'
                });
                const payload = await response.json();

                if (payload.status !== 'success') {
                    throw new Error(payload.message || 'Unable to load delivery plan.');
                }

                const plan = payload.data;
                if (!plan.stops.length) {
                    document.getElementById('route-status-text').textContent = plan.total_active
                        ? 'Active parcels need saved map coordinates before a planned route can be shown.'
                        : 'No active parcels assigned.';
                    return;
                }

                const bounds = L.latLngBounds([]);
                const start = (startLat != null && startLng != null)
                    ? { lat: startLat, lng: startLng }
                    : (plan.start || null);

                if (start) {
                    const riderMarker = L.circleMarker([start.lat, start.lng], {
                        radius: 8,
                        color: '#ffffff',
                        weight: 2,
                        fillColor: '#22c55e',
                        fillOpacity: 1
                    }).addTo(deliveryMap).bindPopup('Your location');
                    planLayers.push(riderMarker);
                    bounds.extend([start.lat, start.lng]);
                }

                plan.stops.forEach(stop => {
                    const icon = L.divIcon({
                        className: 'route-number-marker',
                        html: `<div style="width:30px;height:30px;border-radius:50%;background:#ef4444;color:#fff;display:grid;place-items:center;border:3px solid #fff;font-weight:700;box-shadow:0 2px 7px rgba(0,0,0,.25)">${stop.sequence}</div>`,
                        iconSize: [30, 30],
                        iconAnchor: [15, 15]
                    });
                    const marker = L.marker([stop.latitude, stop.longitude], { icon })
                        .addTo(deliveryMap)
                        .bindPopup(`<b>${stop.sequence}. ${stop.tracking_number}</b><br>${stop.recipient_name}<br>${stop.delivery_address}`);
                    planLayers.push(marker);
                    bounds.extend([stop.latitude, stop.longitude]);

                    const chip = document.createElement('span');
                    chip.className = 'plan-stop-chip';
                    chip.textContent = `${stop.sequence}. ${stop.tracking_number}`;
                    document.getElementById('plan-stops').appendChild(chip);
                });

                const points = plan.stops.map(stop => ({ lat: stop.latitude, lng: stop.longitude }));
                if (start) {
                    points.unshift(start);
                }

                let totalKm = 0;
                for (let i = 0; i < points.length - 1; i++) {
                    const params = new URLSearchParams({
                        start_lat: points[i].lat,
                        start_lng: points[i].lng,
                        end_lat: points[i + 1].lat,
                        end_lng: points[i + 1].lng
                    });

                    let segmentCoords = [[points[i].lat, points[i].lng], [points[i + 1].lat, points[i + 1].lng]];

                    try {
                        const segmentResponse = await fetch(`../api/get_route.php?${params.toString()}`, {
                            credentials: 'same-origin'
                        });
                        const segment = await segmentResponse.json();
                        if (segment.status === 'success' && segment.data?.coordinates?.length > 1) {
                            segmentCoords = segment.data.coordinates;
                            totalKm += Number(segment.data.distance_km || 0);
                        }
                    } catch (error) {
                        console.warn('Route segment lookup failed:', error);
                    }

                    const line = L.polyline(segmentCoords, {
                        color: '#0284c7',
                        weight: 5,
                        opacity: 0.85
                    }).addTo(deliveryMap);
                    planLayers.push(line);
                }

                if (bounds.isValid()) {
                    deliveryMap.fitBounds(bounds, { padding: [36, 36] });
                }

                document.getElementById('route-status-text').textContent =
                    `${plan.stops.length} stop(s) in suggested order` +
                    (totalKm ? ` · ${totalKm.toFixed(1)} km by road` : '') +
                    (plan.unlocated_count ? ` · ${plan.unlocated_count} parcel(s) missing map coordinates` : '');
            } catch (error) {
                document.getElementById('route-status-text').textContent =
                    error.message || 'Unable to load planned delivery route.';
            }
        }

        function bootDeliveryMap() {
            initDeliveryMap();
            if (currentGps) {
                loadPlannedDeliveryRoute(currentGps.lat, currentGps.lng);
            } else {
                loadPlannedDeliveryRoute();
            }
        }

        document.addEventListener('DOMContentLoaded', bootDeliveryMap);
        <?php endif; ?>

        function sendGPSLocation() {
            if (!riderIsOnline) {
                return;
            }

            if (!("geolocation" in navigator)) {
                <?php if ($show_delivery_plan): ?>
                if (!currentGps) {
                    loadPlannedDeliveryRoute();
                }
                <?php endif; ?>
                return;
            }

            navigator.geolocation.getCurrentPosition(async position => {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                currentGps = { lat, lng };

                const formData = new FormData();
                formData.append('rider_id', riderId);
                formData.append('latitude', lat);
                formData.append('longitude', lng);

                fetch('dashboard.php?action=update_location', {
                    method: 'POST',
                    body: formData
                }).catch(err => console.error("GPS location ping failed:", err));

                <?php if ($show_delivery_plan): ?>
                await loadPlannedDeliveryRoute(lat, lng);
                <?php endif; ?>
            }, err => {
                console.warn("Geolocation warning:", err.message);
                <?php if ($show_delivery_plan): ?>
                if (!currentGps) {
                    loadPlannedDeliveryRoute();
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

            if (event.target === document.getElementById('parcelRouteModal')) {
                closeParcelRoute();
            }
        }
    </script>

</body>
</html>
