-- Slice 6: Messaging, Risk Controls

CREATE TABLE IF NOT EXISTS `conversations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `scope_level` ENUM('village','township','county') NOT NULL,
    `scope_id` INT UNSIGNED NOT NULL,
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`),
    INDEX `idx_scope` (`scope_level`, `scope_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `attachments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `file_name` VARCHAR(255) NOT NULL,
    `mime_type` VARCHAR(100) NOT NULL,
    `size_bytes` INT UNSIGNED NOT NULL,
    `storage_path` VARCHAR(500) NOT NULL,
    `checksum_sha256` VARCHAR(64) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `messages` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `conversation_id` INT UNSIGNED NOT NULL,
    `sender_id` INT UNSIGNED NOT NULL,
    `body` TEXT DEFAULT NULL,
    `message_type` ENUM('text','voice','image') NOT NULL DEFAULT 'text',
    `attachment_id` INT UNSIGNED DEFAULT NULL,
    `read_at` TIMESTAMP DEFAULT NULL,
    `recalled_at` TIMESTAMP DEFAULT NULL,
    `risk_result` VARCHAR(20) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`conversation_id`) REFERENCES `conversations`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`sender_id`) REFERENCES `users`(`id`),
    FOREIGN KEY (`attachment_id`) REFERENCES `attachments`(`id`),
    INDEX `idx_conversation` (`conversation_id`),
    INDEX `idx_sender` (`sender_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `message_reports` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `message_id` INT UNSIGNED NOT NULL,
    `reporter_id` INT UNSIGNED NOT NULL,
    `category` VARCHAR(50) NOT NULL,
    `reason` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`),
    FOREIGN KEY (`reporter_id`) REFERENCES `users`(`id`),
    INDEX `idx_message` (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `risk_rules` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `pattern` VARCHAR(500) NOT NULL,
    `is_regex` TINYINT(1) NOT NULL DEFAULT 0,
    `action` ENUM('warn','block','flag') NOT NULL DEFAULT 'warn',
    `category` VARCHAR(50) NOT NULL DEFAULT 'general',
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `updated_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed sample risk rules
INSERT INTO `risk_rules` (`pattern`, `is_regex`, `action`, `category`) VALUES
('fraud', 0, 'warn', 'fraud'),
('scam', 0, 'block', 'fraud'),
('harassment', 0, 'flag', 'harassment');

INSERT INTO `schema_versions` (`version`, `description`)
VALUES ('1.5.0', 'Messaging and Risk - Slice 6');
