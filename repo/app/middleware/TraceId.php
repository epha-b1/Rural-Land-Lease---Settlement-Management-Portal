<?php
declare(strict_types=1);

namespace app\middleware;

use think\Request;
use think\Response;
use Ramsey\Uuid\Uuid;

/**
 * Generates a unique trace ID for every request and attaches it
 * to the response as X-Trace-Id header. If the incoming request
 * already carries an X-Trace-Id, it is reused (for propagation).
 *
 * Note: We store the trace ID via a static accessor rather than
 * $request->withHeader(), which replaces all request headers in
 * ThinkPHP 6 and would wipe Authorization and other headers.
 */
class TraceId
{
    private static string $currentTraceId = '';

    public function handle(Request $request, \Closure $next): Response
    {
        // Reuse incoming trace ID or generate a new one
        $traceId = $request->header('x-trace-id');
        if (empty($traceId)) {
            $traceId = Uuid::uuid4()->toString();
        }

        // Store trace ID in static accessor (safe for single-process PHP server)
        self::$currentTraceId = $traceId;

        /** @var Response $response */
        $response = $next($request);

        // Attach trace ID to response
        $response->header([
            'X-Trace-Id' => $traceId,
        ]);

        return $response;
    }

    /**
     * Get the current request's trace ID.
     */
    public static function getId(): string
    {
        return self::$currentTraceId;
    }
}
