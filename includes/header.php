<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/auth.php';
require_login();

// Default fallback page title if not set on individual page
$page_title = $page_title ?? 'Courier Management System';
$current_page = basename($_SERVER['PHP_SELF']);
$user_role = $_SESSION['user_role'] ?? 'rider';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($page_title) ?></title>
    
    <!-- Shared System CSS -->
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <!-- Leaflet GIS Map Assets (Include when on admin pages) -->
    <?php if ($user_role === 'admin'): ?>
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <?php endif; ?>
</head>
<body>

<?php if ($user_role === 'admin'): ?>
    <!-- Admin Standard Navigation Wrapper -->
    <div class="dashboard-container">
        <aside class="sidebar">
            <div class="brand">Courier Admin</div>
            <nav>
                <a href="dashboard.php" class="<?= $current_page === 'dashboard.php' ? 'active' : '' ?>">
                    Dashboard
                </a>
                <a href="parcels.php" class="<?= in_array($current_page, ['parcels.php', 'view_parcel.php']) ? 'active' : '' ?>">
                    Parcel Management
                </a>
                <a href="../logout.php">Logout</a>
            </nav>
        </aside>

        <main class="main-content">
            <header class="topbar">
                <h2><?= sanitize($page_title) ?></h2>
                <div class="user-profile-badge">
                    <span>Logged in as: <strong><?= sanitize($_SESSION['user_name'] ?? 'Admin') ?></strong></span>
                    <span class="badge badge-info" style="margin-left:8px;">Admin</span>
                </div>
            </header>
<?php else: ?>
    <!-- Rider Portal Header Layout -->
    <div class="rider-header-container">
        <div class="mobile-header card" style="margin-bottom: 15px;">
            <div>
                <h3 style="margin: 0;"><?= sanitize($page_title) ?></h3>
                <small style="color: #64748b;">Rider: <strong><?= sanitize($_SESSION['user_name'] ?? 'Rider') ?></strong></small>
            </div>
            <div>
                <a href="../logout.php" class="btn btn-sm btn-secondary">Logout</a>
            </div>
        </div>
    </div>
    <main class="rider-body">
<?php endif; ?>