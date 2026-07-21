<?php

declare(strict_types=1);

namespace XoopsModules\Debugbar\Tests\Unit;

use DebugBar\DataCollector\MessagesCollector;
use DebugBar\DataFormatter\JsonDataFormatter;
use DebugBar\StandardDebugBar;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use XoopsModules\Debugbar\DebugbarLogger;

final class DebugbarLoggerTest extends TestCase
{
    public function testMessageContextsUseJsonFormatter(): void
    {
        if (! defined('XOOPS_ROOT_PATH')) {
            define('XOOPS_ROOT_PATH', dirname(__DIR__, 3));
        }

        $reflection = new ReflectionClass(DebugbarLogger::class);
        /** @var DebugbarLogger $logger */
        $logger = $reflection->newInstanceWithoutConstructor();
        $logger->enable();

        $debugbar = $logger->getDebugbar();
        self::assertInstanceOf(StandardDebugBar::class, $debugbar);

        $renderer = $logger->getRenderer();
        self::assertNotFalse($renderer);
        $assets = $renderer->getAssets(null);
        self::assertContains('vardumper.js', $assets['js']);
        self::assertContains('vardumper.css', $assets['css']);

        foreach (['messages', 'Deprecated', 'Blocks', 'Extra', 'Queries', 'Cache', 'HTTP', 'Mail'] as $name) {
            $collector = $debugbar->getCollector($name);
            self::assertInstanceOf(MessagesCollector::class, $collector);
            self::assertInstanceOf(JsonDataFormatter::class, $collector->getDataFormatter());
        }

        $collector = $debugbar->getCollector('messages');
        self::assertInstanceOf(MessagesCollector::class, $collector);
        $collector->warning('Slow request', [
            'request' => ['method' => 'GET', 'uri' => '/modules/xcontact/'],
        ]);

        $data = $collector->collect();
        $message = $data['messages'][0];
        self::assertNull($message['context']['request']);
        self::assertSame(
            ['method' => 'GET', 'uri' => '/modules/xcontact/'],
            $message['context_json']['request']
        );
        self::assertStringNotContainsString('<pre', (string) json_encode($message));
    }
}
