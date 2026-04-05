<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Local offline CAPTCHA service.
 * Generates a deterministic math challenge with a random challenge_id.
 * Answer is stored hashed; validated and single-use (consumed on first use).
 * No external dependency — fully offline as required by the prompt.
 */
class CaptchaService
{
    private const TTL_SECONDS = 300; // 5 minutes

    /**
     * Generate a new captcha challenge.
     * @return array ['challenge_id' => string, 'question' => string, 'expires_at' => string]
     */
    public static function generate(): array
    {
        $a = random_int(1, 9);
        $b = random_int(1, 9);
        $op = ['+', '-', '*'][random_int(0, 2)];
        $answer = match ($op) {
            '+' => $a + $b,
            '-' => $a - $b,
            '*' => $a * $b,
        };

        $challengeId = bin2hex(random_bytes(16));
        $expiresAt = date('Y-m-d H:i:s', time() + self::TTL_SECONDS);

        Db::table('captcha_challenges')->insert([
            'challenge_id' => $challengeId,
            'answer_hash'  => self::hashAnswer((string)$answer),
            'expires_at'   => $expiresAt,
            'consumed'     => 0,
        ]);

        return [
            'challenge_id' => $challengeId,
            'question'     => "{$a} {$op} {$b} = ?",
            'expires_at'   => $expiresAt,
        ];
    }

    /**
     * Verify and consume a captcha answer.
     * @throws \think\exception\HttpException 400 on missing/invalid/expired/consumed
     */
    public static function verifyAndConsume(?string $challengeId, ?string $answer): void
    {
        if (empty($challengeId) || $answer === null || $answer === '') {
            throw new \think\exception\HttpException(400, 'CAPTCHA is required');
        }

        $row = Db::table('captcha_challenges')
            ->where('challenge_id', $challengeId)
            ->find();

        if (!$row) {
            throw new \think\exception\HttpException(400, 'Invalid CAPTCHA');
        }

        if ((int)$row['consumed'] === 1) {
            throw new \think\exception\HttpException(400, 'CAPTCHA already used');
        }

        if (strtotime($row['expires_at']) < time()) {
            throw new \think\exception\HttpException(400, 'CAPTCHA expired');
        }

        if (!hash_equals($row['answer_hash'], self::hashAnswer(trim((string)$answer)))) {
            throw new \think\exception\HttpException(400, 'Invalid CAPTCHA answer');
        }

        // Consume (single-use)
        Db::table('captcha_challenges')
            ->where('id', $row['id'])
            ->update(['consumed' => 1]);
    }

    /**
     * Cleanup expired challenges.
     */
    public static function cleanup(): int
    {
        return (int)Db::table('captcha_challenges')
            ->where('expires_at', '<', date('Y-m-d H:i:s', time() - 3600))
            ->delete();
    }

    private static function hashAnswer(string $answer): string
    {
        // Salted hash scoped to server key so answers aren't guessable
        $key = getenv('ENCRYPTION_KEY') ?: 'dev-salt';
        return hash_hmac('sha256', $answer, $key);
    }
}
