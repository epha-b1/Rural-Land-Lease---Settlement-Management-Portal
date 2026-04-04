<?php
declare(strict_types=1);

namespace app\service;

/**
 * Static holder for the current authenticated user context.
 * Set by AuthCheck middleware, read by controllers.
 * Safe for single-process PHP built-in server.
 */
class AuthContext
{
    private static ?array $user = null;
    private static string $token = '';

    public static function set(array $user, string $token): void
    {
        self::$user = $user;
        self::$token = $token;
    }

    public static function user(): ?array
    {
        return self::$user;
    }

    public static function token(): string
    {
        return self::$token;
    }

    public static function clear(): void
    {
        self::$user = null;
        self::$token = '';
    }
}
