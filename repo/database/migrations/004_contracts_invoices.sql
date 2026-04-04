-- Slice 4: Contracts, Invoices, Snapshots

CREATE TABLE IF NOT EXISTS `contracts` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `profile_id` INT UNSIGNED NOT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `rent_cents` INT UNSIGNED NOT NULL,
    `deposit_cents` INT UNSIGNED NOT NULL DEFAULT 0,
    `maintenance_cents` INT UNSIGNED NOT NULL DEFAULT 0,
    `frequency` ENUM('monthly','quarterly','yearly') NOT NULL DEFAULT 'monthly',
    `status` ENUM('active','terminated','completed') NOT NULL DEFAULT 'active',
    `geo_scope_level` ENUM('village','township','county') NOT NULL,
    `geo_scope_id` INT UNSIGNED NOT NULL,
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`profile_id`) REFERENCES `entity_profiles`(`id`),
    FOREIGN KEY (`geo_scope_id`) REFERENCES `geo_areas`(`id`),
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`),
    INDEX `idx_profile` (`profile_id`),
    INDEX `idx_scope` (`geo_scope_level`, `geo_scope_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `invoices` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `contract_id` INT UNSIGNED NOT NULL,
    `due_date` DATE NOT NULL,
    `amount_cents` INT UNSIGNED NOT NULL,
    `late_fee_cents` INT UNSIGNED NOT NULL DEFAULT 0,
    `status` ENUM('unpaid','paid','overdue') NOT NULL DEFAULT 'unpaid',
    `snapshot_version` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`contract_id`) REFERENCES `contracts`(`id`) ON DELETE CASCADE,
    INDEX `idx_contract` (`contract_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_due` (`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `invoice_snapshots` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `invoice_id` INT UNSIGNED NOT NULL,
    `snapshot_json` JSON NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE CASCADE,
    INDEX `idx_invoice` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `schema_versions` (`version`, `description`)
VALUES ('1.3.0', 'Contracts and Invoices - Slice 4');
