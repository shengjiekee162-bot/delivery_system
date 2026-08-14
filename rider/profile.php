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

// Helper function to generate UUID v4 if not already defined
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

// Safe fallback for sanitize helper function if missing from functions.php
if (!function_exists('sanitize')) {
    function sanitize($data) {
        return htmlspecialchars(trim($data ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

// -------------------------------------------------------------
// Handle Form Submissions: Update Profile & Change Password
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. Update Profile Information & Profile Picture
    if (isset($_POST['action']) && $_POST['action'] === 'update_profile') {
        $name           = trim($_POST['name'] ?? '');
        $email          = trim($_POST['email'] ?? '');
        $phone          = trim($_POST['phone'] ?? '');
        $vehicle_number = trim($_POST['vehicle_number'] ?? '');

        if (!empty($name) && !empty($email)) {
            try {
                $db->beginTransaction();

                // Step 1: Verify user exists in the `users` table and retrieve current profile image
                $stmtCheckUser = $db->prepare("SELECT id, profile_image FROM users WHERE id = :id AND deleted_at IS NULL");
                $stmtCheckUser->execute([':id' => $user_id]);
                $currentUser = $stmtCheckUser->fetch(PDO::FETCH_ASSOC);

                if (!$currentUser) {
                    throw new Exception("Logged-in user record does not exist.");
                }

                $old_image_filename = $currentUser['profile_image'] ?? null;

                // Step 2: Handle File Upload for Profile Picture
                $profile_image_filename = null;
                if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                    $fileTmpPath = $_FILES['profile_image']['tmp_name'];
                    $fileName    = $_FILES['profile_image']['name'];
                    $fileSize    = $_FILES['profile_image']['size'];

                    $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];

                    if (!in_array($fileExtension, $allowedExtensions)) {
                        throw new Exception("Invalid image format. Allowed formats: JPG, JPEG, PNG, WEBP.");
                    }

                    // Security: Verify strict MIME type to prevent malicious uploads
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mimeType = finfo_file($finfo, $fileTmpPath);
                    finfo_close($finfo);

                    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
                    if (!in_array($mimeType, $allowedMimeTypes)) {
                        throw new Exception("File content does not match an allowed image type.");
                    }

                    if ($fileSize > 2 * 1024 * 1024) { // 2MB limit
                        throw new Exception("Profile image must be less than 2MB.");
                    }

                    $uploadFileDir = __DIR__ . '/../uploads/profile_images/';
                    if (!is_dir($uploadFileDir)) {
                        mkdir($uploadFileDir, 0755, true);
                    }

                    $newFileName = 'profile_' . $user_id . '_' . time() . '.' . $fileExtension;
                    $dest_path   = $uploadFileDir . $newFileName;

                    if (move_uploaded_file($fileTmpPath, $dest_path)) {
                        $profile_image_filename = $newFileName;

                        // Delete old profile image if it exists on disk
                        if (!empty($old_image_filename)) {
                            $old_file_path = $uploadFileDir . basename($old_image_filename);
                            if (file_exists($old_file_path)) {
                                @unlink($old_file_path);
                            }
                        }
                    } else {
                        throw new Exception("Failed to upload the image file.");
                    }
                }

                // Step 3: Update `users` table
                if ($profile_image_filename) {
                    $stmtUser = $db->prepare("
                        UPDATE users 
                        SET name = :name, email = :email, profile_image = :profile_image, updated_at = NOW() 
                        WHERE id = :id AND deleted_at IS NULL
                    ");
                    $stmtUser->execute([
                        ':name'          => $name,
                        ':email'         => $email,
                        ':profile_image' => $profile_image_filename,
                        ':id'            => $user_id
                    ]);

                    $_SESSION['profile_image'] = $profile_image_filename;
                } else {
                    $stmtUser = $db->prepare("
                        UPDATE users 
                        SET name = :name, email = :email, updated_at = NOW() 
                        WHERE id = :id AND deleted_at IS NULL
                    ");
                    $stmtUser->execute([
                        ':name'  => $name,
                        ':email' => $email,
                        ':id'    => $user_id
                    ]);
                }

                // Sync current session state
                $_SESSION['name'] = $name;
                $_SESSION['user_name'] = $name;

                // Step 4: Safely Update or Insert into `riders` table (Prevents FK 1452 constraint error)
                $stmtCheckRider = $db->prepare("SELECT id FROM riders WHERE user_id = :user_id AND deleted_at IS NULL");
                $stmtCheckRider->execute([':user_id' => $user_id]);
                $riderRecord = $stmtCheckRider->fetch(PDO::FETCH_ASSOC);

                if ($riderRecord) {
                    // Update existing rider record
                    $stmtRider = $db->prepare("
                        UPDATE riders 
                        SET phone = :phone, vehicle_number = :vehicle_number, updated_at = NOW() 
                        WHERE user_id = :user_id AND deleted_at IS NULL
                    ");
                    $stmtRider->execute([
                        ':phone'          => $phone,
                        ':vehicle_number' => $vehicle_number,
                        ':user_id'        => $user_id
                    ]);
                } else {
                    // Insert new rider record if it didn't exist yet
                    $new_rider_id = generate_uuid();
                    $stmtRider = $db->prepare("
                        INSERT INTO riders (id, user_id, phone, vehicle_number, created_at, updated_at) 
                        VALUES (:id, :user_id, :phone, :vehicle_number, NOW(), NOW())
                    ");
                    $stmtRider->execute([
                        ':id'             => $new_rider_id,
                        ':user_id'        => $user_id,
                        ':phone'          => $phone,
                        ':vehicle_number' => $vehicle_number
                    ]);
                }

                $db->commit();

                if (function_exists('log_activity')) {
                    log_activity($user_id, 'Updated Rider Profile Information');
                }
                $success_message = "Profile details updated successfully!";
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $error_message = "Failed to update profile: " . $e->getMessage();
            }
        } else {
            $error_message = "Name and email fields are required.";
        }
    }

    // 2. Change Password
    if (isset($_POST['action']) && $_POST['action'] === 'change_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password     = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (!empty($current_password) && !empty($new_password) && !empty($confirm_password)) {
            if ($new_password !== $confirm_password) {
                $error_message = "New password and confirm password do not match.";
            } else {
                try {
                    $stmtUser = $db->prepare("SELECT password_hash FROM users WHERE id = :id AND deleted_at IS NULL");
                    $stmtUser->execute([':id' => $user_id]);
                    $user_rec = $stmtUser->fetch(PDO::FETCH_ASSOC);

                    if ($user_rec && password_verify($current_password, $user_rec['password_hash'])) {
                        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                        
                        $stmtPass = $db->prepare("UPDATE users SET password_hash = :password, updated_at = NOW() WHERE id = :id AND deleted_at IS NULL");
                        $stmtPass->execute([':password' => $new_hash, ':id' => $user_id]);

                        if (function_exists('log_activity')) {
                            log_activity($user_id, 'Changed Account Password');
                        }
                        $success_message = "Password updated successfully!";
                    } else {
                        $error_message = "Current password is incorrect.";
                    }
                } catch (Exception $e) {
                    $error_message = "Error updating password: " . $e->getMessage();
                }
            }
        } else {
            $error_message = "Please complete all password fields.";
        }
    }
}

// -------------------------------------------------------------
// Fetch Profile Details
// -------------------------------------------------------------
$rider_data = null;
try {
    $stmtProfile = $db->prepare("
        SELECT u.name, u.email, u.profile_image, r.phone, r.vehicle_number 
        FROM users u 
        LEFT JOIN riders r ON u.id = r.user_id AND r.deleted_at IS NULL
        WHERE u.id = :user_id AND u.deleted_at IS NULL
        LIMIT 1
    ");
    $stmtProfile->execute([':user_id' => $user_id]);
    $rider_data = $stmtProfile->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error_message = "Error retrieving profile: " . $e->getMessage();
}

$page_title = "Rider Profile Management";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($page_title) ?></title>

    <!-- FontAwesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />

    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #f1f5f9; color: #0f172a; min-height: 100vh; display: flex; flex-direction: column; }

        .navbar { background-color: #0f172a; color: #ffffff; height: 60px; display: flex; align-items: center; justify-content: space-between; padding: 0 20px; }
        .navbar-brand { font-size: 1.1rem; font-weight: 700; display: flex; align-items: center; gap: 10px; color: #ffffff; text-decoration: none; }
        .btn-logout { background-color: #ef4444; color: #ffffff; padding: 6px 14px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 600; }

        .container { max-width: 800px; margin: 24px auto; padding: 0 16px; width: 100%; flex: 1; }

        .card { background: #ffffff; border-radius: 12px; padding: 24px; border: 1px solid #e2e8f0; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .card-title { font-size: 1.25rem; font-weight: 700; display: flex; align-items: center; gap: 10px; }

        .alert { padding: 12px 16px; border-radius: 8px; font-size: 14px; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .alert-error { background-color: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-success { background-color: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }

        /* Profile Image Styles */
        .profile-avatar-wrapper { display: flex; align-items: center; gap: 20px; margin-bottom: 24px; padding-bottom: 20px; border-bottom: 1px solid #f1f5f9; }
        .profile-avatar { width: 90px; height: 90px; border-radius: 50%; object-fit: cover; border: 3px solid #0284c7; background-color: #e2e8f0; }
        .avatar-placeholder { width: 90px; height: 90px; border-radius: 50%; background-color: #e2e8f0; color: #64748b; display: flex; align-items: center; justify-content: center; font-size: 36px; border: 3px solid #cbd5e1; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px; }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group.full { grid-column: span 2; }
        .form-group label { font-size: 13px; font-weight: 600; color: #334155; }
        .form-group input { width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; color: #0f172a; outline: none; }
        .form-group input:focus { border-color: #0284c7; box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15); }
        .form-group input[type="file"] { padding: 6px 12px; background: #f8fafc; cursor: pointer; }

        .btn-submit { background-color: #0284c7; color: #ffffff; border: none; padding: 10px 18px; border-radius: 6px; font-weight: 600; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
        .btn-submit:hover { background-color: #0369a1; }

        @media (max-width: 600px) {
            .form-grid { grid-template-columns: 1fr; }
            .form-group.full { grid-column: span 1; }
            .profile-avatar-wrapper { flex-direction: column; text-align: center; }
        }
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
            <a href="dashboard.php" style="color:#ffffff; text-decoration:none; font-size: 14px;"><i class="fa-solid fa-gauge"></i> Dashboard</a>
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

        <!-- Rider Profile Information Card -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fa-solid fa-user-gear"></i> Profile Information</h2>
            </div>

            <form method="POST" action="profile.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_profile">

                <!-- Avatar Preview & Upload Area -->
                <div class="profile-avatar-wrapper">
                    <?php if (!empty($rider_data['profile_image']) && file_exists(__DIR__ . '/../uploads/profile_images/' . $rider_data['profile_image'])): ?>
                        <img src="../uploads/profile_images/<?= sanitize($rider_data['profile_image']) ?>" alt="Profile Picture" class="profile-avatar">
                    <?php else: ?>
                        <div class="avatar-placeholder">
                            <i class="fa-solid fa-user"></i>
                        </div>
                    <?php endif; ?>

                    <div class="form-group" style="flex: 1;">
                        <label>Profile Picture</label>
                        <input type="file" name="profile_image" accept="image/jpeg, image/png, image/webp">
                        <span style="font-size: 12px; color: #64748b;">Allowed formats: JPG, PNG, WEBP (Max 2MB)</span>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="name" value="<?= sanitize($rider_data['name'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" value="<?= sanitize($rider_data['email'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="text" name="phone" value="<?= sanitize($rider_data['phone'] ?? '') ?>" placeholder="e.g. +60123456789">
                    </div>

                    <div class="form-group">
                        <label>Vehicle Registration Number</label>
                        <input type="text" name="vehicle_number" value="<?= sanitize($rider_data['vehicle_number'] ?? '') ?>" placeholder="e.g. BAA 1234">
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fa-solid fa-floppy-disk"></i> Save Profile Details
                </button>
            </form>
        </div>

        <!-- Security & Password Card -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fa-solid fa-lock"></i> Security Settings</h2>
            </div>

            <form method="POST" action="profile.php">
                <input type="hidden" name="action" value="change_password">

                <div class="form-grid">
                    <div class="form-group full">
                        <label>Current Password</label>
                        <input type="password" name="current_password" required>
                    </div>

                    <div class="form-group">
                        <label>New Password</label>
                        <input type="password" name="new_password" required>
                    </div>

                    <div class="form-group">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" required>
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fa-solid fa-key"></i> Update Password
                </button>
            </form>
        </div>
    </div>

    <?php require __DIR__ . '/../includes/rider_presence_script.php'; ?>
</body>
</html>
