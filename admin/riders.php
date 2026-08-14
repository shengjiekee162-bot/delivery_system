<?php
$page_title = "Manage Riders";

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

$message = '';
$error = '';

// Helper function to log audit events
function log_audit_action($db, $user_id, $action, $details) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $stmt = $db->prepare("
            INSERT INTO audit_logs (id, user_id, action, details, ip_address, created_at) 
            VALUES (UUID(), :user_id, :action, :details, :ip, NOW())
        ");
        $stmt->execute([
            ':user_id' => $user_id,
            ':action'  => $action,
            ':details' => $details,
            ':ip'      => $ip
        ]);
    } catch (Exception $e) {
        error_log("Audit Log Failed: " . $e->getMessage());
    }
}

$current_admin_id = $_SESSION['user_id'] ?? null;

// -------------------------------------------------------------
// POST HANDLER: Create New Rider
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_rider') {
    $name           = trim($_POST['name'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $phone          = trim($_POST['phone'] ?? '');
    $vehicle_number = trim($_POST['vehicle_number'] ?? '');
    $password       = password_hash($_POST['password'] ?? 'rider123', PASSWORD_BCRYPT);

    if (!empty($name) && !empty($email) && !empty($vehicle_number)) {
        try {
            $db->beginTransaction();

            // 1. Create User Record
            $user_stmt = $db->prepare("
                INSERT INTO users (id, name, email, password_hash, role, created_at) 
                VALUES (UUID(), :name, :email, :password, 'rider', NOW())
            ");
            $user_stmt->execute([
                ':name'     => $name,
                ':email'    => $email,
                ':password' => $password
            ]);

            // Retrieve generated user UUID
            $get_user_stmt = $db->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
            $get_user_stmt->execute([':email' => $email]);
            $new_user = $get_user_stmt->fetch();
            $new_user_id = $new_user['id'];

            // 2. Create Rider Record
            $rider_stmt = $db->prepare("
                INSERT INTO riders (id, user_id, phone, vehicle_number, is_online, created_at) 
                VALUES (UUID(), :user_id, :phone, :vehicle, 0, NOW())
            ");
            $rider_stmt->execute([
                ':user_id' => $new_user_id,
                ':phone'   => $phone,
                ':vehicle' => $vehicle_number
            ]);

            // 3. Audit Log
            log_audit_action($db, $current_admin_id, 'CREATE_RIDER', "Created rider '{$name}' ({$vehicle_number})");

            $db->commit();
            $message = "Rider '{$name}' successfully created.";
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Failed to create rider: " . $e->getMessage();
        }
    } else {
        $error = "Please fill in all required fields (*).";
    }
}

// -------------------------------------------------------------
// POST HANDLER: Hard Delete Rider (Permanent Delete)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_rider') {
    $rider_id = $_POST['rider_id'] ?? '';
    $user_id  = $_POST['user_id'] ?? '';

    if (!empty($rider_id) && !empty($user_id)) {
        try {
            $db->beginTransaction();

            // 1. Unassign parcels from this rider to prevent foreign key errors
            $stmtParcels = $db->prepare("UPDATE parcels SET assigned_rider_id = NULL WHERE assigned_rider_id = :rider_id");
            $stmtParcels->execute([':rider_id' => $rider_id]);

            // 2. Delete location tracking history associated with this rider
            try {
                $stmtLoc = $db->prepare("DELETE FROM rider_locations WHERE rider_id = :rider_id");
                $stmtLoc->execute([':rider_id' => $rider_id]);
            } catch (Exception $ex) {
                // Ignore if rider_locations table does not exist
            }

            // 3. Delete permanent row from riders table
            $stmtRider = $db->prepare("DELETE FROM riders WHERE id = :rider_id");
            $stmtRider->execute([':rider_id' => $rider_id]);

            // 4. Delete permanent row from users table
            $stmtUser = $db->prepare("DELETE FROM users WHERE id = :user_id");
            $stmtUser->execute([':user_id' => $user_id]);

            // 5. Log audit action
            log_audit_action($db, $current_admin_id, 'HARD_DELETE_RIDER', "Permanently deleted rider ID: {$rider_id}");

            $db->commit();
            $message = "Rider permanently deleted from database.";
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Error permanently deleting rider: " . $e->getMessage();
        }
    } else {
        $error = "Missing rider or user identifier.";
    }
}

// -------------------------------------------------------------
// Fetch Data
// -------------------------------------------------------------
$search = trim($_GET['search'] ?? '');

$query = "
    SELECT r.id AS rider_id, r.user_id, r.phone, r.vehicle_number, r.is_online, r.created_at,
           u.name, u.email,
           COUNT(p.id) AS assigned_parcels
    FROM riders r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN parcels p ON p.assigned_rider_id = r.id AND p.deleted_at IS NULL
    WHERE u.deleted_at IS NULL AND r.deleted_at IS NULL
";

$params = [];
if (!empty($search)) {
    $query .= " AND (u.name LIKE :search OR u.email LIKE :search OR r.phone LIKE :search OR r.vehicle_number LIKE :search)";
    $params[':search'] = "%{$search}%";
}

$query .= " GROUP BY r.id ORDER BY r.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$riders = $stmt->fetchAll();

$online_count = count(array_filter($riders, fn($r) => $r['is_online'] == 1));
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
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px; }
        .card-title { font-size: 1.35rem; font-weight: 700; color: #0f172a; }
        .card-badge { background-color: #e0f2fe; color: #0284c7; padding: 6px 12px; border-radius: 6px; font-size: 13px; font-weight: 600; }
        .form-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 16px; }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group label { font-size: 13px; font-weight: 600; color: #334155; }
        .form-group input[type="text"], .form-group input[type="email"], .form-group input[type="password"] { width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; color: #1e293b; outline: none; }
        .form-group input:focus { border-color: #0284c7; box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15); }
        .btn-primary { background-color: #0284c7; color: #ffffff; border: none; padding: 10px 20px; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary:hover { background-color: #0369a1; }
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; }
        .alert-success { background-color: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .alert-error { background-color: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 14px; }
        th { background-color: #f8fafc; color: #475569; font-weight: 700; padding: 12px 16px; border-bottom: 1px solid #e2e8f0; }
        td { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; color: #334155; vertical-align: middle; }
        tr:hover { background-color: #f8fafc; }
        .subtext { display: block; font-size: 12px; color: #94a3b8; margin-top: 2px; }
        .badge-online { background-color: #dcfce7; color: #15803d; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; }
        .badge-offline { background-color: #f1f5f9; color: #64748b; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; }
        .btn-action-delete { background: #fee2e2; border: 1px solid #fecaca; color: #b91c1c; padding: 5px 10px; border-radius: 4px; font-size: 12px; cursor: pointer; font-weight: 600; }
        .btn-action-delete:hover { background: #fca5a5; }
        .search-box { display: flex; align-items: center; gap: 8px; }
        .search-input { padding: 7px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; outline: none; width: 250px; }
        .search-input:focus { border-color: #0284c7; box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.15); }
        @media (max-width: 900px) { .form-grid { grid-template-columns: 1fr; } }
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
            <a href="riders.php" class="sidebar-item active">
                <i class="fa-solid fa-motorcycle"></i>
                <span>Manage Riders</span>
            </a>
            <a href="audit_logs.php" class="sidebar-item">
                <i class="fa-solid fa-clock-rotate-left"></i>
                <span>Audit Logs</span>
            </a>
        </aside>

        <main class="main-content">

            <?php if ($message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h1 class="card-title">Manage Delivery Fleet Riders</h1>
                    <span class="card-badge"><i class="fa-solid fa-motorcycle"></i> Online Fleet: <?= $online_count ?> / <?= count($riders) ?> Riders</span>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="create_rider">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Full Name *</label>
                            <input type="text" name="name" required placeholder="e.g. Ahmad Razak">
                        </div>

                        <div class="form-group">
                            <label>Email Address *</label>
                            <input type="email" name="email" required placeholder="e.g. ahmad@courier.com">
                        </div>

                        <div class="form-group">
                            <label>Phone Number</label>
                            <input type="text" name="phone" placeholder="e.g. +60123456789">
                        </div>

                        <div class="form-group">
                            <label>Vehicle Plate Number *</label>
                            <input type="text" name="vehicle_number" required placeholder="e.g. MOTO-9921">
                        </div>

                        <div class="form-group">
                            <label>Login Password</label>
                            <input type="password" name="password" placeholder="Default: rider123">
                        </div>
                    </div>

                    <button type="submit" class="btn-primary">
                        <i class="fa-solid fa-plus"></i> Register Fleet Rider
                    </button>
                </form>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Registered Fleet Drivers</h2>
                    <div class="search-box">
                        <input type="text" id="liveSearchInput" class="search-input" placeholder="Type to search rider instantly...">
                    </div>
                </div>
                <div class="table-container">
                    <table id="ridersTable">
                        <thead>
                            <tr>
                                <th>Rider Name</th>
                                <th>Contact Info</th>
                                <th>Vehicle Plate</th>
                                <th>Active Parcels</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($riders)): ?>
                                <tr id="noRidersRow">
                                    <td colspan="6" style="text-align: center; color: #94a3b8; padding: 20px;">No registered riders found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($riders as $r): ?>
                                <tr class="rider-row">
                                    <td><strong><?= htmlspecialchars($r['name']) ?></strong></td>
                                    <td>
                                        <?= htmlspecialchars($r['email']) ?>
                                        <span class="subtext"><?= htmlspecialchars($r['phone'] ?: 'N/A') ?></span>
                                    </td>
                                    <td><strong><?= htmlspecialchars($r['vehicle_number']) ?></strong></td>
                                    <td><span style="font-weight:700; color:#0284c7;"><?= (int)$r['assigned_parcels'] ?> Parcels</span></td>
                                    <td>
                                        <?php if ($r['is_online'] == 1): ?>
                                            <span class="badge-online"><i class="fa-solid fa-circle" style="font-size:8px;"></i> ONLINE</span>
                                        <?php else: ?>
                                            <span class="badge-offline">OFFLINE</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Permanently remove rider <?= htmlspecialchars($r['name']) ?> from the database?');">
                                            <input type="hidden" name="action" value="delete_rider">
                                            <input type="hidden" name="rider_id" value="<?= $r['rider_id'] ?>">
                                            <input type="hidden" name="user_id" value="<?= $r['user_id'] ?>">
                                            <button type="submit" class="btn-action-delete">Delete</button>
                                        </form>
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

    <!-- Instant Live Search Script -->
    <script>
        document.getElementById('liveSearchInput').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            const rows = document.querySelectorAll('#ridersTable tbody tr.rider-row');
            let visibleCount = 0;

            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                if (text.includes(searchTerm)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            // Handle no match found dynamically
            let noMatchRow = document.getElementById('noMatchRow');
            if (visibleCount === 0 && rows.length > 0) {
                if (!noMatchRow) {
                    const tbody = document.querySelector('#ridersTable tbody');
                    noMatchRow = document.createElement('tr');
                    noMatchRow.id = 'noMatchRow';
                    noMatchRow.innerHTML = `<td colspan="6" style="text-align: center; color: #94a3b8; padding: 20px;">No matching riders found for "${this.value}"</td>`;
                    tbody.appendChild(noMatchRow);
                } else {
                    noMatchRow.style.display = '';
                    noMatchRow.querySelector('td').innerText = `No matching riders found for "${this.value}"`;
                }
            } else if (noMatchRow) {
                noMatchRow.style.display = 'none';
            }
        });
    </script>
</body>
</html>
