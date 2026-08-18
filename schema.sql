
-- ========================================================
-- 1. SCHEMAS (TABLE CREATION)
-- ========================================================

-- 1. Users Table
CREATE TABLE IF NOT EXISTS `users` (
    `id` CHAR(36) NOT NULL PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `profile_image` VARCHAR(500) DEFAULT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('admin', 'rider') NOT NULL DEFAULT 'rider',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Riders Profile Table
CREATE TABLE IF NOT EXISTS `riders` (
    `id` CHAR(36) NOT NULL PRIMARY KEY,
    `user_id` CHAR(36) NOT NULL,
    `phone` VARCHAR(20) NOT NULL,
    `vehicle_number` VARCHAR(30) NOT NULL,
    `is_online` TINYINT(1) NOT NULL DEFAULT 0,
    `last_active_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME DEFAULT NULL,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Parcels Table (Strict 4-status lifecycle)
CREATE TABLE IF NOT EXISTS `parcels` (
    `id` CHAR(36) NOT NULL PRIMARY KEY,
    `tracking_number` VARCHAR(50) NOT NULL UNIQUE,
    `recipient_name` VARCHAR(100) NOT NULL,
    `recipient_phone` VARCHAR(20) NOT NULL,
    `delivery_address` TEXT NOT NULL,
    `latitude` DECIMAL(10, 8) DEFAULT NULL,
    `longitude` DECIMAL(11, 8) DEFAULT NULL,
    `status` ENUM('pending', 'out_for_delivery', 'delivered', 'failed') NOT NULL DEFAULT 'pending',
    `assigned_rider_id` CHAR(36) DEFAULT NULL,
    `remarks` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME DEFAULT NULL,
    FOREIGN KEY (`assigned_rider_id`) REFERENCES `riders`(`id`) ON DELETE SET NULL,
    INDEX (`status`),
    INDEX (`tracking_number`),
    INDEX `idx_status_created` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Parcel Status History Table
CREATE TABLE IF NOT EXISTS `parcel_status_history` (
    `id` CHAR(36) NOT NULL PRIMARY KEY,
    `parcel_id` CHAR(36) NOT NULL,
    `status` VARCHAR(50) NOT NULL,
    `changed_by_user_id` CHAR(36) NOT NULL,
    `remarks` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`parcel_id`) REFERENCES `parcels`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`changed_by_user_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Rider Live GPS Tracking Table
CREATE TABLE IF NOT EXISTS `rider_locations` (
    `id` CHAR(36) NOT NULL PRIMARY KEY,
    `rider_id` CHAR(36) NOT NULL,
    `latitude` DECIMAL(10, 8) NOT NULL,
    `longitude` DECIMAL(11, 8) NOT NULL,
    `recorded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`rider_id`) REFERENCES `riders`(`id`) ON DELETE CASCADE,
    INDEX (`rider_id`, `recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Delivery Photo Proof Table
CREATE TABLE IF NOT EXISTS `delivery_photos` (
    `id` CHAR(36) NOT NULL PRIMARY KEY,
    `parcel_id` CHAR(36) NOT NULL,
    `file_path` VARCHAR(255) NOT NULL,
    `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`parcel_id`) REFERENCES `parcels`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Activity Audit Logs Table
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` CHAR(36) NOT NULL PRIMARY KEY,
    `user_id` CHAR(36) DEFAULT NULL,
    `action` VARCHAR(100) NOT NULL,
    `details` TEXT DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_user` (`user_id`),
    INDEX `idx_action` (`action`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================================
-- 2. ESSENTIAL SEED DATA
-- ========================================================

-- 1. Insert Default Admin & Rider
INSERT INTO `users` (`id`, `name`, `email`, `profile_image`, `password_hash`, `role`) VALUES
('a0000000-0000-0000-0000-000000000001', 'System Admin', 'admin@courier.com', NULL, '$2a$12$uoxSA3J84kmYWdPLU0sqgOBfn7Y5d.fuPLlBERDvx.agGnJjkdGDy', 'admin'),
('r0000000-0000-0000-0000-000000000001', 'Ahmad Rider', 'rider@courier.com', NULL, '$2a$12$dhxUrVHbK.oP9J36sC8ZB.LUSIiy9y1bY77pd31dKHrlbDij0bNbS', 'rider')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 2. Insert Rider Profile
INSERT INTO `riders` (`id`, `user_id`, `phone`, `vehicle_number`, `is_online`, `last_active_at`) VALUES
('b0000000-0000-0000-0000-000000000001', 'r0000000-0000-0000-0000-000000000001', '+60123456789', 'MOTO-9921', 1, NOW())
ON DUPLICATE KEY UPDATE `is_online` = 1;

-- 3. Insert Initial GPS Location
INSERT INTO `rider_locations` (`id`, `rider_id`, `latitude`, `longitude`, `recorded_at`) VALUES
('l0000000-0000-0000-0000-000000000001', 'b0000000-0000-0000-0000-000000000001', 5.37780000, 100.39960000, NOW())
ON DUPLICATE KEY UPDATE `recorded_at` = NOW();

-- 4. Initial Audit Log
INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `details`, `ip_address`) VALUES
(UUID(), 'a0000000-0000-0000-0000-000000000001', 'SYSTEM_INIT', 'Database schema setup and initial seed verified.', '127.0.0.1');

-- ========================================================
-- 3. SAFE CLEANUP & MIGRATION DATA
-- ========================================================

SET SQL_SAFE_UPDATES = 0;

-- Migration step: Safely add or expand profile_image column size on existing databases
SET @dbname = DATABASE();
SET @tablename = "users";
SET @columnname = "profile_image";

SET @preparedStatement = (SELECT IF(
    (
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE (table_name = @tablename)
          AND (table_schema = @dbname)
          AND (column_name = @columnname)
    ) > 0,
    "ALTER TABLE users MODIFY COLUMN profile_image VARCHAR(500) NULL;",
    "ALTER TABLE users ADD COLUMN profile_image VARCHAR(500) NULL AFTER email;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Clean up mock records
DELETE FROM `parcels` 
WHERE `id` = 'p0000000-0000-0000-0000-000000000001' 
   OR `tracking_number` = 'TRK-100200300' 
   OR `recipient_name` = 'Ahmad Razak';

-- Migration step: Normalize any legacy status values to current schema
UPDATE `parcels` SET `status` = 'pending' WHERE `status` = 'assigned';
UPDATE `parcels` SET `status` = 'failed' WHERE `status` = 'failed_delivery';

-- Reset invalid coordinates outside Malaysia
UPDATE `parcels` 
SET `latitude` = NULL, `longitude` = NULL 
WHERE `latitude` < 0.8 OR `latitude` > 7.5 
   OR `longitude` < 98.5 OR `longitude` > 119.5;

SET SQL_SAFE_UPDATES = 1;
