<?php

declare(strict_types=1);

namespace XoopsModules\Debugbar\Analysis;

defined('XOOPS_ROOT_PATH') || exit('Restricted access');

/** Classifies the limited read-only SQL forms accepted by the EXPLAIN endpoint. */
final class SqlStatementClassifier
{
    private const MAIN_STATEMENTS = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE'];

    public static function isReadOnlySelect(string $sql): bool
    {
        $tokens = self::topLevelTokens(trim($sql));
        if ($tokens === null || $tokens === []) {
            return false;
        }

        $first = $tokens[0];
        if ($first === 'SELECT') {
            return ! self::hasFileOutputClause($tokens);
        }
        if ($first !== 'WITH') {
            return false;
        }

        foreach (array_slice($tokens, 1) as $token) {
            if (! in_array($token, self::MAIN_STATEMENTS, true)) {
                continue;
            }

            return $token === 'SELECT' && ! self::hasFileOutputClause($tokens);
        }

        return false;
    }

    /** @param list<string> $tokens */
    private static function hasFileOutputClause(array $tokens): bool
    {
        $count = count($tokens);
        for ($index = 0; $index + 1 < $count; ++$index) {
            if ($tokens[$index] === 'INTO' && in_array($tokens[$index + 1], ['OUTFILE', 'DUMPFILE'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return SQL keywords visible at parenthesis depth zero.
     *
     * Strings, quoted identifiers, and comments are skipped so words such as
     * "update" inside diagnostic data cannot change the classification.
     *
     * @return list<string>|null Null denotes malformed or stacked SQL.
     */
    private static function topLevelTokens(string $sql): ?array
    {
        $tokens = [];
        $length = strlen($sql);
        $depth = 0;
        $statementEnded = false;

        for ($index = 0; $index < $length;) {
            $character = $sql[$index];

            if (ctype_space($character)) {
                ++$index;

                continue;
            }

            if ($character === '#') {
                $index = self::skipLineComment($sql, $index + 1);

                continue;
            }
            if ($character === '-' && ($sql[$index + 1] ?? '') === '-'
                && (! isset($sql[$index + 2]) || ctype_space($sql[$index + 2]))) {
                $index = self::skipLineComment($sql, $index + 2);

                continue;
            }
            if ($character === '/' && ($sql[$index + 1] ?? '') === '*') {
                $index = self::skipBlockComment($sql, $index + 2);
                if ($index < 0) {
                    return null;
                }

                continue;
            }

            if ($statementEnded) {
                return null;
            }

            if (in_array($character, ["'", '"', '`'], true)) {
                $index = self::skipQuotedValue($sql, $index, $character);
                if ($index < 0) {
                    return null;
                }

                continue;
            }

            if ($character === ';') {
                if ($depth !== 0) {
                    return null;
                }
                $statementEnded = true;
                ++$index;

                continue;
            }

            if ($character === '(') {
                ++$depth;
                ++$index;

                continue;
            }
            if ($character === ')') {
                --$depth;
                if ($depth < 0) {
                    return null;
                }
                ++$index;

                continue;
            }

            if ($depth === 0 && preg_match('/[A-Za-z_]/A', substr($sql, $index, 1)) === 1) {
                $start = $index;
                while ($index < $length
                    && preg_match('/[A-Za-z0-9_$]/A', substr($sql, $index, 1)) === 1) {
                    ++$index;
                }
                $tokens[] = strtoupper(substr($sql, $start, $index - $start));

                continue;
            }

            ++$index;
        }

        return $depth === 0 ? $tokens : null;
    }

    private static function skipLineComment(string $sql, int $index): int
    {
        $length = strlen($sql);
        while ($index < $length && ! in_array($sql[$index], ["\r", "\n"], true)) {
            ++$index;
        }

        return $index;
    }

    private static function skipBlockComment(string $sql, int $index): int
    {
        $end = strpos($sql, '*/', $index);

        return $end === false ? -1 : $end + 2;
    }

    private static function skipQuotedValue(string $sql, int $index, string $quote): int
    {
        $length = strlen($sql);
        for (++$index; $index < $length; ++$index) {
            if ($sql[$index] === '\\') {
                ++$index;

                continue;
            }
            if ($sql[$index] !== $quote) {
                continue;
            }
            if (($sql[$index + 1] ?? '') === $quote) {
                ++$index;

                continue;
            }

            return $index + 1;
        }

        return -1;
    }
}
