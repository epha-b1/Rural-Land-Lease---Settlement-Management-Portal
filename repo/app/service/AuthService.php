<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Core authentication service.
 * Handles registration, login with lockout/backoff, logout.
 */
class AuthService
{
    private const LOCKOUT_THRESHOLD = 5;       // failures to trigger lockout
    private const LOCKOUT_WINDOW_SECONDS = 900; // 15 minutes rolling window
    private const MAX_BACKOFF_SECONDS = 3600;   // cap at 1 hour

    private const VALID_ROLES = ['farmer', 'enterprise', 'collective', 'system_admin'];
    private const VALID_SCOPES = ['village', 'township', 'county'];

    /**
     * Register a new user.
     * @return array User data on success
     * @throws \Exception on validation failure
     */
    public static function register(array $data, string $traceId = ''): array
    {
        $username = trim($data['username'] ?? '');
        $password = $data['password'] ?? '';
        $role = $data['role'] ?? 'farmer';
        $geoScopeLevel = $data['geo_scope_level'] ?? 'village';
        $geoScopeId = (int)($data['geo_scope_id'] ?? 0);

        // Validate required fields
        if (empty($username)) {
            throw new \think\exception\HttpException(400, 'Username is required');
        }
        if (strlen($username) < 3 || strlen($username) > 100) {
            throw new \think\exception\HttpException(400, 'Username must be 3-100 characters');
        }

        // Validate password policy
        $pwErrors = PasswordService::validate($password);
        if (!empty($pwErrors)) {
            throw new \think\exception\HttpException(400, implode('; ', $pwErrors));
        }

        // Validate role
        if (!in_array($role, self::VALID_ROLES, true)) {
            throw new \think\exception\HttpException(400, 'Invalid role: ' . $role);
        }

        // Validate scope
        if (!in_array($geoScopeLevel, self::VALID_SCOPES, true)) {
            throw new \think\exception\HttpException(400, 'Invalid geo_scope_level');
        }

        // Validate geo_scope_id exists
        $geoArea = Db::table('geo_areas')->where('id', $geoScopeId)->find();
        if (!$geoArea) {
            throw new \think\exception\HttpException(400, 'Invalid geo_scope_id');
        }

        // Check username uniqueness
        $existing = Db::table('users')->where('username', $username)->find();
        if ($existing) {
            throw new \think\exception\HttpException(409, 'Username already exists');
        }

        // Create user
        $userId = Db::table('users')->insertGetId([
            'username'        => $username,
            'password_hash'   => PasswordService::hash($password),
            'role'            => $role,
            'geo_scope_level' => $geoScopeLevel,
            'geo_scope_id'    => $geoScopeId,
            'status'          => 'active',
            'mfa_enabled'     => 0,
        ]);

        LogService::info('user_registered', [
            'user_id'  => $userId,
            'username' => $username,
            'role'     => $role,
        ], $traceId);

        return [
            'user_id'  => $userId,
            'username' => $username,
            'role'     => $role,
            'scope'    => [
                'level' => $geoScopeLevel,
                'id'    => $geoScopeId,
            ],
        ];
    }

    /**
     * Attempt login. Handles lockout, backoff, MFA challenge.
     * @return array Login result with token or mfa_required flag
     * @throws \think\exception\HttpException on failure
     */
    public static function login(string $username, string $password, ?string $totpCode = null, string $ip = '', string $traceId = ''): array
    {
        $user = Db::table('users')->where('username', $username)->find();

        if (!$user) {
            throw new \think\exception\HttpException(401, 'Invalid credentials');
        }

        $userId = (int)$user['id'];

        // Check lockout status
        $lockoutInfo = self::checkLockout($userId);
        if ($lockoutInfo['locked']) {
            LogService::warning('login_locked_out', [
                'user_id'  => $userId,
                'failures' => $lockoutInfo['recent_failures'],
                'wait'     => $lockoutInfo['wait_seconds'],
            ], $traceId);

            throw new \think\exception\HttpException(423,
                'Account locked due to too many failed attempts. Try again in ' . $lockoutInfo['wait_seconds'] . ' seconds'
            );
        }

        // Check account status
        if ($user['status'] !== 'active') {
            throw new \think\exception\HttpException(423, 'Account is ' . $user['status']);
        }

        // Verify password
        if (!PasswordService::verify($password, $user['password_hash'])) {
            self::recordFailure($userId, $ip);

            LogService::warning('login_failed', [
                'user_id'  => $userId,
                'username' => $username,
                'ip'       => $ip,
            ], $traceId);

            throw new \think\exception\HttpException(401, 'Invalid credentials');
        }

        // Password correct — check MFA requirement
        if ($user['mfa_enabled'] && !empty($user['mfa_secret'])) {
            if (empty($totpCode)) {
                // MFA required but not provided — return challenge
                return [
                    'mfa_required' => true,
                    'user'         => self::safeUserData($user),
                ];
            }

            // Verify TOTP
            // Decode base32-stored secret for TOTP verification
            $secret = MfaService::decodeBase32($user['mfa_secret']);
            if (!MfaService::verifyCode($secret, $totpCode)) {
                self::recordFailure($userId, $ip);

                LogService::warning('mfa_verify_failed', [
                    'user_id' => $userId,
                ], $traceId);

                throw new \think\exception\HttpException(401, 'Invalid MFA code');
            }
        }

        // Clear failures on successful login
        self::clearFailures($userId);

        // Generate token
        $token = TokenService::create($userId);

        LogService::info('login_success', [
            'user_id'  => $userId,
            'username' => $username,
            'ip'       => $ip,
        ], $traceId);

        return [
            'access_token' => $token,
            'user'         => self::safeUserData($user),
            'mfa_required' => false,
        ];
    }

    /**
     * Logout: revoke token.
     */
    public static function logout(string $token, string $traceId = ''): void
    {
        $userId = TokenService::validate($token);
        TokenService::revoke($token);

        if ($userId) {
            LogService::info('logout', ['user_id' => $userId], $traceId);
        }
    }

    /**
     * Get current user from token.
     */
    public static function getCurrentUser(string $token): ?array
    {
        $userId = TokenService::validate($token);
        if (!$userId) {
            return null;
        }

        $user = Db::table('users')->where('id', $userId)->find();
        if (!$user || $user['status'] !== 'active') {
            return null;
        }

        return self::safeUserData($user);
    }

    /**
     * Check rolling-window lockout status.
     * Returns lockout state and backoff wait time.
     */
    public static function checkLockout(int $userId): array
    {
        $windowStart = date('Y-m-d H:i:s', time() - self::LOCKOUT_WINDOW_SECONDS);

        $recentFailures = (int)Db::table('auth_failures')
            ->where('user_id', $userId)
            ->where('failed_at', '>=', $windowStart)
            ->count();

        if ($recentFailures < self::LOCKOUT_THRESHOLD) {
            return [
                'locked'          => false,
                'recent_failures' => $recentFailures,
                'wait_seconds'    => 0,
            ];
        }

        // Account is LOCKED: 5+ failures in the rolling 15-minute window.
        // Calculate exponential backoff wait time for user feedback.
        // Base: 60s after 5 failures, doubling per additional failure.
        $overThreshold = $recentFailures - self::LOCKOUT_THRESHOLD;
        $exponent = min($overThreshold, 10);
        $waitSeconds = min(60 * (int)pow(2, $exponent), self::MAX_BACKOFF_SECONDS);

        // Calculate time remaining based on when oldest failure leaves the window
        $oldestFailure = Db::table('auth_failures')
            ->where('user_id', $userId)
            ->where('failed_at', '>=', $windowStart)
            ->order('failed_at', 'asc')
            ->value('failed_at');

        if ($oldestFailure) {
            // Time until oldest failure drops out of window
            $windowRemaining = self::LOCKOUT_WINDOW_SECONDS - (time() - strtotime($oldestFailure));
            $waitSeconds = max($windowRemaining, $waitSeconds);
        }

        return [
            'locked'          => true,
            'recent_failures' => $recentFailures,
            'wait_seconds'    => max($waitSeconds, 1),
        ];
    }

    /**
     * Record a failed login attempt.
     */
    public static function recordFailure(int $userId, string $ip = ''): void
    {
        Db::table('auth_failures')->insert([
            'user_id'   => $userId,
            'ip'        => $ip ?: null,
            'failed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Clear failure records for a user (on successful login).
     */
    public static function clearFailures(int $userId): void
    {
        Db::table('auth_failures')->where('user_id', $userId)->delete();
    }

    /**
     * Return user data safe for API responses (no secrets).
     */
    public static function safeUserData(array $user): array
    {
        return [
            'id'                  => (int)$user['id'],
            'username'            => $user['username'],
            'role'                => $user['role'],
            'geo_scope_level'     => $user['geo_scope_level'],
            'geo_scope_id'        => (int)$user['geo_scope_id'],
            'status'              => $user['status'],
            'mfa_enabled'         => (bool)$user['mfa_enabled'],
            'verification_status' => 'pending', // Verification workflow in Slice 3
        ];
    }

    /**
     * Count recent failures in the rolling window (exposed for testing).
     */
    public static function getRecentFailureCount(int $userId): int
    {
        $windowStart = date('Y-m-d H:i:s', time() - self::LOCKOUT_WINDOW_SECONDS);

        return (int)Db::table('auth_failures')
            ->where('user_id', $userId)
            ->where('failed_at', '>=', $windowStart)
            ->count();
    }
}
