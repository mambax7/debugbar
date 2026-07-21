<?php

declare(strict_types=1);

namespace XoopsModules\Debugbar\Tests\Unit;

use DebugBar\DataCollector\MessagesCollector;
use DebugBar\DataFormatter\JsonDataFormatter;
use DebugBar\JavascriptRenderer;
use DebugBar\StandardDebugBar;
use PHPUnit\Framework\TestCase;
use XoopsModules\Debugbar\DebugbarLogger;

final class XoopsLoggerStub
{
    public static function getInstance(): self
    {
        return new self();
    }

    public function addLogger(object $logger): void
    {
        // Intentionally ignored: this test double only satisfies logger registration.
    }
}

final class DebugbarLoggerTest extends TestCase
{
    public function testMessageContextsUseJsonFormatter(): void
    {
        if (! defined('XOOPS_ROOT_PATH')) {
            define('XOOPS_ROOT_PATH', dirname(__DIR__, 2));
        }
        if (! class_exists(\XoopsLogger::class, false)) {
            class_alias(XoopsLoggerStub::class, \XoopsLogger::class);
        }

        $logger = new DebugbarLogger();
        $logger->enable();

        $debugbar = $logger->getDebugbar();
        self::assertInstanceOf(StandardDebugBar::class, $debugbar);

        $renderer = $logger->getRenderer();
        self::assertNotFalse($renderer);
        $assets = $renderer->getAssets(JavascriptRenderer::RELATIVE_URL);
        self::assertContains('vardumper.js', array_map('basename', $assets['js']));
        self::assertContains('vardumper.css', array_map('basename', $assets['css']));

        foreach (['messages', 'Deprecated', 'Blocks', 'Extra', 'Queries', 'Cache', 'HTTP', 'Mail'] as $name) {
            $collector = $debugbar->getCollector($name);
            self::assertInstanceOf(MessagesCollector::class, $collector);
            self::assertInstanceOf(JsonDataFormatter::class, $collector->getDataFormatter());
        }

        $collector = $debugbar->getCollector('messages');
        self::assertInstanceOf(MessagesCollector::class, $collector);
        $collector->warning('Slow request', [
            'request' => ['method' => 'GET', 'uri' => '/modules/xcontact/'],
            'null_value' => null,
            'false_value' => false,
            'zero_value' => 0,
            'empty_value' => '',
        ]);

        $data = $collector->collect();
        $message = $data['messages'][0];
        self::assertNull($message['context']['request']);
        self::assertSame([
            'request' => ['method' => 'GET', 'uri' => '/modules/xcontact/'],
            'null_value' => null,
            'false_value' => false,
            'zero_value' => 0,
            'empty_value' => '',
        ], $message['context_json']);
        self::assertStringNotContainsString('<pre', (string) json_encode($message));
    }
}
