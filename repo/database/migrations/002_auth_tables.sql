-- Auth tables migration: users, auth_failures, user_tokens

-- Users table
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(100) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('farmer', 'enterprise', 'collective', 'system_admin') NOT NULL DEFAULT 'farmer',
    `geo_scope_level` ENUM('village', 'township', 'county') NOT NULL DEFAULT 'village',
    `geo_scope_id` INT UNSIGNED NOT NULL,
    `status` ENUM('active', 'inactive', 'locked') NOT NULL DEFAULT 'active',
    `mfa_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `mfa_secret` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`geo_scope_id`) REFERENCES `geo_areas`(`id`),
    INDEX `idx_username` (`username`),
    INDEX `idx_role` (`role`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Auth failures: each row is a single failed login attempt with timestamp
-- Used for rolling-window lockout (5 failures in 15 minutes)
CREATE TABLE IF NOT EXISTS `auth_failures` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `failed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `ip` VARCHAR(45) DEFAULT NULL,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_failed` (`user_id`, `failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bearer tokens for stateless auth
CREATE TABLE IF NOT EXISTS `user_tokens` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `token` VARCHAR(128) NOT NULL UNIQUE,
    `expires_at` TIMESTAMP NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_token` (`token`),
    INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Update schema version
INSERT INTO `schema_versions` (`version`, `description`)
VALUES ('1.1.0', 'Auth tables - Slice 2');
