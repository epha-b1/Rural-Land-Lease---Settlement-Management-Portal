<?php
declare(strict_types=1);

namespace app\service;

/**
 * TOTP-based MFA service for admin users.
 * Implements RFC 6238 (TOTP) with HMAC-SHA1.
 * No external dependencies — uses PHP's built-in crypto.
 */
class MfaService
{
    private const PERIOD = 30;      // Time step in seconds
    private const DIGITS = 6;       // OTP length
    private const WINDOW = 1;       // Accept codes from +/- 1 time step
    private const SECRET_LENGTH = 20; // 160-bit secret

    // Base32 alphabet per RFC 4648
    private const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generate a new TOTP secret (raw bytes).
     */
    public static function generateSecret(): string
    {
        return random_bytes(self::SECRET_LENGTH);
    }

    /**
     * Encode raw secret to Base32 for QR codes and authenticator apps.
     */
    public static function encodeBase32(string $data): string
    {
        $binary = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $result = '';
        $chunks = str_split($binary, 5);
        foreach ($chunks as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $result .= self::BASE32_CHARS[bindec($chunk)];
        }

        return $result;
    }

    /**
     * Decode Base32 back to raw bytes.
     */
    public static function decodeBase32(string $base32): string
    {
        $base32 = strtoupper(rtrim($base32, '='));
        $binary = '';
        foreach (str_split($base32) as $char) {
            $pos = strpos(self::BASE32_CHARS, $char);
            if ($pos === false) continue;
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $result = '';
        $bytes = str_split($binary, 8);
        foreach ($bytes as $byte) {
            if (strlen($byte) < 8) break;
            $result .= chr(bindec($byte));
        }

        return $result;
    }

    /**
     * Generate a TOTP code for the given secret and time.
     */
    public static function generateCode(string $secret, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $counter = intdiv($timestamp, self::PERIOD);

        // Pack counter as 8-byte big-endian
        $counterBytes = pack('N*', 0, $counter);

        // HMAC-SHA1
        $hash = hash_hmac('sha1', $counterBytes, $secret, true);

        // Dynamic truncation
        $offset = ord($hash[19]) & 0x0F;
        $code = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** self::DIGITS);

        return str_pad((string)$code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a TOTP code, allowing for clock skew within the window.
     */
    public static function verifyCode(string $secret, string $code, ?int $timestamp = null): bool
    {
        $timestamp = $timestamp ?? time();

        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            $checkTime = $timestamp + ($i * self::PERIOD);
            if (hash_equals(self::generateCode($secret, $checkTime), $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the otpauth:// URI for QR code generation by authenticator apps.
     */
    public static function buildOtpauthUri(string $base32Secret, string $username, string $issuer = 'RuralLeasePortal'): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($username),
            $base32Secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD
        );
    }
}
