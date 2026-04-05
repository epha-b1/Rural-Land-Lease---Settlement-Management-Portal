<?php
declare(strict_types=1);

namespace app\middleware;

use think\Request;
use think\Response;
use app\service\TokenService;
use think\facade\Db;

/**
 * Authentication middleware for protected routes.
 * Validates Bearer token and attaches user data to the request.
 * Returns 401 if no/invalid token, 403 if role check fails.
 *
 * Usage in routes:
 *   ->middleware('authCheck')            // any authenticated user
 *   ->middleware('authCheck:system_admin') // require specific role
 */
class AuthCheck
{
    public function handle(Request $request, \Closure $next, ?string $requiredRole = null): Response
    {
        $traceId = \app\middleware\TraceId::getId();

        // Extract Bearer token from $_SERVER directly
        // (ThinkPHP's $request->header() can lose headers after middleware processing)
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token = '';
        if (str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
        }

        if (empty($token)) {
            return $this->errorResponse(401, 'UNAUTHORIZED', 'Authentication required', $traceId);
        }

        // Validate token
        $userId = TokenService::validate($token);
        if (!$userId) {
            return $this->errorResponse(401, 'UNAUTHORIZED', 'Invalid or expired token', $traceId);
        }

        // Load user
        $user = Db::table('users')->where('id', $userId)->find();
        if (!$user || $user['status'] !== 'active') {
            return $this->errorResponse(401, 'UNAUTHORIZED', 'Account not active', $traceId);
        }

        // Role check
        if ($requiredRole !== null && $user['role'] !== $requiredRole) {
            // Allow system_admin access to admin-level routes too
            $isAdmin = $user['role'] === 'system_admin';
            $adminRoutes = ['admin', 'system_admin'];
            if (!($isAdmin && in_array($requiredRole, $adminRoutes, true))) {
                return $this->errorResponse(403, 'FORBIDDEN', 'Insufficient permissions', $traceId);
            }
        }

        // Store auth context for controllers to access (avoid PHP 8.2 dynamic property issues)
        \app\service\AuthContext::set([
            'id'              => (int)$user['id'],
            'username'        => $user['username'],
            'role'            => $user['role'],
            'geo_scope_level' => $user['geo_scope_level'],
            'geo_scope_id'    => (int)$user['geo_scope_id'],
            'status'          => $user['status'],
            'mfa_enabled'     => (bool)$user['mfa_enabled'],
        ], $token);

        // Issue #12 remediation: capture request metadata (IP + UA) so that
        // service-layer audit callsites can log complete forensic context
        // without having to receive a Request object.
        $ip = $request->ip() ?: ($_SERVER['REMOTE_ADDR'] ?? '');
        $device = $_SERVER['HTTP_USER_AGENT'] ?? '';
        \app\service\RequestContext::set((string)$ip, (string)$device);

        return $next($request);
    }

    private function errorResponse(int $httpStatus, string $code, string $message, string $traceId): Response
    {
        return json([
            'status'   => 'error',
            'code'     => $code,
            'message'  => $message,
            'trace_id' => $traceId,
        ], $httpStatus)->header(['X-Trace-Id' => $traceId]);
    }
}
