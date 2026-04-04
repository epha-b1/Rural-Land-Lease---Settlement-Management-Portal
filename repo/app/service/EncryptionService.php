<?php
declare(strict_types=1);

namespace app\service;

/**
 * AES-256-CBC encryption/decryption service.
 * Key loaded from environment variable (stored outside web root in production).
 */
class EncryptionService
{
    private const CIPHER = 'aes-256-cbc';

    private static function getKey(): string
    {
        $key = getenv('ENCRYPTION_KEY') ?: '';
        if (empty($key) || strlen($key) < 64) {
            throw new \RuntimeException('ENCRYPTION_KEY not set or too short');
        }
        return hex2bin($key);
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
     * Mask a string for display: show only last 4 chars.
     */
    public static function mask(string $value, int $showLast = 4): string
    {
        if (strlen($value) <= $showLast) return str_repeat('*', strlen($value));
        return str_repeat('*', strlen($value) - $showLast) . substr($value, -$showLast);
    }
}
