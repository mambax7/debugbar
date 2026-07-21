<?php

declare(strict_types=1);

namespace XoopsModules\Debugbar\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XoopsModules\Debugbar\Analysis\LogCatalog;

if (! defined('XOOPS_ROOT_PATH')) {
    define('XOOPS_ROOT_PATH', dirname(__DIR__, 2));
}

final class LogCatalogTest extends TestCase
{
    public function testEmptyMonologDirectoryDoesNotHideLegacyLogOrResolveRootFiles(): void
    {
        $directory = sys_get_temp_dir() . '/debugbar-log-catalog-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory));
        $legacyFile = $directory . '/log.txt';
        self::assertNotFalse(file_put_contents($legacyFile, "legacy entry\n"));

        try {
            $catalog = new LogCatalog($directory, $legacyFile);
            $files = $catalog->listFiles();

            self::assertCount(1, $files);
            self::assertSame('legacy', $files[0]['source']);
            self::assertSame("legacy entry\n", $catalog->read('legacy'));
            self::assertNull($catalog->read('xoops.log'));
        } finally {
            if (is_file($legacyFile)) {
                self::assertTrue(unlink($legacyFile));
            }
            if (is_dir($directory)) {
                self::assertTrue(rmdir($directory));
            }
        }
    }
}
