-- Issue #1: Local CAPTCHA challenges

CREATE TABLE IF NOT EXISTS `captcha_challenges` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `challenge_id` VARCHAR(64) NOT NULL UNIQUE,
    `answer_hash` VARCHAR(255) NOT NULL,
    `expires_at` TIMESTAMP NOT NULL,
    `consumed` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_challenge_id` (`challenge_id`),
    INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `schema_versions` (`version`, `description`)
VALUES ('1.8.0', 'CAPTCHA challenges - Remediation Issue #1');
