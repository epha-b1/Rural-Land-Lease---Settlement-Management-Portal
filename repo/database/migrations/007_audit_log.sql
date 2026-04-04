-- Slice 7: Audit log (append-only)

CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `actor_id` INT UNSIGNED DEFAULT NULL,
    `event_type` VARCHAR(100) NOT NULL,
    `resource_type` VARCHAR(100) DEFAULT NULL,
    `resource_id` INT UNSIGNED DEFAULT NULL,
    `before_json` JSON DEFAULT NULL,
    `after_json` JSON DEFAULT NULL,
    `ip` VARCHAR(45) DEFAULT NULL,
    `device_fingerprint` VARCHAR(255) DEFAULT NULL,
    `trace_id` VARCHAR(64) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_event_type` (`event_type`),
    INDEX `idx_actor` (`actor_id`),
    INDEX `idx_resource` (`resource_type`, `resource_id`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `schema_versions` (`version`, `description`)
VALUES ('1.6.0', 'Audit log - Slice 7');
