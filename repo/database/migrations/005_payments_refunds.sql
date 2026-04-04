-- Slice 5: Payments, Refunds, Idempotency

CREATE TABLE IF NOT EXISTS `payments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `invoice_id` INT UNSIGNED NOT NULL,
    `amount_cents` INT UNSIGNED NOT NULL,
    `paid_at` TIMESTAMP NOT NULL,
    `method` VARCHAR(50) NOT NULL DEFAULT 'cash',
    `reference_enc` VARCHAR(500) DEFAULT NULL,
    `posted_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`),
    FOREIGN KEY (`posted_by`) REFERENCES `users`(`id`),
    INDEX `idx_invoice` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `refunds` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `invoice_id` INT UNSIGNED NOT NULL,
    `amount_cents` INT UNSIGNED NOT NULL,
    `reason` TEXT NOT NULL,
    `issued_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`),
    FOREIGN KEY (`issued_by`) REFERENCES `users`(`id`),
    INDEX `idx_invoice` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `payment_idempotency` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `actor_id` INT UNSIGNED NOT NULL,
    `method` VARCHAR(10) NOT NULL,
    `route` VARCHAR(255) NOT NULL,
    `idempotency_key` VARCHAR(255) NOT NULL,
    `response_status` INT UNSIGNED NOT NULL,
    `response_json` JSON NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_scope` (`actor_id`, `method`, `route`, `idempotency_key`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `schema_versions` (`version`, `description`)
VALUES ('1.4.0', 'Payments, Refunds, Idempotency - Slice 5');
