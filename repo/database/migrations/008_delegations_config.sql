-- Slice 8: Delegations, Admin Config

CREATE TABLE IF NOT EXISTS `access_delegations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `grantor_id` INT UNSIGNED NOT NULL,
    `grantee_id` INT UNSIGNED NOT NULL,
    `scope_level` ENUM('village','township','county') NOT NULL,
    `scope_id` INT UNSIGNED NOT NULL,
    `expires_at` TIMESTAMP NOT NULL,
    `status` ENUM('pending_approval','active','expired','revoked') NOT NULL DEFAULT 'pending_approval',
    `approved_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`grantor_id`) REFERENCES `users`(`id`),
    FOREIGN KEY (`grantee_id`) REFERENCES `users`(`id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `admin_config` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `config_key` VARCHAR(100) NOT NULL UNIQUE,
    `config_value` TEXT NOT NULL,
    `updated_by` INT UNSIGNED DEFAULT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default config values
INSERT INTO `admin_config` (`config_key`, `config_value`) VALUES
('message_retention_months', '24'),
('max_delegation_days', '30'),
('risk_cache_refresh_minutes', '10');

INSERT INTO `schema_versions` (`version`, `description`)
VALUES ('1.7.0', 'Delegations and Config - Slice 8');
