<?php

declare(strict_types=1);

namespace XoopsModules\Debugbar\Analysis;

defined('XOOPS_ROOT_PATH') || exit('Restricted access');

/** Analyse the compact query log without touching the database. */
final class QueryAnalyzer
{
    /**
     * @param array<int, array<string, mixed>> $queries
     * @return array{
     *     count: int,
     *     total_ms: float,
     *     error_count: int,
     *     slowest_ms: float,
     *     slowest_fp: string,
     *     worst_repeat: int,
     *     duplicates: list<array{sql: string, count: int}>,
     *     n_plus_one: list<array{sql: string, count: int}>,
     *     slow: list<array{sql: string, ms: float}>
     * }
     */
    public static function analyze(array $queries, float $slowThreshold, int $repeatThreshold = 5): array
    {
        $repeatThreshold = self::normalizeRepeatThreshold($repeatThreshold);
        $fingerprints = [];
        $duplicates = [];
        $slow = [];
        $total = 0.0;
        $errors = 0;
        $count = 0;
        foreach ($queries as $query) {
            $sql = trim((string) ($query['sql'] ?? ''));
            $ms = (float) ($query['ms'] ?? 0.0);
            if ($sql === '') {
                continue;
            }
            $count++;
            $total += $ms;
            $fingerprint = self::fingerprint($sql);
            $fingerprints[$fingerprint] = ($fingerprints[$fingerprint] ?? 0) + 1;
            $duplicates[$fingerprint] = ['sql' => $sql, 'count' => $fingerprints[$fingerprint]];
            if (self::hasError($query['error'] ?? null)) {
                $errors++;
            }
            if ($ms >= $slowThreshold * 1000.0) {
                $slow[] = ['sql' => $sql, 'ms' => $ms];
            }
        }
        uasort($duplicates, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        usort($slow, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);
        $nPlusOne = $repeatThreshold === 0
            ? []
            : array_filter($duplicates, static fn (array $row): bool => $row['count'] >= $repeatThreshold);

        return [
            'count' => $count,
            'total_ms' => round($total, 3),
            'error_count' => $errors,
            'slowest_ms' => $slow[0]['ms'] ?? 0.0,
            'slowest_fp' => isset($slow[0]) ? self::fingerprint($slow[0]['sql']) : '',
            'worst_repeat' => $nPlusOne === [] ? 0 : max(array_column($nPlusOne, 'count')),
            'duplicates' => array_values(array_filter($duplicates, static fn (array $row): bool => $row['count'] > 1)),
            'n_plus_one' => array_values($nPlusOne),
            'slow' => $slow,
        ];
    }

    public static function normalizeRepeatThreshold(int $threshold): int
    {
        if ($threshold <= 0) {
            return 0;
        }

        return max(2, $threshold);
    }

    private static function hasError(mixed $error): bool
    {
        return ! in_array($error, [null, false, 0, 0.0, '', '0', []], true);
    }

    public static function fingerprint(string $sql): string
    {
        $sql = preg_replace("/'(?:''|[^'])*'/", '?', $sql) ?? $sql;
        $sql = preg_replace('/"(?:""|[^"])*"/', '?', $sql) ?? $sql;
        $sql = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $sql) ?? $sql;
        $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;
        $sql = preg_replace('/\s*([=<>])\s*/', '$1', $sql) ?? $sql;

        return hash('sha256', strtolower($sql));
    }
}
