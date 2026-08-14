<?php
$page_title = 'Completed Orders';

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('admin');
$db = get_db_connection();

$search = trim($_GET['search'] ?? '');
$params = [];
$sql = "
    SELECT p.tracking_number, p.recipient_name, p.recipient_phone, p.delivery_address,
           p.updated_at, u.name AS rider_name, r.vehicle_number,
           MAX(h.created_at) AS completed_at,
           GROUP_CONCAT(DISTINCT dp.file_path SEPARATOR '||') AS proof_images
    FROM parcels p
    LEFT JOIN riders r ON p.assigned_rider_id = r.id
    LEFT JOIN users u ON r.user_id = u.id
    LEFT JOIN parcel_status_history h ON h.parcel_id = p.id AND h.status = 'delivered'
    LEFT JOIN delivery_photos dp ON dp.parcel_id = p.id
    WHERE p.status = 'delivered' AND p.deleted_at IS NULL
";

if ($search !== '') {
    $sql .= " AND (p.tracking_number LIKE :search OR p.recipient_name LIKE :search OR p.delivery_address LIKE :search OR u.name LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$sql .= " GROUP BY p.id, p.tracking_number, p.recipient_name, p.recipient_phone, p.delivery_address, p.updated_at, u.name, r.vehicle_number
          ORDER BY completed_at DESC, p.updated_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - Courier Dispatch Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/portal-theme.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { min-height: 100vh; display: flex; flex-direction: column; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f0f4f8; color: #1e293b; }
        .navbar { height: 56px; padding: 0 20px; display: flex; align-items: center; justify-content: space-between; background: #1e242b; color: #fff; }
        .navbar-brand, .btn-logout { display: flex; align-items: center; gap: 9px; color: #fff; text-decoration: none; font-weight: 700; }
        .btn-logout { padding: 6px 14px; border-radius: 6px; background: #ef4444; font-size: 13px; }
        .app-container { display: flex; flex: 1; }
        .sidebar { width: 220px; padding: 16px 12px; display: flex; flex-direction: column; gap: 4px; background: #fff; border-right: 1px solid #e2e8f0; }
        .sidebar-item { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-radius: 8px; color: #475569; text-decoration: none; font-size: 14px; font-weight: 500; }
        .sidebar-item.active { background: #dcfce7; color: #15803d; font-weight: 700; }
        .main-content { flex: 1; padding: 24px; min-width: 0; }
        .card { padding: 24px; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
        .card-header { display: flex; gap: 16px; align-items: center; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; }
        .card-title { font-size: 1.35rem; color: #0f172a; }
        .subtext { margin-top: 5px; color: #64748b; font-size: 13px; }
        .search-form { display: flex; gap: 8px; }
        .search-input { min-width: 250px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; }
        .btn-primary, .btn-clear { padding: 8px 13px; border: 0; border-radius: 6px; background: #0284c7; color: #fff; font-weight: 600; text-decoration: none; cursor: pointer; }
        .btn-clear { background: #fee2e2; color: #b91c1c; }
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 13px 14px; text-align: left; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
        th { background: #f8fafc; color: #475569; font-size: 12px; text-transform: uppercase; }
        .badge-delivered { display: inline-flex; gap: 5px; padding: 5px 9px; border-radius: 999px; background: #dcfce7; color: #15803d; font-size: 12px; font-weight: 700; white-space: nowrap; }
        .proof-thumb { width: 42px; height: 42px; object-fit: cover; border-radius: 6px; border: 1px solid #cbd5e1; }
        .proof-images { display: flex; flex-wrap: wrap; gap: 6px; min-width: 90px; }
        .muted { color: #94a3b8; font-size: 12px; }
        @media (max-width: 800px) { .sidebar { width: auto; } .sidebar-item span { display: none; } .main-content { padding: 16px; } .search-input { min-width: 0; width: 100%; } .search-form { width: 100%; } }
    </style>
</head>
<body>
    <header class="navbar">
        <a href="dashboard.php" class="navbar-brand"><i class="fa-solid fa-boxes-packing"></i> Courier Dispatch Portal</a>
        <a href="../logout.php" class="btn-logout"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </header>
    <div class="app-container">
        <aside class="sidebar">
            <a href="dashboard.php" class="sidebar-item"><i class="fa-solid fa-map-location-dot"></i><span>Live Radar</span></a>
            <a href="parcels.php" class="sidebar-item"><i class="fa-solid fa-box"></i><span>Manage Parcels</span></a>
            <a href="completed_orders.php" class="sidebar-item active"><i class="fa-solid fa-circle-check"></i><span>Completed Orders</span></a>
            <a href="riders.php" class="sidebar-item"><i class="fa-solid fa-motorcycle"></i><span>Manage Riders</span></a>
            <a href="reports.php" class="sidebar-item"><i class="fa-solid fa-chart-line"></i><span>Reports</span></a>
            <a href="audit_logs.php" class="sidebar-item"><i class="fa-solid fa-clock-rotate-left"></i><span>Audit Logs</span></a>
        </aside>
        <main class="main-content">
            <section class="card">
                <div class="card-header">
                    <div>
                        <h1 class="card-title"><i class="fa-solid fa-circle-check"></i> Completed Orders</h1>
                        <p class="subtext">All parcels successfully delivered by riders.</p>
                    </div>
                    <form class="search-form" method="GET">
                        <input class="search-input" type="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search tracking, recipient, rider...">
                        <button class="btn-primary" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                        <?php if ($search !== ''): ?><a class="btn-clear" href="completed_orders.php">Clear</a><?php endif; ?>
                    </form>
                </div>
                <div class="table-container">
                    <table>
                        <thead><tr><th>Tracking #</th><th>Recipient</th><th>Address</th><th>Rider</th><th>Status</th><th>Completed At</th><th>Proof</th></tr></thead>
                        <tbody>
                            <?php if (!$orders): ?>
                                <tr><td colspan="7" style="padding:24px;text-align:center;color:#94a3b8;">No completed orders found.</td></tr>
                            <?php else: foreach ($orders as $order): ?>
                                <?php $proof_images = array_filter(explode('||', (string)($order['proof_images'] ?? ''))); ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($order['tracking_number']) ?></strong></td>
                                    <td><?= htmlspecialchars($order['recipient_name']) ?><span class="muted" style="display:block;"><?= htmlspecialchars($order['recipient_phone']) ?></span></td>
                                    <td><?= htmlspecialchars($order['delivery_address']) ?></td>
                                    <td><?= htmlspecialchars($order['rider_name'] ?: 'Unassigned') ?><span class="muted" style="display:block;"><?= htmlspecialchars($order['vehicle_number'] ?: '') ?></span></td>
                                    <td><span class="badge-delivered"><i class="fa-solid fa-check"></i> Delivered</span></td>
                                    <td><?= htmlspecialchars(date('d M Y, g:i a', strtotime($order['completed_at'] ?: $order['updated_at']))) ?></td>
                                    <td>
                                        <?php if ($proof_images): ?><div class="proof-images">
                                            <?php foreach ($proof_images as $proof_image): ?>
                                                <?php $proof = ltrim(preg_replace('#^(\.\./|\./)+#', '', $proof_image), '/\\'); ?>
                                                <a href="../<?= htmlspecialchars($proof) ?>" target="_blank"><img class="proof-thumb" src="../<?= htmlspecialchars($proof) ?>" alt="Delivery proof"></a>
                                            <?php endforeach; ?>
                                        </div><?php else: ?><span class="muted">No upload</span><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
