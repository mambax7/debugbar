<?php

declare(strict_types=1);

namespace XoopsModules\Debugbar\Analysis;

defined('XOOPS_ROOT_PATH') || exit('Restricted access');

/** Compare request metrics with optional development budgets. */
final class BudgetChecker
{
    /**
     * @param array<string, int|float> $metrics
     * @param array<string, int|float> $budgets
     * @return array{flags: int, findings: list<string>}
     */
    public static function check(array $metrics, array $budgets): array
    {
        $findings = [];
        $flags = 0;
        $checks = [
            ['queries', 'budget_queries', 'queries', 1],
            ['query_ms', 'budget_query_ms', 'SQL time (ms)', 2],
            ['boot_ms', 'budget_boot_ms', 'boot time (ms)', 4],
            ['total_ms', 'budget_total_ms', 'request time (ms)', 8],
            ['memory_mb', 'budget_memory_mb', 'peak memory (MB)', 16],
            ['payload_kb', 'budget_payload_kb', 'payload (KB)', 32],
        ];
        foreach ($checks as [$metric, $budget, $label, $flag]) {
            $limit = (float) ($budgets[$budget] ?? 0);
            $value = (float) ($metrics[$metric] ?? 0);
            if ($limit > 0 && $value > $limit) {
                $flags |= $flag;
                $findings[] = sprintf('%s exceeded: %s > %s', $label, self::format($value), self::format($limit));
            }
        }
        $repeatLimit = QueryAnalyzer::normalizeRepeatThreshold((int) ($budgets['nplus1_threshold'] ?? 0));
        if ($repeatLimit > 0 && (int) ($metrics['worst_repeat'] ?? 0) >= $repeatLimit) {
            $flags |= 64;
            $findings[] = sprintf('Repeated query detected: %d executions', $metrics['worst_repeat']);
        }

        return ['flags' => $flags, 'findings' => $findings];
    }

    private static function format(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }

    /** @return list<string> */
    public static function decodeFlags(int $flags): array
    {
        $names = [1 => 'queries', 2 => 'sql', 4 => 'boot', 8 => 'request', 16 => 'memory', 32 => 'payload', 64 => 'n+1'];
        $result = [];
        foreach ($names as $flag => $name) {
            if (($flags & $flag) !== 0) {
                $result[] = $name;
            }
        }

        return $result;
    }
}
