<?php
declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\service\EncryptionService;

/**
 * Remediation: Issue I-10 — production guard against the DEV encryption key.
 *
 * The default compose file ships a well-known dev marker key so contributors
 * can boot the stack without any secret plumbing. In production mode that
 * exact key MUST be refused by EncryptionService::getKey().
 *
 * These tests exercise the guard by flipping APP_ENV via putenv() and then
 * resetting the EncryptionService key cache between assertions. The guard
 * fires every time a key is needed because the cache is cleared up-front.
 */
class EncryptionKeyGuardTest extends TestCase
{
    private string $originalEnv;
    private string $originalKey;
    private string $originalKeyFile;

    protected function setUp(): void
    {
        // Snapshot env so we can restore it after each test.
        $this->originalEnv = getenv('APP_ENV') ?: '';
        $this->originalKey = getenv('ENCRYPTION_KEY') ?: '';
        $this->originalKeyFile = getenv('ENCRYPTION_KEY_FILE') ?: '';
    }

    protected function tearDown(): void
    {
        if ($this->originalEnv !== '') putenv('APP_ENV=' . $this->originalEnv);
        else putenv('APP_ENV');
        if ($this->originalKey !== '') putenv('ENCRYPTION_KEY=' . $this->originalKey);
        else putenv('ENCRYPTION_KEY');
        if ($this->originalKeyFile !== '') putenv('ENCRYPTION_KEY_FILE=' . $this->originalKeyFile);
        else putenv('ENCRYPTION_KEY_FILE');
        EncryptionService::resetKeyCacheForTests();
    }

    public function testDevMarkerKeyAllowedInDevelopmentMode(): void
    {
        putenv('APP_ENV=development');
        putenv('ENCRYPTION_KEY=' . EncryptionService::DEV_KEY_MARKER);
        putenv('ENCRYPTION_KEY_FILE');
        EncryptionService::resetKeyCacheForTests();

        // Should NOT throw — development mode accepts the marker.
        $cipher = EncryptionService::encrypt('hello');
        $this->assertNotEmpty($cipher);
        $this->assertEquals('hello', EncryptionService::decrypt($cipher));
    }

    public function testDevMarkerKeyRejectedInProductionMode(): void
    {
        putenv('APP_ENV=production');
        putenv('ENCRYPTION_KEY=' . EncryptionService::DEV_KEY_MARKER);
        putenv('ENCRYPTION_KEY_FILE');
        EncryptionService::resetKeyCacheForTests();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DEV encryption key|production/i');
        EncryptionService::encrypt('should fail');
    }

    public function testNonDevKeyAllowedInProduction(): void
    {
        putenv('APP_ENV=production');
        // A plausible real hex key (NOT the dev marker)
        putenv('ENCRYPTION_KEY=1111111111111111111111111111111111111111111111111111111111111111');
        putenv('ENCRYPTION_KEY_FILE');
        EncryptionService::resetKeyCacheForTests();

        $cipher = EncryptionService::encrypt('payload');
        $this->assertNotEmpty($cipher);
        $this->assertEquals('payload', EncryptionService::decrypt($cipher));
    }

    public function testKeyFilePreferredOverEnvVar(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'enc_key_');
        try {
            file_put_contents($tmp, '2222222222222222222222222222222222222222222222222222222222222222');
            putenv('APP_ENV=production');
            putenv('ENCRYPTION_KEY_FILE=' . $tmp);
            // Intentionally set an env var that WOULD fail (short) — the
            // key file must take precedence and make encryption succeed.
            putenv('ENCRYPTION_KEY=deadbeef');
            EncryptionService::resetKeyCacheForTests();

            $cipher = EncryptionService::encrypt('from-file');
            $this->assertEquals('from-file', EncryptionService::decrypt($cipher));
        } finally {
            @unlink($tmp);
        }
    }

    public function testShortKeyRejected(): void
    {
        putenv('APP_ENV=development');
        putenv('ENCRYPTION_KEY=tooshort');
        putenv('ENCRYPTION_KEY_FILE');
        EncryptionService::resetKeyCacheForTests();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/64-character hex/');
        EncryptionService::encrypt('x');
    }

    public function testNonHexKeyRejected(): void
    {
        putenv('APP_ENV=development');
        putenv('ENCRYPTION_KEY=ZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZ');
        putenv('ENCRYPTION_KEY_FILE');
        EncryptionService::resetKeyCacheForTests();

        $this->expectException(\RuntimeException::class);
        EncryptionService::encrypt('x');
    }
}
