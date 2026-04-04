-- Slice 3: Profiles, Verification, Scope enforcement, Duplicate detection

-- Entity profiles (farmer/enterprise/collective master records)
CREATE TABLE IF NOT EXISTS `entity_profiles` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `entity_type` ENUM('farmer', 'enterprise', 'collective') NOT NULL,
    `display_name` VARCHAR(200) NOT NULL,
    `address` VARCHAR(500) NOT NULL DEFAULT '',
    `id_last4` VARCHAR(4) DEFAULT NULL,
    `license_last4` VARCHAR(4) DEFAULT NULL,
    `extra_fields_json` JSON DEFAULT NULL,
    `geo_scope_level` ENUM('village', 'township', 'county') NOT NULL DEFAULT 'village',
    `geo_scope_id` INT UNSIGNED NOT NULL,
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`geo_scope_id`) REFERENCES `geo_areas`(`id`),
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`),
    INDEX `idx_entity_type` (`entity_type`),
    INDEX `idx_geo_scope` (`geo_scope_level`, `geo_scope_id`),
    INDEX `idx_display_name` (`display_name`),
    INDEX `idx_dup_match` (`display_name`, `address`(100), `id_last4`, `license_last4`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Verification requests (real-name/business verification)
CREATE TABLE IF NOT EXISTS `verification_requests` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `id_number_enc` VARCHAR(500) DEFAULT NULL,
    `license_number_enc` VARCHAR(500) DEFAULT NULL,
    `scan_path` VARCHAR(500) DEFAULT NULL,
    `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    `submitted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `reviewed_at` TIMESTAMP DEFAULT NULL,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_status` (`status`),
    INDEX `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Verification decisions (reviewer actions with mandatory reason for rejections)
CREATE TABLE IF NOT EXISTS `verification_decisions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `request_id` INT UNSIGNED NOT NULL,
    `reviewer_id` INT UNSIGNED NOT NULL,
    `decision` ENUM('approved', 'rejected') NOT NULL,
    `reason` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`request_id`) REFERENCES `verification_requests`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`reviewer_id`) REFERENCES `users`(`id`),
    INDEX `idx_request` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Duplicate flags (raised on profile create/update when matching criteria met)
CREATE TABLE IF NOT EXISTS `duplicate_flags` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `left_profile_id` INT UNSIGNED NOT NULL,
    `right_profile_id` INT UNSIGNED NOT NULL,
    `match_basis` VARCHAR(255) NOT NULL,
    `status` ENUM('open', 'merged', 'dismissed') NOT NULL DEFAULT 'open',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`left_profile_id`) REFERENCES `entity_profiles`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`right_profile_id`) REFERENCES `entity_profiles`(`id`) ON DELETE CASCADE,
    INDEX `idx_left` (`left_profile_id`),
    INDEX `idx_right` (`right_profile_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Profile merge history
CREATE TABLE IF NOT EXISTS `profile_merge_history` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `source_profile_id` INT UNSIGNED NOT NULL,
    `target_profile_id` INT UNSIGNED NOT NULL,
    `merged_by` INT UNSIGNED NOT NULL,
    `diff_json` JSON DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`merged_by`) REFERENCES `users`(`id`),
    INDEX `idx_source` (`source_profile_id`),
    INDEX `idx_target` (`target_profile_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Extra field definitions (admin-configurable per entity type)
CREATE TABLE IF NOT EXISTS `extra_field_definitions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `entity_type` ENUM('farmer', 'enterprise', 'collective') NOT NULL,
    `field_key` VARCHAR(50) NOT NULL,
    `field_label` VARCHAR(100) NOT NULL,
    `field_type` ENUM('text', 'number', 'date', 'select') NOT NULL DEFAULT 'text',
    `options_json` JSON DEFAULT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_type_key` (`entity_type`, `field_key`),
    INDEX `idx_entity_type` (`entity_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default extra field definitions
INSERT INTO `extra_field_definitions` (`entity_type`, `field_key`, `field_label`, `field_type`, `options_json`) VALUES
('farmer', 'primary_crop', 'Primary Crop', 'text', NULL),
('farmer', 'land_area_acres', 'Land Area (acres)', 'number', NULL),
('enterprise', 'business_type', 'Business Type', 'select', '["Agriculture","Processing","Storage","Transport","Other"]'),
('enterprise', 'employee_count', 'Employee Count', 'number', NULL),
('collective', 'equipment_storage', 'Equipment Storage Needs', 'text', NULL),
('collective', 'member_count', 'Member Count', 'number', NULL);

-- Update schema version
INSERT INTO `schema_versions` (`version`, `description`)
VALUES ('1.2.0', 'Profiles, Verification, Scope - Slice 3');
