<?php
declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\service\LogService;

/**
 * Regression test for Issue #8: prove that the structured log output never
 * contains sensitive values (passwords, tokens, MFA secrets, ID numbers,
 * license numbers, encryption keys). Addresses the "Log sanitization is
 * code-level only; no dedicated automated leakage test" gap.
 *
 * Strategy:
 *  1. Emit a log entry with a payload that deliberately contains every
 *     sensitive key that LogService::$sensitiveKeys masks.
 *  2. Re-read the newly written log file from runtime/log/.
 *  3. Assert the raw secret values are absent and that [REDACTED]
 *     appears in their place.
 */
class LogLeakageTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        // LogService writes to runtime/log/YYYYMMDD.log inside the container
        $this->logFile = '/app/runtime/log/' . date('Ymd') . '.log';
    }

    /**
     * LogService::info must mask every sensitive key and emit [REDACTED]
     * without leaking the raw value into the on-disk log line.
     */
    public function testSensitiveKeysAreRedactedInEmittedLog(): void
    {
        // Use a unique marker so we can locate our specific log entry
        $marker = 'LOGLEAK_TEST_' . bin2hex(random_bytes(6));

        // Each of these keys is expected to be redacted by LogService
        $sensitive = [
            'password'        => 'myRealP@ssword1234',
            'password_hash'   => '$2y$12$notARealHashButShouldBeRedacted',
            'token'           => 'abc123bearertoken456secret',
            'secret'          => 'totally-secret-value-xyz',
            'mfa_secret'      => 'JBSWY3DPEHPK3PXP',
            'id_number'       => 'SSN-987-65-4321',
            'license_number'  => 'LIC-999888777',
            'bank_reference'  => 'IBAN-SECRET-ACC-12345',
            'encryption_key'  => '00112233445566778899aabbccddeeff',
        ];

        LogService::info('log_leakage_probe', array_merge(
            ['marker' => $marker],
            $sensitive
        ), 'test-trace-leakage');

        // Give the filesystem a moment if buffered
        clearstatcache(true, $this->logFile);
        $this->assertFileExists($this->logFile, 'Log file must exist after LogService::info');

        $content = file_get_contents($this->logFile);
        $this->assertNotFalse($content, 'Log file must be readable');

        // Extract only lines containing our marker to keep assertions focused
        $ourLines = [];
        foreach (explode("\n", $content) as $line) {
            if (str_contains($line, $marker)) {
                $ourLines[] = $line;
            }
        }
        $this->assertNotEmpty($ourLines, "Log line containing marker {$marker} must be found");
        $probeLine = implode("\n", $ourLines);

        // 1) Raw secret values must NOT appear in the log line
        foreach ($sensitive as $key => $rawValue) {
            $this->assertStringNotContainsString(
                $rawValue,
                $probeLine,
                "Raw value of sensitive key '{$key}' leaked into log line: {$rawValue}"
            );
        }

        // 2) [REDACTED] placeholder must appear for each sensitive key
        $redactionCount = substr_count($probeLine, '[REDACTED]');
        $this->assertGreaterThanOrEqual(
            count($sensitive),
            $redactionCount,
            'Expected at least one [REDACTED] per sensitive key'
        );

        // 3) Non-sensitive data (the marker) must still be visible
        $this->assertStringContainsString($marker, $probeLine, 'Non-sensitive marker must still appear');
    }

    /**
     * Nested arrays must also be masked — LogService walks recursively.
     */
    public function testNestedSensitiveKeysAreRedacted(): void
    {
        $marker = 'LOGLEAK_NESTED_' . bin2hex(random_bytes(6));
        LogService::info('log_leakage_nested_probe', [
            'marker' => $marker,
            'outer'  => [
                'harmless' => 'public-info',
                'deep'     => [
                    'password' => 'DEEPLY_NESTED_SECRET_PW',
                    'token'    => 'DEEPLY_NESTED_SECRET_TK',
                ],
            ],
        ], 'test-trace-nested');

        clearstatcache(true, $this->logFile);
        $content = file_get_contents($this->logFile);
        $matchingLines = [];
        foreach (explode("\n", $content) as $line) {
            if (str_contains($line, $marker)) $matchingLines[] = $line;
        }
        $this->assertNotEmpty($matchingLines);
        $probeLine = implode("\n", $matchingLines);

        $this->assertStringNotContainsString('DEEPLY_NESTED_SECRET_PW', $probeLine);
        $this->assertStringNotContainsString('DEEPLY_NESTED_SECRET_TK', $probeLine);
        $this->assertStringContainsString('[REDACTED]', $probeLine);
        $this->assertStringContainsString('public-info', $probeLine, 'Harmless nested field stays visible');
    }
}
