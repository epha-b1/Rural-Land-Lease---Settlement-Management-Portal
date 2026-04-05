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

class Auth
{
    /**
     * POST /auth/register
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

        $result = AuthService::register($data, $traceId);

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

        // Store base32-encoded secret (not yet enabled — user must verify first)
        // Note: In Slice 7 this will be AES-256 encrypted at rest
        Db::table('users')
            ->where('id', $user['id'])
            ->update(['mfa_secret' => $base32Secret]);

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

        // Verify the code
        $rawSecret = MfaService::decodeBase32($dbUser['mfa_secret']);
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
}
