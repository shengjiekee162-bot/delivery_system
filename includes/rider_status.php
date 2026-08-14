<?php

if (!function_exists('ensure_rider_profile')) {
    function ensure_rider_profile(PDO $db, string $user_id): ?array
    {
        $stmt = $db->prepare("
            SELECT r.id AS rider_id, r.is_online, r.phone, r.vehicle_number
            FROM riders r
            WHERE r.user_id = :user_id AND r.deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $user_id]);
        $rider = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($rider) {
            $_SESSION['rider_id'] = $rider['rider_id'];
            return $rider;
        }

        $new_rider_id = function_exists('generate_uuid') ? generate_uuid() : sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        $insert = $db->prepare("
            INSERT INTO riders (id, user_id, phone, vehicle_number, is_online, last_active_at, created_at)
            VALUES (:id, :user_id, '', 'N/A', 0, NULL, NOW())
        ");
        $insert->execute([':id' => $new_rider_id, ':user_id' => $user_id]);

        $_SESSION['rider_id'] = $new_rider_id;

        return [
            'rider_id'       => $new_rider_id,
            'is_online'      => 0,
            'phone'          => '',
            'vehicle_number' => 'N/A',
        ];
    }
}

if (!function_exists('set_rider_online_status')) {
    function set_rider_online_status(PDO $db, string $user_id, int $is_online): bool
    {
        ensure_rider_profile($db, $user_id);

        $stmt = $db->prepare("
            UPDATE riders
            SET is_online = :status,
                last_active_at = CASE WHEN :status = 1 THEN NOW() ELSE last_active_at END,
                updated_at = NOW()
            WHERE user_id = :user_id AND deleted_at IS NULL
        ");

        return $stmt->execute([
            ':status'  => $is_online ? 1 : 0,
            ':user_id' => $user_id,
        ]);
    }
}

if (!function_exists('mark_rider_online')) {
    function mark_rider_online(PDO $db, string $user_id): void
    {
        set_rider_online_status($db, $user_id, 1);
    }
}

if (!function_exists('mark_rider_offline')) {
    function mark_rider_offline(PDO $db, string $user_id): void
    {
        set_rider_online_status($db, $user_id, 0);
    }
}
