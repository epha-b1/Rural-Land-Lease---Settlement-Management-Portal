<?php
declare(strict_types=1);

namespace app\service;

/**
 * Structured logging service.
 * All log entries include trace_id and timestamp for traceability.
 * Sensitive fields are never included in log output.
 */
class LogService
{
    private static array $sensitiveKeys = [
        'password', 'password_hash', 'token', 'secret',
        'mfa_secret', 'id_number', 'license_number',
        'bank_reference', 'encryption_key',
    ];

    public static function info(string $event, array $context = [], string $traceId = ''): void
    {
        self::write('info', $event, $context, $traceId);
    }

    public static function error(string $event, array $context = [], string $traceId = ''): void
    {
        self::write('error', $event, $context, $traceId);
    }

    public static function warning(string $event, array $context = [], string $traceId = ''): void
    {
        self::write('warning', $event, $context, $traceId);
    }

    private static function write(string $level, string $event, array $context, string $traceId): void
    {
        $entry = [
            'timestamp' => date('c'),
            'level'     => $level,
            'event'     => $event,
            'trace_id'  => $traceId,
            'context'   => self::maskSensitive($context),
        ];

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Write to structured log file
        $logDir = runtime_path() . 'log/';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . date('Ymd') . '.log';
        @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);

        // Also write to stderr for Docker log collection
        file_put_contents('php://stderr', $line . PHP_EOL, FILE_APPEND);
    }

    private static function maskSensitive(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::maskSensitive($value);
            } elseif (in_array(strtolower($key), self::$sensitiveKeys, true)) {
                $data[$key] = '[REDACTED]';
            }
        }
        return $data;
    }
}
