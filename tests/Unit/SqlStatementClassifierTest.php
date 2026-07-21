<?php

declare(strict_types=1);

namespace XoopsModules\Debugbar\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XoopsModules\Debugbar\Analysis\SqlStatementClassifier;

if (! defined('XOOPS_ROOT_PATH')) {
    define('XOOPS_ROOT_PATH', dirname(__DIR__, 2));
}

final class SqlStatementClassifierTest extends TestCase
{
    public function testAcceptsReadOnlySelectStatements(): void
    {
        $statements = [
            'SELECT * FROM users',
            "SELECT 'DELETE FROM users' AS message",
            'SELECT update FROM audit_log',
            'WITH selected AS (SELECT 1 AS id) SELECT * FROM selected',
            'WITH RECURSIVE selected AS (SELECT 1 UNION ALL SELECT id + 1 FROM selected WHERE id < 3) SELECT * FROM selected',
            '/* INSERT is mentioned only in a comment */ SELECT 1',
            "SELECT 1; -- one statement with a terminal delimiter\n",
            'SELECT 1 FROM `a\\` WHERE 1',
            'SELECT `a\\` AS `col`, 1',
        ];

        foreach ($statements as $statement) {
            self::assertTrue(SqlStatementClassifier::isReadOnlySelect($statement), $statement);
        }
    }

    public function testRejectsWritableOrMalformedStatements(): void
    {
        $statements = [
            'UPDATE users SET active = 1',
            'WITH selected AS (SELECT 1) UPDATE users SET active = 1',
            'WITH selected AS (SELECT 1) DELETE FROM users',
            'WITH selected AS (SELECT 1) INSERT INTO audit_log SELECT * FROM selected',
            'WITH selected AS (SELECT 1) REPLACE INTO audit_log SELECT * FROM selected',
            "SELECT * FROM users INTO OUTFILE '/tmp/users.txt'",
            "SELECT * FROM users INTO DUMPFILE '/tmp/users.bin'",
            "SELECT * FROM users /*! INTO OUTFILE '/tmp/users.txt' */",
            "SELECT * FROM users /*!80000 INTO OUTFILE '/tmp/users.txt' */",
            "SELECT * FROM users /*M! INTO OUTFILE '/tmp/users.txt' */",
            'SELECT 1; DELETE FROM users',
            'SELECT 1 FROM `x\\`; DELETE FROM users',
            "SELECT 'unterminated",
            'SELECT (1',
            '/* unterminated SELECT 1',
            '',
        ];

        foreach ($statements as $statement) {
            self::assertFalse(SqlStatementClassifier::isReadOnlySelect($statement), $statement);
        }
    }
}
