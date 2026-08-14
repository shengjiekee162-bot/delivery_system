<?php
require_once '../includes/auth.php';
require_role('admin');

$parcel_id = sanitize($_GET['id'] ?? '');
if (empty($parcel_id)) {
    header('Location: parcels.php');
    exit();
}

$db = get_db_connection();

// 1. Fetch main parcel information with rider details
$stmt = $db->prepare("
    SELECT p.*, u.name as rider_name, r.phone as rider_phone, r.vehicle_number 
    FROM parcels p 
    LEFT JOIN riders r ON p.assigned_rider_id = r.id 
    LEFT JOIN users u ON r.user_id = u.id 
    WHERE p.id = ? AND p.deleted_at IS NULL 
    LIMIT 1
");
$stmt->execute([$parcel_id]);
$parcel = $stmt->fetch();

if (!$parcel) {
    die("Error: Parcel record not found.");
}

// 2. Fetch status transition timeline history
$histStmt = $db->prepare("
    SELECT h.*, u.name as changed_by_name, u.role as user_role 
    FROM parcel_status_history h 
    JOIN users u ON h.changed_by_user_id = u.id 
    WHERE h.parcel_id = ? 
    ORDER BY h.created_at DESC
");
$histStmt->execute([$parcel_id]);
$history = $histStmt->fetchAll();

// 3. Fetch delivery photo proofs
$photoStmt = $db->prepare("SELECT * FROM delivery_photos WHERE parcel_id = ? ORDER BY uploaded_at DESC");
$photoStmt->execute([$parcel_id]);
$photos = $photoStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parcel Detail - <?= sanitize($parcel['tracking_number']) ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .details-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .timeline { border-left: 2px solid var(--border-color); margin-left: 10px; padding-left: 20px; list-style: none; }
        .timeline-item { position: relative; margin-bottom: 20px; }
        .timeline-item::before { content: ''; position: absolute; left: -26px; top: 4px; width: 10px; height: 10px; border-radius: 50%; background: var(--primary); }
        .photo-gallery { display: flex; gap: 15px; flex-wrap: wrap; margin-top: 10px; }
        .photo-gallery img { width: 180px; height: 180px; object-fit: cover; border-radius: 6px; border: 1px solid var(--border-color); }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="brand">Courier Admin</div>
            <nav>
                <a href="dashboard.php">Dashboard</a>
                <a href="parcels.php" class="active">Parcel Management</a>
                <a href="../logout.php">Logout</a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <header class="topbar">
                <div>
                    <a href="parcels.php" style="text-decoration: none; color: var(--primary);">&larr; Back to Parcels</a>
                    <h2 style="margin-top: 5px;">Tracking #: <?= sanitize($parcel['tracking_number']) ?></h2>
                </div>
                <div>
                    <?php 
                        $badgeClass = 'badge-secondary';
                        if ($parcel['status'] === 'delivered') $badgeClass = 'badge-success';
                        if ($parcel['status'] === 'out_for_delivery') $badgeClass = 'badge-info';
                        if ($parcel['status'] === 'failed_delivery') $badgeClass = 'badge-danger';
                    ?>
                    <span class="badge <?= $badgeClass ?>" style="font-size: 1rem; padding: 6px 12px;">
                        <?= str_replace('_', ' ', strtoupper($parcel['status'])) ?>
                    </span>
                </div>
            </header>

            <div class="details-grid">
                <!-- Recipient Info Card -->
                <div class="card">
                    <h3>Recipient Details</h3>
                    <hr style="margin: 10px 0; border: 0; border-top: 1px solid var(--border-color);">
                    <p><strong>Name:</strong> <?= sanitize($parcel['recipient_name']) ?></p>
                    <p><strong>Phone:</strong> <?= sanitize($parcel['recipient_phone']) ?></p>
                    <p><strong>Delivery Address:</strong></p>
                    <p style="background: #f8fafc; padding: 10px; border-radius: 4px; border: 1px solid var(--border-color); margin-top: 5px;">
                        <?= nl2br(sanitize($parcel['delivery_address'])) ?>
                    </p>
                    <p style="margin-top: 10px;"><small><strong>Created At:</strong> <?= date('F j, Y, g:i a', strtotime($parcel['created_at'])) ?></small></p>
                </div>

                <!-- Assigned Rider Details Card -->
                <div class="card">
                    <h3>Assigned Dispatch Rider</h3>
                    <hr style="margin: 10px 0; border: 0; border-top: 1px solid var(--border-color);">
                    <?php if ($parcel['rider_name']): ?>
                        <p><strong>Rider Name:</strong> <?= sanitize($parcel['rider_name']) ?></p>
                        <p><strong>Phone:</strong> <?= sanitize($parcel['rider_phone']) ?></p>
                        <p><strong>Vehicle Number:</strong> <?= sanitize($parcel['vehicle_number']) ?></p>
                    <?php else: ?>
                        <p style="color: #64748b;">No rider currently assigned to this parcel.</p>
                        <a href="parcels.php" class="btn btn-sm btn-primary" style="margin-top: 10px;">Assign Rider Now</a>
                    <?php endif; ?>

                    <?php if (!empty($parcel['remarks'])): ?>
                        <div style="margin-top: 15px;">
                            <strong>Latest Remarks / Notes:</strong>
                            <p style="background: #fffbe0; padding: 8px; border-radius: 4px; font-size: 0.9rem; margin-top: 5px;">
                                <?= sanitize($parcel['remarks']) ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Proof of Delivery Photos -->
            <div class="card">
                <h3>Uploaded Proof of Delivery Photos</h3>
                <?php if (empty($photos)): ?>
                    <p style="color: #64748b; margin-top: 10px;">No proof photos captured for this parcel yet.</p>
                <?php else: ?>
                    <div class="photo-gallery">
                        <?php foreach ($photos as $photo): ?>
                            <div>
                                <a href="../<?= sanitize($photo['file_path']) ?>" target="_blank">
                                    <img src="../<?= sanitize($photo['file_path']) ?>" alt="Proof of Delivery">
                                </a>
                                <br>
                                <small style="color: #64748b;"><?= date('M d, H:i', strtotime($photo['uploaded_at'])) ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Audit Trail / Status History -->
            <div class="card">
                <h3>Parcel Status Audit Trail</h3>
                <div style="margin-top: 15px;">
                    <ul class="timeline">
                        <?php foreach ($history as $event): ?>
                            <li class="timeline-item">
                                <strong>Status changed to: <?= str_replace('_', ' ', strtoupper(sanitize($event['status']))) ?></strong>
                                <div style="font-size: 0.85rem; color: #64748b;">
                                    By <?= sanitize($event['changed_by_name']) ?> (<?= ucfirst(sanitize($event['user_role'])) ?>) on <?= date('M d, Y - H:i:s', strtotime($event['created_at'])) ?>
                                </div>
                                <?php if (!empty($event['remarks'])): ?>
                                    <div style="font-size: 0.9rem; margin-top: 4px;">Remarks: "<?= sanitize($event['remarks']) ?>"</div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </main>
    </div>
</body>
</html>