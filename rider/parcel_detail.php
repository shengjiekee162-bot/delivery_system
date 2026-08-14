<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rider_status.php';
require_role('rider');

$db = get_db_connection();
$user_id = (string)($_SESSION['user_id'] ?? '');
$rider = ensure_rider_profile($db, $user_id);
mark_rider_online($db, $user_id);

$parcel_id = sanitize($_GET['id'] ?? '');

$stmt = $db->prepare("SELECT * FROM parcels WHERE id = ? AND assigned_rider_id = ? LIMIT 1");
$stmt->execute([$parcel_id, $rider['rider_id']]);
$parcel = $stmt->fetch();

if (!$parcel) {
    die("Parcel record not found or unassigned.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Processing - <?= sanitize($parcel['tracking_number']) ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="rider-body">
    <div class="card">
        <a href="dashboard.php">&larr; Back to Dashboard</a>
        <h3>Update Order: <?= sanitize($parcel['tracking_number']) ?></h3>
        <p><strong>Address:</strong> <?= sanitize($parcel['delivery_address']) ?></p>
        
        <form id="status-update-form">
            <input type="hidden" name="parcel_id" value="<?= $parcel['id'] ?>">

            <div class="form-group">
                <label>Delivery Status</label>
                <select name="status" class="form-control" required>
                    <option value="out_for_delivery" <?= $parcel['status'] === 'out_for_delivery' ? 'selected' : '' ?>>Out for Delivery</option>
                    <option value="delivered">Delivered</option>
                    <option value="failed_delivery">Failed Delivery</option>
                </select>
            </div>

            <div class="form-group">
                <label>Proof of Delivery Camera</label>
                <video id="camera-feed" autoplay playsinline style="width:100%; max-height: 250px; background:#000; border-radius:4px;"></video>
                <canvas id="photo-canvas" style="display:none;"></canvas>
                <button type="button" class="btn btn-secondary btn-block" onclick="capturePhoto()">Capture Photo Proof</button>
            </div>

            <div class="form-group">
                <label>Remarks / Notes</label>
                <textarea name="remarks" class="form-control" rows="3"><?= sanitize($parcel['remarks'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Save Status Update</button>
        </form>
    </div>

    <script src="../assets/js/camera.js"></script>
    <script>
        let capturedDataUrl = null;

        document.addEventListener('DOMContentLoaded', () => {
            initCamera('camera-feed', 'photo-canvas');
        });

        function capturePhoto() {
            capturedDataUrl = captureAndCompressPhoto('camera-feed', 'photo-canvas');
            alert('Photo proof captured and compressed successfully!');
        }

        document.getElementById('status-update-form').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            if (capturedDataUrl) {
                formData.append('photo', capturedDataUrl);
            }

            fetch('../api/update_parcel_status.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    stopCamera();
                    alert('Delivery state updated!');
                    window.location.href = 'dashboard.php';
                } else {
                    alert('Error: ' + data.message);
                }
            });
        });
    </script>
    <?php require __DIR__ . '/../includes/rider_presence_script.php'; ?>
</body>
</html>