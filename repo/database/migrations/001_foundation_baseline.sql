-- Foundation baseline migration
-- Creates the schema_version tracking and initial system tables

-- Schema version metadata (separate from migration tracker for application-level queries)
CREATE TABLE IF NOT EXISTS `schema_versions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `version` VARCHAR(50) NOT NULL,
    `description` VARCHAR(255) NOT NULL,
    `applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `schema_versions` (`version`, `description`)
VALUES ('1.0.0', 'Foundation baseline - Slice 1');

-- Geographic hierarchy reference table
CREATE TABLE IF NOT EXISTS `geo_areas` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `level` ENUM('county', 'township', 'village') NOT NULL,
    `parent_id` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`parent_id`) REFERENCES `geo_areas`(`id`) ON DELETE SET NULL,
    INDEX `idx_level` (`level`),
    INDEX `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed initial geographic hierarchy
INSERT INTO `geo_areas` (`id`, `name`, `level`, `parent_id`) VALUES
(1, 'Default County', 'county', NULL),
(2, 'Default Township', 'township', 1),
(3, 'Default Village', 'village', 2);
