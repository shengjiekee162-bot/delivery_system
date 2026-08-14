<?php
header('Content-Type: application/json');
require_once '../includes/auth.php';

// Ensure user is authenticated
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$query = sanitize($_GET['q'] ?? $_GET['query'] ?? '');
$status = sanitize($_GET['status'] ?? '');

$db = get_db_connection();

try {
    $sql = "SELECT p.id, p.tracking_number, p.recipient_name, p.recipient_phone, 
                   p.delivery_address, p.status, p.created_at,
                   u.name as rider_name, r.vehicle_number
            FROM parcels p
            LEFT JOIN riders r ON p.assigned_rider_id = r.id
            LEFT JOIN users u ON r.user_id = u.id
            WHERE p.deleted_at IS NULL";

    $params = [];

    // Filter by search keyword (tracking number, name, phone, address)
    if (!empty($query)) {
        $sql .= " AND (p.tracking_number LIKE ? OR p.recipient_name LIKE ? OR p.recipient_phone LIKE ? OR p.delivery_address LIKE ?)";
        $searchTerm = "%{$query}%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    // Filter by delivery status
    if (!empty($status)) {
        $sql .= " AND p.status = ?";
        $params[] = $status;
    }

    // Role-Based Constraint: Riders can only search through their own assigned parcels
    if ($_SESSION['user_role'] === 'rider') {
        if (!isset($_SESSION['rider_id'])) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Rider profile not associated.']);
            exit();
        }
        $sql .= " AND p.assigned_rider_id = ?";
        $params[] = $_SESSION['rider_id'];
    }

    $sql .= " ORDER BY p.created_at DESC LIMIT 50";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $parcels = $stmt->fetchAll();

    echo json_encode([
        'status' => 'success',
        'count' => count($parcels),
        'data' => $parcels
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to search parcels: ' . $e->getMessage()]);
}