<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rider_status.php';

require_role('rider');

$db = get_db_connection();
$user_id = (string)($_SESSION['user_id'] ?? '');
$rider = ensure_rider_profile($db, $user_id);
$completed_orders = [];
$error_message = '';

try {
    $stmt = $db->prepare("
        SELECT p.tracking_number, p.recipient_name, p.recipient_phone, p.delivery_address,
               p.updated_at, MAX(h.created_at) AS completed_at,
               GROUP_CONCAT(DISTINCT dp.file_path SEPARATOR '||') AS proof_images
        FROM parcels p
        LEFT JOIN parcel_status_history h
            ON h.parcel_id = p.id AND h.status = 'delivered'
        LEFT JOIN delivery_photos dp ON dp.parcel_id = p.id
        WHERE p.assigned_rider_id = :rider_id
          AND p.status = 'delivered'
          AND p.deleted_at IS NULL
        GROUP BY p.id, p.tracking_number, p.recipient_name, p.recipient_phone, p.delivery_address, p.updated_at
        ORDER BY completed_at DESC, p.updated_at DESC
    ");
    $stmt->execute([':rider_id' => $rider['rider_id']]);
    $completed_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error_message = 'Unable to load completed orders: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Completed Orders - Rider Delivery Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/portal-theme.css">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f1f5f9; color: #0f172a; }
        .navbar { min-height: 60px; padding: 0 20px; display: flex; align-items: center; justify-content: space-between; gap: 16px; background: #0f172a; color: #fff; }
        .brand, .nav-link { color: #fff; text-decoration: none; display: inline-flex; align-items: center; gap: 9px; font-weight: 700; }
        .nav-link { font-size: 14px; font-weight: 600; }
        .container { width: min(900px, calc(100% - 32px)); margin: 24px auto; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,.05); }
        .card-header { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 6px; }
        h1 { font-size: 1.3rem; margin: 0; }
        .subtext { margin: 0 0 18px; color: #64748b; font-size: 14px; }
        .alert { padding: 12px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .order-list { display: grid; gap: 12px; }
        .order { padding: 16px; border: 1px solid #e2e8f0; border-radius: 9px; background: #f8fafc; }
        .order-top { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        .tracking { color: #0284c7; font-weight: 700; }
        .completed { display: inline-flex; align-items: center; gap: 6px; padding: 5px 9px; border-radius: 999px; background: #dcfce7; color: #15803d; font-size: 12px; font-weight: 700; white-space: nowrap; }
        .order p { margin: 7px 0 0; color: #475569; font-size: 14px; }
        .completed-at { color: #64748b !important; font-size: 12px !important; }
        .proof-images { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
        .proof-thumb { width: 70px; height: 70px; object-fit: cover; border: 1px solid #cbd5e1; border-radius: 7px; }
        .empty { padding: 32px 0; text-align: center; color: #64748b; font-size: 14px; }
        @media (max-width: 560px) { .navbar { padding: 12px 16px; } .order-top { align-items: flex-start; flex-direction: column; } }
    </style>
</head>
<body>
    <nav class="navbar">
        <a class="brand" href="dashboard.php"><i class="fa-solid fa-motorcycle"></i> Rider Delivery Portal</a>
        <a class="nav-link" href="dashboard.php"><i class="fa-solid fa-arrow-left"></i> Current Orders</a>
    </nav>

    <main class="container">
        <?php if (isset($_GET['completed'])): ?>
            <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> Order marked as delivered and moved to Completed Orders.</div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= sanitize($error_message) ?></div>
        <?php endif; ?>

        <section class="card">
            <div class="card-header">
                <h1><i class="fa-solid fa-circle-check"></i> Completed Orders (<?= count($completed_orders) ?>)</h1>
            </div>
            <p class="subtext">Orders you have successfully delivered.</p>

            <?php if (!$completed_orders): ?>
                <p class="empty">No completed orders yet.</p>
            <?php else: ?>
                <div class="order-list">
                    <?php foreach ($completed_orders as $order): ?>
                        <article class="order">
                            <div class="order-top">
                                <span class="tracking"><i class="fa-solid fa-barcode"></i> <?= sanitize($order['tracking_number']) ?></span>
                                <span class="completed"><i class="fa-solid fa-check"></i> Delivered</span>
                            </div>
                            <p><strong>Recipient:</strong> <?= sanitize($order['recipient_name']) ?> (<?= sanitize($order['recipient_phone']) ?>)</p>
                            <p><strong>Address:</strong> <?= sanitize($order['delivery_address']) ?></p>
                            <p class="completed-at"><i class="fa-regular fa-clock"></i> Completed: <?= sanitize(date('d M Y, g:i a', strtotime($order['completed_at'] ?: $order['updated_at']))) ?></p>
                            <?php $proof_images = array_filter(explode('||', (string)($order['proof_images'] ?? ''))); ?>
                            <?php if ($proof_images): ?>
                                <div class="proof-images" aria-label="Delivery proof photos">
                                    <?php foreach ($proof_images as $proof_image): ?>
                                        <a href="../<?= sanitize($proof_image) ?>" target="_blank"><img class="proof-thumb" src="../<?= sanitize($proof_image) ?>" alt="Delivery proof"></a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
