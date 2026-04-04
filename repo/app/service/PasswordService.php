<?php
declare(strict_types=1);

namespace app\service;

/**
 * Server-side password policy enforcement.
 * Policy: minimum 12 characters, at least one uppercase, one lowercase,
 * one digit, and one symbol.
 */
class PasswordService
{
    public const MIN_LENGTH = 12;

    /**
     * Validate a password against the policy.
     * Returns array of violation messages (empty = valid).
     */
    public static function validate(string $password): array
    {
        $errors = [];

        if (strlen($password) < self::MIN_LENGTH) {
            $errors[] = 'Password must be at least ' . self::MIN_LENGTH . ' characters';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter';
        }
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one digit';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'Password must contain at least one symbol';
        }

        return $errors;
    }

    /**
     * Check if a password meets policy (convenience boolean).
     */
    public static function isValid(string $password): bool
    {
        return empty(self::validate($password));
    }

    /**
     * Hash a password using bcrypt.
     */
    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * Verify a password against a bcrypt hash.
     */
    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}
