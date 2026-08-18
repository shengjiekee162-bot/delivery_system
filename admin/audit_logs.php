<?php
$page_title = "Audit Logs";

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Role & Database Connection Checks
require_role('admin');
$db = get_db_connection();

$search_query = trim($_GET['search'] ?? '');
$logs = [];
$total_logs = 0;
$db_error = '';

// Fetch Logs Safely
try {
    $sql = "
        SELECT a.id, a.action, a.details, a.ip_address, a.created_at,
               COALESCE(u.name, 'System Admin') AS performed_by,
               COALESCE(u.email, 'admin@courier.com') AS user_email
        FROM audit_logs a
        LEFT JOIN users u ON a.user_id = u.id
    ";

    $params = [];
    if (!empty($search_query)) {
        $sql .= " WHERE a.action LIKE :search OR a.details LIKE :search OR u.name LIKE :search ";
        $params[':search'] = '%' . $search_query . '%';
    }

    $sql .= " ORDER BY a.created_at DESC LIMIT 100";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();

    $total_logs = $db->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
} catch (PDOException $e) {
    $db_error = "Database Error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - Courier Dispatch Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #f0f4f8; color: #1e293b; display: flex; flex-direction: column; min-height: 100vh; }
        .navbar { background-color: #1e242b; color: #ffffff; height: 56px; display: flex; align-items: center; justify-content: space-between; padding: 0 20px; position: sticky; top: 0; z-index: 1000; }
        .navbar-brand { font-size: 1.15rem; font-weight: 700; display: flex; align-items: center; gap: 10px; color: #ffffff; text-decoration: none; }
        .navbar-user { display: flex; align-items: center; gap: 16px; font-size: 14px; }
        .navbar-user .user-name { color: #ffffff; font-weight: 500; display: flex; align-items: center; gap: 8px; }
        .btn-logout { background-color: #ef4444; color: #ffffff; padding: 6px 14px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 6px; }
        .btn-logout:hover { background-color: #dc2626; }
        .app-container { display: flex; flex: 1; }
        .sidebar { width: 220px; background-color: #ffffff; padding: 16px 12px; display: flex; flex-direction: column; gap: 4px; border-right: 1px solid #e2e8f0; min-height: calc(100vh - 56px); }
        .sidebar-item { display: flex; align-items: center; gap: 12px; padding: 10px 14px; color: #475569; text-decoration: none; font-weight: 500; font-size: 14px; border-radius: 8px; transition: all 0.15s ease; }
        .sidebar-item:hover { background-color: #f8fafc; color: #0f172a; }
        .sidebar-item.active { background-color: #e0f2fe; color: #0284c7; font-weight: 600; }
        .sidebar-item.active i { color: #0284c7; }
        .main-content { flex: 1; padding: 24px; background-color: #f0f4f8; }
        .card { background: #ffffff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04); padding: 24px; border: 1px solid #e2e8f0; margin-bottom: 24px; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .card-title { font-size: 1.35rem; font-weight: 700; color: #0f172a; }
        .card-badge { background-color: #e2e8f0; color: #475569; padding: 6px 12px; border-radius: 6px; font-size: 13px; font-weight: 600; }
        .alert-error { background-color: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; padding: 14px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; font-weight: 500; }
        .search-container { display: flex; gap: 10px; margin-bottom: 16px; }
        .search-container input { flex: 1; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; outline: none; }
        .search-container input:focus { border-color: #0284c7; box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15); }
        .btn-search { background-color: #0284c7; color: #ffffff; border: none; padding: 9px 16px; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; }
        .btn-search:hover { background-color: #0369a1; }
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 14px; }
        th { background-color: #f8fafc; color: #475569; font-weight: 700; padding: 12px 16px; border-bottom: 1px solid #e2e8f0; }
        td { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; color: #334155; vertical-align: middle; }
        tr:hover { background-color: #f8fafc; }
        .subtext { display: block; font-size: 12px; color: #94a3b8; margin-top: 2px; }
        .action-tag { display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; font-family: monospace; background-color: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; }
    </style>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap">
    <link rel="stylesheet" href="../assets/css/portal-theme.css">
</head>
<body>

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

    <div class="app-container">

        <aside class="sidebar">
            <a href="dashboard.php" class="sidebar-item">
                <i class="fa-solid fa-map-location-dot"></i>
                <span>Live Radar</span>
            </a>
            <a href="parcels.php" class="sidebar-item">
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
            <a href="audit_logs.php" class="sidebar-item active">
                <i class="fa-solid fa-clock-rotate-left"></i>
                <span>Audit Logs</span>
            </a>
        </aside>

        <main class="main-content">

            <?php if ($db_error): ?>
                <div class="alert-error">
                    <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($db_error) ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h1 class="card-title">System Audit & Activity Logs</h1>
                    <span class="card-badge">Total Events: <?= (int)$total_logs ?></span>
                </div>

                <form method="GET" class="search-container">
                    <input type="text" name="search" value="<?= htmlspecialchars($search_query) ?>" placeholder="Filter by action (e.g. CREATE_RIDER), details, or user name...">
                    <button type="submit" class="btn-search"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                    <?php if (!empty($search_query)): ?>
                        <a href="audit_logs.php" style="padding:9px 12px; background:#e2e8f0; color:#334155; text-decoration:none; border-radius:6px; font-size:14px; font-weight:600;">Clear</a>
                    <?php endif; ?>
                </form>

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>User</th>
                                <th>Action Event</th>
                                <th>Details</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; color: #94a3b8; padding: 24px;">No audit log records found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td>
                                        <strong><?= date('Y-m-d H:i:s', strtotime($log['created_at'])) ?></strong>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($log['performed_by']) ?>
                                        <span class="subtext"><?= htmlspecialchars($log['user_email']) ?></span>
                                    </td>
                                    <td>
                                        <span class="action-tag"><?= htmlspecialchars($log['action']) ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($log['details']) ?></td>
                                    <td><span class="subtext"><?= htmlspecialchars($log['ip_address'] ?? '127.0.0.1') ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

</body>
</html>
