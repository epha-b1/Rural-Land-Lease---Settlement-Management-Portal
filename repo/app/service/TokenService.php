<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Bearer token management for stateless REST auth.
 * Tokens are random 64-char hex strings stored in user_tokens table.
 */
class TokenService
{
    // Token lifetime: 24 hours
    private const TOKEN_LIFETIME_SECONDS = 86400;

    /**
     * Generate and store a new token for a user.
     * Removes any existing tokens for the user first.
     */
    public static function create(int $userId): string
    {
        $token = bin2hex(random_bytes(32)); // 64 hex chars
        $expiresAt = date('Y-m-d H:i:s', time() + self::TOKEN_LIFETIME_SECONDS);

        // Remove old tokens for this user
        Db::table('user_tokens')->where('user_id', $userId)->delete();

        Db::table('user_tokens')->insert([
            'user_id'    => $userId,
            'token'      => $token,
            'expires_at' => $expiresAt,
        ]);

        return $token;
    }

    /**
     * Validate a token and return the user_id if valid, null if not.
     */
    public static function validate(string $token): ?int
    {
        $row = Db::table('user_tokens')
            ->where('token', $token)
            ->where('expires_at', '>', date('Y-m-d H:i:s'))
            ->find();

        if (!$row) {
            return null;
        }

        return (int)$row['user_id'];
    }

    /**
     * Revoke a token (logout).
     */
    public static function revoke(string $token): void
    {
        Db::table('user_tokens')->where('token', $token)->delete();
    }

    /**
     * Revoke all tokens for a user.
     */
    public static function revokeAll(int $userId): void
    {
        Db::table('user_tokens')->where('user_id', $userId)->delete();
    }

    /**
     * Clean up expired tokens.
     */
    public static function cleanup(): int
    {
        return Db::table('user_tokens')
            ->where('expires_at', '<', date('Y-m-d H:i:s'))
            ->delete();
    }
}
