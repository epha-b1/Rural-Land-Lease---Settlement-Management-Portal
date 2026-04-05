<?php
declare(strict_types=1);

namespace app\service;

/**
 * AES-256-CBC encryption/decryption service.
 *
 * Key management (Issue I-10 remediation):
 *  - Preferred: ENCRYPTION_KEY_FILE points to a file path (secret mount /
 *    docker secret / K8s secret / host-side file outside web root) whose
 *    contents are a 64-char hex string. This is read at process startup.
 *  - Fallback: ENCRYPTION_KEY env var is used if ENCRYPTION_KEY_FILE is
 *    unset or unreadable. This is acceptable for local dev.
 *  - Guard: if APP_ENV is "production" (or anything except blank / "dev" /
 *    "development" / "test"), the service REFUSES to start with the
 *    well-known development default key (`INSECURE_DEV_KEY`), or with any
 *    key shorter than 64 hex chars. The guard is applied every time a key
 *    is needed, not just at boot, so no encryption operation can silently
 *    run against the insecure default in production.
 */
class EncryptionService
{
    private const CIPHER = 'aes-256-cbc';

    /**
     * Well-known insecure development key. Shipped in docker-compose.yml
     * solely so new contributors can boot the stack with zero config. The
     * guard below REJECTS this value in production mode.
     */
    public const DEV_KEY_MARKER = '00000000000000000000000000000000000000000000000000000000DEADBEEF';

    /** In-process cache so we don't re-read the key file on every call. */
    private static ?string $cachedKeyBytes = null;

    private static function getKey(): string
    {
        if (self::$cachedKeyBytes !== null) {
            return self::$cachedKeyBytes;
        }

        $keyHex = '';

        // 1. Prefer ENCRYPTION_KEY_FILE (secret mount path).
        $keyFile = getenv('ENCRYPTION_KEY_FILE') ?: '';
        if ($keyFile !== '' && is_readable($keyFile)) {
            $keyHex = trim((string)@file_get_contents($keyFile));
        }

        // 2. Fallback to ENCRYPTION_KEY env var.
        if ($keyHex === '') {
            $keyHex = trim((string)(getenv('ENCRYPTION_KEY') ?: ''));
        }

        if ($keyHex === '') {
            throw new \RuntimeException(
                'ENCRYPTION_KEY not set. Provide either ENCRYPTION_KEY_FILE '
                . '(path to a 64-char hex secret) or the ENCRYPTION_KEY '
                . 'environment variable.'
            );
        }
        if (strlen($keyHex) < 64 || !ctype_xdigit($keyHex)) {
            throw new \RuntimeException(
                'ENCRYPTION_KEY must be a 64-character hex string (256-bit AES key).'
            );
        }

        // 3. Production guard — reject the well-known dev marker.
        $env = strtolower((string)(getenv('APP_ENV') ?: 'development'));
        $isProduction = !in_array($env, ['dev', 'development', 'test', 'testing', 'local'], true);
        if ($isProduction && strtolower($keyHex) === strtolower(self::DEV_KEY_MARKER)) {
            throw new \RuntimeException(
                'Refusing to start with the well-known DEV encryption key in production mode. '
                . 'Set ENCRYPTION_KEY_FILE to a secret-mounted path or a secure ENCRYPTION_KEY env var, '
                . 'and set APP_ENV=production (or unset) only after rotating the key.'
            );
        }

        self::$cachedKeyBytes = hex2bin($keyHex);
        return self::$cachedKeyBytes;
    }

    public static function encrypt(string $plaintext): string
    {
        $key = self::getKey();
        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $ciphertext);
    }

    public static function decrypt(string $encrypted): string
    {
        $key = self::getKey();
        $data = base64_decode($encrypted);
        $ivLen = openssl_cipher_iv_length(self::CIPHER);
        $iv = substr($data, 0, $ivLen);
        $ciphertext = substr($data, $ivLen);
        return openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
    }

    /**
     * Mask a string for display: show only last N chars.
     */
    public static function mask(string $value, int $showLast = 4): string
    {
        if (strlen($value) <= $showLast) return str_repeat('*', strlen($value));
        return str_repeat('*', strlen($value) - $showLast) . substr($value, -$showLast);
    }

    /**
     * Test-only helper that clears the in-process key cache so unit tests
     * can exercise different env configurations without spawning new PHP
     * processes. NEVER called from production code paths.
     */
    public static function resetKeyCacheForTests(): void
    {
        self::$cachedKeyBytes = null;
    }
}
