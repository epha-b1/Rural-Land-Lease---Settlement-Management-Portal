<?php
declare(strict_types=1);

namespace app\middleware;

use think\Request;
use think\Response;

/**
 * Ensures all API responses have correct JSON content type
 * and adds CORS headers for local network access.
 */
class JsonResponse
{
    public function handle(Request $request, \Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // Set JSON content type for API responses (skip static files and file downloads)
        $path = $request->pathinfo();
        $skipJson = str_starts_with($path, 'static/')
            || str_contains($path, '.')
            || str_starts_with($path, 'exports/');
        if (!$skipJson) {
            $response->contentType('application/json');
        }

        // Basic CORS for local network
        $response->header([
            'Access-Control-Allow-Origin'  => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Trace-Id, Idempotency-Key',
        ]);

        return $response;
    }
}
