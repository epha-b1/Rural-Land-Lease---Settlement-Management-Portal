<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Offline risk content detection.
 * Checks message content against admin-managed keyword/pattern library.
 * Actions: warn (allow + warning), block (reject), flag (allow + flag for review).
 */
class RiskService
{
    /**
     * Check content against active risk rules.
     * Returns: ['action' => 'allow'|'warn'|'block'|'flag', 'warning' => string|null, 'matched_rule' => id|null]
     */
    public static function check(string $content): array
    {
        if (empty(trim($content))) {
            return ['action' => 'allow', 'warning' => null, 'matched_rule' => null];
        }

        $rules = Db::table('risk_rules')->where('active', 1)->select()->toArray();
        $contentLower = strtolower($content);

        foreach ($rules as $rule) {
            $matched = false;
            if ($rule['is_regex']) {
                $matched = @preg_match('/' . $rule['pattern'] . '/i', $content) === 1;
            } else {
                $matched = str_contains($contentLower, strtolower($rule['pattern']));
            }

            if ($matched) {
                $action = $rule['action'];
                $warning = ($action === 'warn')
                    ? 'Content contains sensitive terms (' . $rule['category'] . ')'
                    : null;

                return [
                    'action'       => $action,
                    'warning'      => $warning,
                    'matched_rule' => (int)$rule['id'],
                ];
            }
        }

        return ['action' => 'allow', 'warning' => null, 'matched_rule' => null];
    }
}
