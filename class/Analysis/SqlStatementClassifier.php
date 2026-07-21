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
        $index = 0;

        while ($index < $length) {
            $skipped = self::skipCommentOrSpace($sql, $index);
            if ($skipped === null) {
                return null;
            }
            if ($skipped) {
                continue;
            }

            if ($statementEnded) {
                return null;
            }

            $character = $sql[$index];
            $special = self::handleSpecialToken($sql, $index, $depth, $statementEnded, $character);
            if ($special === null) {
                return null;
            }
            if ($special) {
                continue;
            }

            if (preg_match('/[A-Za-z_][A-Za-z0-9_$]*/A', $sql, $matches, 0, $index) === 1) {
                if ($depth === 0) {
                    $tokens[] = strtoupper($matches[0]);
                }
                $index += strlen($matches[0]);

                continue;
            }

            ++$index;
        }

        return $depth === 0 ? $tokens : null;
    }

    private static function skipCommentOrSpace(string $sql, int &$index): ?bool
    {
        $character = $sql[$index];

        if (ctype_space($character)) {
            ++$index;

            return true;
        }

        if ($character === '#') {
            $index = self::skipLineComment($sql, $index + 1);

            return true;
        }

        if ($character === '-' && ($sql[$index + 1] ?? '') === '-'
            && (! isset($sql[$index + 2]) || ctype_space($sql[$index + 2]))) {
            $index = self::skipLineComment($sql, $index + 2);

            return true;
        }

        if ($character === '/' && ($sql[$index + 1] ?? '') === '*') {
            if (self::isExecutableBlockComment($sql, $index)) {
                return null;
            }

            $nextIndex = self::skipBlockComment($sql, $index + 2);
            if ($nextIndex < 0) {
                return null;
            }
            $index = $nextIndex;

            return true;
        }

        return false;
    }

    private static function isExecutableBlockComment(string $sql, int $index): bool
    {
        $marker = $sql[$index + 2] ?? '';

        return $marker === '!'
            || (strtoupper($marker) === 'M' && ($sql[$index + 3] ?? '') === '!');
    }

    private static function handleSpecialToken(
        string $sql,
        int &$index,
        int &$depth,
        bool &$statementEnded,
        string $character
    ): ?bool {
        if ($character === ';') {
            if ($depth !== 0) {
                return null;
            }
            $statementEnded = true;
            ++$index;

            return true;
        }

        if ($character === '(') {
            ++$depth;
            ++$index;

            return true;
        }

        if ($character === ')') {
            --$depth;
            if ($depth < 0) {
                return null;
            }
            ++$index;

            return true;
        }

        if (in_array($character, ["'", '"', '`'], true)) {
            $nextIndex = self::skipQuotedValue($sql, $index, $character);
            if ($nextIndex < 0) {
                return null;
            }
            $index = $nextIndex;

            return true;
        }

        return false;
    }

    private static function skipLineComment(string $sql, int $index): int
    {
        return $index + strcspn($sql, "\r\n", $index);
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
            if ($quote !== '`' && $sql[$index] === '\\') {
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
