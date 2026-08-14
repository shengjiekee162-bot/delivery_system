<?php
header('Content-Type: application/json');
require_once '../includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$parcel_id = sanitize($_POST['parcel_id'] ?? '');
$status = sanitize($_POST['status'] ?? '');
$remarks = sanitize($_POST['remarks'] ?? '');
$photo_base64 = $_POST['photo'] ?? null;

$allowed_statuses = ['pending', 'out_for_delivery', 'delivered', 'failed_delivery'];

if (!in_array($status, $allowed_statuses) || empty($parcel_id)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid parameters provided']);
    exit();
}

$db = get_db_connection();

try {
    $db->beginTransaction();

    // Update parcel status
    $stmt = $db->prepare("UPDATE parcels SET status = ?, remarks = ? WHERE id = ?");
    $stmt->execute([$status, $remarks, $parcel_id]);

    // Insert history
    $histStmt = $db->prepare("INSERT INTO parcel_status_history (id, parcel_id, status, changed_by_user_id, remarks) VALUES (?, ?, ?, ?, ?)");
    $histStmt->execute([generate_uuid(), $parcel_id, $status, $_SESSION['user_id'], $remarks]);

    // Process delivery proof image if uploaded
    if (!empty($photo_base64)) {
        if (preg_match('/^data:image\/(\w+);base64,/', $photo_base64, $type)) {
            $data = substr($photo_base64, strpos($photo_base64, ',') + 1);
            $type = strtolower($type[1]);

            if (!in_array($type, ['jpg', 'jpeg', 'png', 'webp'])) {
                throw new Exception('Invalid image type format.');
            }

            $data = base64_decode($data);
            if ($data === false) {
                throw new Exception('Base64 decode failed.');
            }

            $dir = '../uploads/proof/';
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            $filename = generate_uuid() . '.' . $type;
            $filepath = $dir . $filename;
            file_put_contents($filepath, $data);

            $relative_path = 'uploads/proof/' . $filename;
            $imgStmt = $db->prepare("INSERT INTO delivery_photos (id, parcel_id, file_path) VALUES (?, ?, ?)");
            $imgStmt->execute([generate_uuid(), $parcel_id, $relative_path]);
        }
    }

    $db->commit();
    log_activity($_SESSION['user_id'], "Updated parcel ($parcel_id) status to $status");
    echo json_encode(['status' => 'success', 'message' => 'Parcel updated successfully']);

} catch (Exception $e) {
    $db->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}