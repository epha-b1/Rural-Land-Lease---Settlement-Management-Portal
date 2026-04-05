<?php
declare(strict_types=1);

namespace tests;

/**
 * Shared helper for tests that exercise /auth/login and /auth/register.
 * Fetches a real CAPTCHA challenge from /auth/captcha and solves the math
 * expression — we never bypass or weaken the production check.
 */
trait TestCaptchaHelper
{
    protected function fetchCaptcha(string $baseUrl): array
    {
        $ch = curl_init($baseUrl . '/auth/captcha');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $body = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($body, true);

        // Solve the math expression (e.g. "3 + 4 = ?")
        $answer = $this->solveCaptcha($data['question']);
        return [
            'captcha_id'     => $data['challenge_id'],
            'captcha_answer' => (string)$answer,
        ];
    }

    private function solveCaptcha(string $question): int
    {
        // Parse "a op b = ?"
        if (preg_match('/^\s*(-?\d+)\s*([+\-*])\s*(-?\d+)/', $question, $m)) {
            $a = (int)$m[1]; $op = $m[2]; $b = (int)$m[3];
            return match ($op) {
                '+' => $a + $b,
                '-' => $a - $b,
                '*' => $a * $b,
            };
        }
        throw new \RuntimeException("Unparseable captcha question: {$question}");
    }
}
