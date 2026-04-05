<?php
declare(strict_types=1);

namespace app\service;

/**
 * Static holder for request metadata (IP address + User-Agent) used by
 * audit logging callsites. Set by controllers (or the AuthCheck
 * middleware) at the start of each request so that services downstream
 * can read the current client IP and device fingerprint without
 * plumbing Request objects through every function signature.
 *
 * Issue #12 remediation: prior to this helper, many audit callsites
 * passed empty strings for ip/device_fingerprint, leaving a forensic
 * traceability gap. This helper gives every service-layer audit call
 * a single source of truth for request metadata.
 *
 * Single-request lifetime: PHP-CLI-server is one request per worker,
 * so static state is safe. In long-lived worker setups a caller should
 * explicitly clear() between requests.
 */
class RequestContext
{
    private static string $ip = '';
    private static string $device = '';

    public static function set(string $ip, string $device): void
    {
        self::$ip = $ip;
        self::$device = $device;
    }

    public static function ip(): string
    {
        return self::$ip;
    }

    public static function device(): string
    {
        return self::$device;
    }

    public static function clear(): void
    {
        self::$ip = '';
        self::$device = '';
    }
}
