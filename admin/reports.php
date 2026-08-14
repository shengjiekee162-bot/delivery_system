<?php
$page_title = "Delivery Reports";

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

// Fetch Active Riders for Filter Dropdown
$riders = $db->query("
    SELECT r.id AS rider_id, u.name, r.vehicle_number 
    FROM riders r 
    JOIN users u ON r.user_id = u.id 
    WHERE u.deleted_at IS NULL
")->fetchAll();

// Handle Filter Inputs
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date'] ?? date('Y-m-d');
$status_filter = $_GET['status'] ?? '';
$rider_filter  = $_GET['rider_id'] ?? '';

// Build Query with Filters
$sql = "
    SELECT p.*, u.name AS rider_name 
    FROM parcels p 
    LEFT JOIN riders r ON p.assigned_rider_id = r.id 
    LEFT JOIN users u ON r.user_id = u.id 
    WHERE p.deleted_at IS NULL 
    AND DATE(p.created_at) BETWEEN :start_date AND :end_date
";

$params = [
    ':start_date' => $start_date,
    ':end_date'   => $end_date
];

if (!empty($status_filter)) {
    $sql .= " AND p.status = :status";
    $params[':status'] = $status_filter;
}

if (!empty($rider_filter)) {
    $sql .= " AND p.assigned_rider_id = :rider_id";
    $params[':rider_id'] = $rider_filter;
}

$sql .= " ORDER BY p.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$reports = $stmt->fetchAll();

// Calculate Summary Statistics from Filtered Data
$total_parcels = count($reports);
$delivered_count = 0;
$out_for_delivery_count = 0;

foreach ($reports as $r) {
    if ($r['status'] === 'delivered') {
        $delivered_count++;
    } elseif ($r['status'] === 'out_for_delivery') {
        $out_for_delivery_count++;
    }
}
$success_rate = $total_parcels > 0 ? round(($delivered_count / $total_parcels) * 100, 1) : 0;

// Handle CSV Export if requested
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=delivery_report_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['Tracking Number', 'Recipient Name', 'Phone', 'Address', 'Status', 'Rider', 'Created At']);
    
    foreach ($reports as $r) {
        fputcsv($output, [
            $r['tracking_number'],
            $r['recipient_name'],
            $r['recipient_phone'],
            $r['delivery_address'],
            strtoupper($r['status']),
            $r['rider_name'] ?? 'Unassigned',
            $r['created_at']
        ]);
    }
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - Courier Dispatch Portal</title>
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
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

        /* STATS GRID */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 20px;
        }

        .stat-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px;
        }

        .stat-box h3 {
            font-size: 12px;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 6px;
        }

        .stat-box .value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #0f172a;
        }

        /* FILTER FORM */
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr) auto;
            gap: 12px;
            align-items: flex-end;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-group label {
            font-size: 13px;
            font-weight: 600;
            color: #334155;
        }

        .form-group input, 
        .form-group select {
            padding: 9px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 14px;
            color: #1e293b;
            outline: none;
            transition: border-color 0.15s ease;
        }

        .form-group input:focus, 
        .form-group select:focus {
            border-color: #0284c7;
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15);
        }

        .btn-primary {
            background-color: #0284c7;
            color: #ffffff;
            border: none;
            padding: 10px 16px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: background 0.15s ease;
        }

        .btn-primary:hover {
            background-color: #0369a1;
        }

        .btn-success {
            background-color: #16a34a;
        }

        .btn-success:hover {
            background-color: #15803d;
        }

        .btn-secondary {
            background-color: #475569;
        }

        .btn-secondary:hover {
            background-color: #334155;
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

        @media (max-width: 1000px) {
            .filter-grid {
                grid-template-columns: 1fr 1fr;
            }
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media print {
            .navbar, .sidebar, .filter-grid, .action-buttons {
                display: none !important;
            }
            .app-container {
                display: block;
            }
            .main-content {
                padding: 0;
                background: #fff;
            }
            .card {
                border: none;
                box-shadow: none;
                padding: 0;
            }
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
            <a href="parcels.php" class="sidebar-item">
                <i class="fa-solid fa-box"></i>
                <span>Manage Parcels</span>
            </a>
            <a href="riders.php" class="sidebar-item">
                <i class="fa-solid fa-motorcycle"></i>
                <span>Manage Riders</span>
            </a>
            <a href="reports.php" class="sidebar-item active">
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

            <div class="card">
                <div class="card-header">
                    <h1 class="card-title">Delivery Activity Reports</h1>
                    <div class="action-buttons" style="display: flex; gap: 8px;">
                        <a href="reports.php?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>" class="btn-primary btn-success">
                            <i class="fa-solid fa-file-excel"></i> Export CSV
                        </a>
                        <button onclick="window.print()" class="btn-primary btn-secondary">
                            <i class="fa-solid fa-print"></i> Print Report
                        </button>
                    </div>
                </div>

                <!-- FILTER FORM -->
                <form method="GET" class="filter-grid">
                    <div class="form-group">
                        <label>Start Date</label>
                        <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
                    </div>
                    <div class="form-group">
                        <label>End Date</label>
                        <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="">All Statuses</option>
                            <option value="out_for_delivery" <?= $status_filter === 'out_for_delivery' ? 'selected' : '' ?>>Out For Delivery</option>
                            <option value="delivered" <?= $status_filter === 'delivered' ? 'selected' : '' ?>>Delivered</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Rider</label>
                        <select name="rider_id">
                            <option value="">All Riders</option>
                            <?php foreach ($riders as $r): ?>
                                <option value="<?= $r['rider_id'] ?>" <?= $rider_filter == $r['rider_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($r['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn-primary" style="height: 41px;">
                        <i class="fa-solid fa-filter"></i> Filter
                    </button>
                </form>

                <!-- STATS SUMMARY CARDS -->
                <div class="stats-grid">
                    <div class="stat-box">
                        <h3>Total Parcels</h3>
                        <div class="value"><?= $total_parcels ?></div>
                    </div>
                    <div class="stat-box">
                        <h3>Delivered</h3>
                        <div class="value" style="color: #16a34a;"><?= $delivered_count ?></div>
                    </div>
                    <div class="stat-box">
                        <h3>Out For Delivery</h3>
                        <div class="value" style="color: #d97706;"><?= $out_for_delivery_count ?></div>
                    </div>
                    <div class="stat-box">
                        <h3>Success Rate</h3>
                        <div class="value" style="color: #0284c7;"><?= $success_rate ?>%</div>
                    </div>
                </div>

                <!-- REPORT DATA TABLE -->
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Tracking #</th>
                                <th>Recipient</th>
                                <th>Delivery Address</th>
                                <th>Status</th>
                                <th>Assigned Rider</th>
                                <th>Created Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($reports)): ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; color: #94a3b8; padding: 25px;">
                                        No parcel records found for the selected criteria.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($reports as $row): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($row['tracking_number']) ?></strong></td>
                                    <td>
                                        <?= htmlspecialchars($row['recipient_name']) ?>
                                        <span class="phone-subtext"><?= htmlspecialchars($row['recipient_phone']) ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($row['delivery_address']) ?></td>
                                    <td>
                                        <span class="status-badge status-<?= htmlspecialchars($row['status']) ?>">
                                            <?= strtoupper(str_replace('_', ' ', $row['status'])) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($row['rider_name'] ?? 'Unassigned') ?></td>
                                    <td><?= htmlspecialchars($row['created_at']) ?></td>
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
