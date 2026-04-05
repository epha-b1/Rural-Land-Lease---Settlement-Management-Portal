<?php
declare(strict_types=1);

namespace app\controller;

use think\Request;
use think\Response;
use think\facade\Db;
use app\service\AuthService;
use app\service\AuthContext;
use app\service\MfaService;
use app\service\LogService;
use app\service\CaptchaService;
use app\service\EncryptionService;

class Auth
{
    /**
     * POST /auth/register  (public)
     *
     * Issue I-09: refuses to self-assign privileged roles. The AuthService
     * throws 403 if the payload requests a blocked role (e.g. system_admin).
     */
    public function register(Request $request): Response
    {
        $traceId = \app\middleware\TraceId::getId();
        $data = $request->post();

        // CAPTCHA required (offline local challenge)
        CaptchaService::verifyAndConsume(
            $data['captcha_id'] ?? null,
            $data['captcha_answer'] ?? null
        );

        // Public path — third positional arg defaults to true which enables
        // the blocked-role policy inside AuthService::register().
        $result = AuthService::register($data, $traceId);

        return json($result, 201);
    }

    /**
     * POST /admin/users  (system_admin only)
     *
     * Issue I-09: the bootstrap/invite path for minting admin accounts.
     * Requires an authenticated system_admin caller (route middleware
     * `authCheck:system_admin`). CAPTCHA is NOT required because the caller
     * is already authenticated. Service-layer invariants reassert the role
     * check as defense-in-depth.
     */
    public function adminCreateUser(Request $request): Response
    {
        $traceId = \app\middleware\TraceId::getId();
        $caller = AuthContext::user();
        $data = $request->post();

        $result = AuthService::createByAdmin($data, $caller, $traceId);
        return json($result, 201);
    }

    /**
     * POST /auth/login
     */
    public function login(Request $request): Response
    {
        $traceId = \app\middleware\TraceId::getId();
        $username = $request->post('username', '');
        $password = $request->post('password', '');
        $totpCode = $request->post('totp_code');
        $captchaId = $request->post('captcha_id');
        $captchaAnswer = $request->post('captcha_answer');
        $ip = $request->ip();

        if (empty($username) || empty($password)) {
            throw new \think\exception\HttpException(400, 'Username and password are required');
        }

        // CAPTCHA required (offline local challenge)
        CaptchaService::verifyAndConsume($captchaId, $captchaAnswer);

        $result = AuthService::login($username, $password, $totpCode, $ip, $traceId);

        return json($result, 200);
    }

    /**
     * POST /auth/logout
     */
    public function logout(Request $request): Response
    {
        $traceId = \app\middleware\TraceId::getId();
        $token = AuthContext::token();

        AuthService::logout($token, $traceId);

        return json(['status' => 'ok'], 200);
    }

    /**
     * GET /auth/me
     */
    public function me(Request $request): Response
    {
        $user = AuthContext::user();
        return json($user, 200);
    }

    /**
     * POST /auth/mfa/enroll
     * Admin-only: generate a TOTP secret and return the otpauth URI.
     */
    public function mfaEnroll(Request $request): Response
    {
        $traceId = \app\middleware\TraceId::getId();
        $user = AuthContext::user();

        // Check if already enrolled
        if ($user['mfa_enabled']) {
            throw new \think\exception\HttpException(409, 'MFA is already enabled');
        }

        // Generate secret
        $secret = MfaService::generateSecret();
        $base32Secret = MfaService::encodeBase32($secret);
        $otpauthUri = MfaService::buildOtpauthUri($base32Secret, $user['username']);

        // Issue #7 remediation: encrypt the base32 secret at rest with AES-256.
        // The ciphertext is stored in `mfa_secret`; only EncryptionService can
        // recover the plaintext base32 string, which is then decoded for TOTP
        // verification.
        $encryptedSecret = EncryptionService::encrypt($base32Secret);
        Db::table('users')
            ->where('id', $user['id'])
            ->update(['mfa_secret' => $encryptedSecret]);

        LogService::info('mfa_enrolled', ['user_id' => $user['id']], $traceId);

        return json([
            'secret_otpauth_url' => $otpauthUri,
            'qr_payload'         => $base32Secret,
        ], 200);
    }

    /**
     * POST /auth/mfa/verify
     * Admin-only: verify a TOTP code to finalize MFA enrollment.
     */
    public function mfaVerify(Request $request): Response
    {
        $traceId = \app\middleware\TraceId::getId();
        $user = AuthContext::user();
        $totpCode = $request->post('totp_code', '');

        if (empty($totpCode)) {
            throw new \think\exception\HttpException(400, 'TOTP code is required');
        }

        // Load the stored secret
        $dbUser = Db::table('users')->where('id', $user['id'])->find();
        if (empty($dbUser['mfa_secret'])) {
            throw new \think\exception\HttpException(400, 'MFA not enrolled. Call /auth/mfa/enroll first');
        }

        // Verify the code.
        // Issue #7 remediation: decrypt ciphertext back to base32, then decode
        // base32 to the raw HMAC key for TOTP computation.
        $base32Secret = self::decryptMfaSecret($dbUser['mfa_secret']);
        $rawSecret = MfaService::decodeBase32($base32Secret);
        if (!MfaService::verifyCode($rawSecret, $totpCode)) {
            throw new \think\exception\HttpException(400, 'Invalid TOTP code');
        }

        // Enable MFA
        Db::table('users')
            ->where('id', $user['id'])
            ->update(['mfa_enabled' => 1]);

        LogService::info('mfa_verified', ['user_id' => $user['id']], $traceId);

        return json(['mfa_enabled' => true], 200);
    }

    /**
     * Decrypt the stored mfa_secret back to its base32 string.
     *
     * Since Issue #7 remediation, new enrollments store AES-256 ciphertext
     * produced by EncryptionService. Any record that still holds a raw
     * base32 string (no IV prefix, no ciphertext padding) from a prior
     * session is transparently detected by base32 character set and
     * returned as-is so TOTP verification keeps working while the user
     * is migrated on next enrollment.
     */
    private static function decryptMfaSecret(string $stored): string
    {
        // Base32 alphabet is A–Z, 2–7 and optional '='. Anything outside
        // that set is definitely ciphertext (base64 + binary).
        if (preg_match('/^[A-Z2-7=]+$/', $stored)) {
            return $stored;
        }
        return EncryptionService::decrypt($stored);
    }
}
